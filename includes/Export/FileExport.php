<?php
/**
 * Copies the WordPress installation into the export's files/ folder in two
 * resumable passes: scan (writes a manifest) and copy (works through it).
 *
 * Folders that are symlinks or junctions (e.g. a plugin repository linked into
 * wp-content/plugins) get special treatment: if the repository contains a built
 * release zip (dist/<folder>.zip) that zip is unpacked, otherwise the folder is
 * copied with the rules of its .distignore file.
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev\Export;

use RMD\MigrateFromLocaldev\Domain\PathFilter;
use RMD\MigrateFromLocaldev\Domain\Profile;
use RuntimeException;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

// Copying thousands of files between local folders; WP_Filesystem adds nothing here.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class FileExport {

	/**
	 * @param array<string, mixed> $job
	 */
	public static function scan( array &$job, Profile $profile, float $deadline ): void {
		$scan     = &$job['scan'];
		$root     = (string) $job['source']['abspath'];
		$filter   = PathFilter::from_lines( $profile->exclude_patterns );
		$excluded = self::hard_excludes( $job );
		$handle   = Exports::open_append( $job['dir'] . '/' . Job::MANIFEST_FILE, (int) $scan['bytes'] );

		try {
			while ( [] !== $scan['stack'] && microtime( true ) < $deadline ) {
				$dir     = (string) array_pop( $scan['stack'] );
				$abs_dir = '' === $dir ? $root : $root . '/' . $dir;
				$entries = scandir( $abs_dir );
				if ( false === $entries ) {
					Job::warn(
						$job,
						sprintf(
							/* translators: %s: folder path */
							__( 'Folder could not be read and was skipped: %s', 'rmd-migrate-from-localdev' ),
							$abs_dir
						)
					);
					continue;
				}

				foreach ( $entries as $name ) {
					if ( '.' === $name || '..' === $name ) {
						continue;
					}
					$rel    = '' === $dir ? $name : $dir . '/' . $name;
					$abs    = $abs_dir . '/' . $name;
					$is_dir = is_dir( $abs );

					if ( in_array( strtolower( $abs ), $excluded, true ) || $filter->matches( $rel, $is_dir ) || self::excluded_by_link( $scan['links'], $rel, $is_dir ) ) {
						continue;
					}

					if ( ! $is_dir ) {
						$size = (int) filesize( $abs );
						self::write( $handle, "F\t" . $rel . "\n" );
						++$scan['files'];
						$scan['size'] += $size;
						continue;
					}

					if ( self::is_linked( $abs ) ) {
						$target = wp_normalize_path( (string) realpath( $abs ) );
						$zip    = $target . '/dist/' . $name . '.zip';
						if ( is_file( $zip ) && class_exists( ZipArchive::class ) ) {
							self::write( $handle, "Z\t" . $rel . "\t" . $zip . "\n" );
							$job['report']['links'][ $rel ] = [
								'target' => $target,
								'mode'   => 'zip',
								'zip'    => $zip,
								'date'   => (int) filemtime( $zip ),
							];
							continue;
						}
						$rules                          = is_readable( $target . '/.distignore' ) ? (string) file_get_contents( $target . '/.distignore' ) : '';
						$scan['links'][ $rel ]          = $rules;
						$job['report']['links'][ $rel ] = [
							'target' => $target,
							'mode'   => '' === $rules ? 'copy' : 'distignore',
						];
					}

					self::write( $handle, "D\t" . $rel . "\n" );
					$scan['stack'][] = $rel;
				}
				$scan['bytes'] = (int) ftell( $handle );
			}
			$scan['bytes'] = (int) ftell( $handle );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Copies manifest entries until the time is up. Returns true when all are done.
	 *
	 * @param array<string, mixed> $job
	 */
	public static function copy( array &$job, float $deadline ): bool {
		$copy   = &$job['copy'];
		$root   = (string) $job['source']['abspath'];
		$dest   = $job['dir'] . '/' . Job::FILES_DIR;
		$handle = fopen( $job['dir'] . '/' . Job::MANIFEST_FILE, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( esc_html__( 'The file list of this export is missing.', 'rmd-migrate-from-localdev' ) );
		}
		wp_mkdir_p( $dest );

		try {
			fseek( $handle, (int) $copy['offset'] );
			while ( microtime( true ) < $deadline ) {
				$line = fgets( $handle );
				if ( false === $line ) {
					return true;
				}
				$parts = explode( "\t", rtrim( $line, "\n" ) );
				$type  = $parts[0];
				$rel   = $parts[1] ?? '';

				if ( 'D' === $type ) {
					wp_mkdir_p( $dest . '/' . $rel );
				} elseif ( 'F' === $type ) {
					self::copy_file( $job, $root . '/' . $rel, $dest . '/' . $rel );
				} elseif ( 'Z' === $type ) {
					self::unzip( $job, (string) ( $parts[2] ?? '' ), $rel, $dest );
				}
				$copy['offset'] = (int) ftell( $handle );
			}
			return false;
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private static function copy_file( array &$job, string $source, string $target ): void {
		$parent = dirname( $target );
		if ( ! is_dir( $parent ) ) {
			wp_mkdir_p( $parent );
		}
		if ( ! copy( $source, $target ) ) {
			++$job['report']['failed'];
			Job::warn(
				$job,
				sprintf(
					/* translators: %s: file path */
					__( 'File could not be copied: %s', 'rmd-migrate-from-localdev' ),
					$source
				)
			);
			return;
		}
		$mtime = filemtime( $source );
		if ( false !== $mtime ) {
			touch( $target, $mtime );
		}
		++$job['copy']['files'];
		$job['copy']['bytes'] += (int) filesize( $target );
	}

	/**
	 * Unpacks a plugin release zip (one top-level folder named like the link) in place of the linked folder.
	 *
	 * @param array<string, mixed> $job
	 */
	private static function unzip( array &$job, string $zip_path, string $rel, string $dest ): void {
		$name = basename( $rel );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			Job::warn(
				$job,
				sprintf(
					/* translators: %s: zip file path */
					__( 'Release zip could not be opened: %s', 'rmd-migrate-from-localdev' ),
					$zip_path
				)
			);
			return;
		}

		try {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
				$entry = (string) $zip->getNameIndex( $i );
				if ( ! str_starts_with( $entry, $name . '/' ) || str_contains( $entry, '..' ) ) {
					Job::warn(
						$job,
						sprintf(
							/* translators: 1: zip file path, 2: expected folder name */
							__( 'Release zip %1$s does not contain a single folder named %2$s and was skipped.', 'rmd-migrate-from-localdev' ),
							$zip_path,
							$name
						)
					);
					return;
				}
			}

			$parent = dirname( $rel );
			$into   = '.' === $parent ? $dest : $dest . '/' . $parent;
			wp_mkdir_p( $into );
			if ( ! $zip->extractTo( $into ) ) {
				++$job['report']['failed'];
				Job::warn(
					$job,
					sprintf(
						/* translators: %s: zip file path */
						__( 'Release zip could not be unpacked: %s', 'rmd-migrate-from-localdev' ),
						$zip_path
					)
				);
				return;
			}
			$job['copy']['files'] += $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
		} finally {
			$zip->close();
		}
	}

	/**
	 * Paths that are never copied: wp-config.php (credentials; a template is
	 * written instead), .htaccess (rewritten for the target), the export folder
	 * and this plugin.
	 *
	 * @param array<string, mixed> $job
	 * @return list<string> Lower-case absolute paths.
	 */
	private static function hard_excludes( array $job ): array {
		$root = (string) $job['source']['abspath'];
		return array_map(
			'strtolower',
			[
				$root . '/wp-config.php',
				$root . '/.htaccess',
				untrailingslashit( wp_normalize_path( (string) $job['dir'] ) ),
				dirname( untrailingslashit( wp_normalize_path( (string) $job['dir'] ) ) ),
				untrailingslashit( wp_normalize_path( RMD_MFL_DIR ) ),
				wp_normalize_path( WP_PLUGIN_DIR ) . '/' . dirname( RMD_MFL_BASENAME ),
			]
		);
	}

	/**
	 * @param array<string, string> $links Linked folder => its .distignore rules.
	 */
	private static function excluded_by_link( array $links, string $rel, bool $is_dir ): bool {
		static $filters = [];

		foreach ( $links as $link => $rules ) {
			if ( '' === $rules || ! str_starts_with( $rel, $link . '/' ) ) {
				continue;
			}
			$filters[ $link ] ??= PathFilter::from_lines( $rules );
			if ( $filters[ $link ]->matches( substr( $rel, strlen( $link ) + 1 ), $is_dir ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Symlinks and Windows junctions: the real path differs from the path below the real parent.
	 */
	private static function is_linked( string $path ): bool {
		if ( is_link( $path ) ) {
			return true;
		}
		$real        = realpath( $path );
		$real_parent = realpath( dirname( $path ) );
		if ( false === $real || false === $real_parent ) {
			return false;
		}
		return strtolower( wp_normalize_path( $real ) ) !== strtolower( wp_normalize_path( $real_parent ) . '/' . basename( $path ) );
	}

	/**
	 * @param resource $handle
	 */
	private static function write( $handle, string $data ): void {
		if ( false === fwrite( $handle, $data ) ) {
			throw new RuntimeException( esc_html__( 'Writing the file list failed. Is the disk full?', 'rmd-migrate-from-localdev' ) );
		}
	}
}
