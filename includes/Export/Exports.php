<?php
/**
 * The export folder: one subfolder per export, protected against web access.
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev\Export;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RMD\MigrateFromLocaldev\Settings;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// The exports are written to a local folder chosen by the site owner, outside
// the uploads API, and can be several gigabytes; WP_Filesystem is not suited.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class Exports {

	/**
	 * Creates the export folder with deny rules and returns its path.
	 */
	public static function prepare_root(): string {
		$root = Settings::export_dir();
		if ( ! wp_mkdir_p( $root ) || ! wp_is_writable( $root ) ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: folder path */
						__( 'The export folder %s could not be created or is not writable.', 'rmd-migrate-from-localdev' ),
						$root
					)
				)
			);
		}
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			file_put_contents( $root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $root . '/index.php' ) ) {
			file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $root;
	}

	/**
	 * Finished and unfinished exports, newest first.
	 *
	 * @return list<array{name: string, path: string, created: int, complete: bool, database: int}>
	 */
	public static function all(): array {
		$root = Settings::export_dir();
		if ( ! is_dir( $root ) ) {
			return [];
		}

		$exports = [];
		foreach ( (array) scandir( $root ) as $name ) {
			$name = (string) $name;
			$path = $root . '/' . $name;
			if ( str_starts_with( $name, '.' ) || ! is_dir( $path ) ) {
				continue;
			}
			$dump      = file_exists( $path . '/' . Job::SQL_FILE . '.gz' ) ? $path . '/' . Job::SQL_FILE . '.gz' : $path . '/' . Job::SQL_FILE;
			$exports[] = [
				'name'     => $name,
				'path'     => $path,
				'created'  => (int) filemtime( $path ),
				'complete' => file_exists( $path . '/' . Job::REPORT_FILE ),
				'database' => file_exists( $dump ) ? (int) filesize( $dump ) : 0,
			];
		}

		usort( $exports, static fn( array $a, array $b ): int => strcmp( $b['name'], $a['name'] ) );
		return $exports;
	}

	/**
	 * Deletes one export folder. Only direct subfolders of the export folder are accepted.
	 */
	public static function delete( string $name ): void {
		$root = Settings::export_dir();
		if ( '' === $name || str_starts_with( $name, '.' ) || basename( $name ) !== $name ) {
			throw new RuntimeException( esc_html__( 'Invalid export name.', 'rmd-migrate-from-localdev' ) );
		}
		$path = $root . '/' . $name;
		if ( ! is_dir( $path ) ) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			/** @var \SplFileInfo $item */
			$ok = $item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
			if ( ! $ok ) {
				throw new RuntimeException(
					esc_html(
						sprintf(
							/* translators: %s: file path */
							__( 'Could not delete %s.', 'rmd-migrate-from-localdev' ),
							$item->getPathname()
						)
					)
				);
			}
		}
		rmdir( $path );
	}

	/**
	 * Opens a file for appending after cutting it to the length recorded in the
	 * job state, so output of an interrupted step is never written twice.
	 *
	 * @return resource
	 */
	public static function open_append( string $path, int $length ) {
		$handle = fopen( $path, 'c+b' );
		if ( false === $handle ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: file path */
						__( 'Could not open %s for writing.', 'rmd-migrate-from-localdev' ),
						$path
					)
				)
			);
		}
		ftruncate( $handle, $length );
		fseek( $handle, 0, SEEK_END );
		return $handle;
	}
}
