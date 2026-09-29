<?php
/**
 * Last export step: compresses the dump, writes .htaccess and a wp-config.php
 * template for the target and the MIGRATION.txt report.
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev\Export;

use RMD\MigrateFromLocaldev\Domain\Htaccess;
use RMD\MigrateFromLocaldev\Domain\Profile;
use RMD\MigrateFromLocaldev\Domain\ReplacementPlan;
use RMD\MigrateFromLocaldev\Domain\WpConfigTemplate;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// Local files only; see Exports.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class Finisher {

	/**
	 * @param array<string, mixed> $job
	 */
	public static function run( array &$job, Profile $profile, ReplacementPlan $plan ): void {
		$dir  = (string) $job['dir'];
		$root = (string) $job['source']['abspath'];

		if ( $profile->gzip ) {
			self::gzip( $dir . '/' . Job::SQL_FILE );
		}

		$htaccess = $root . '/.htaccess';
		if ( $profile->include_files && is_readable( $htaccess ) ) {
			$content = Htaccess::for_target( (string) file_get_contents( $htaccess ), ReplacementPlan::url_path( $profile->target_url ) );
			self::put( $dir . '/' . Job::FILES_DIR . '/.htaccess', $content );
		}

		$config = self::find_wp_config( $root );
		if ( null !== $config ) {
			$salts = [];
			foreach ( WpConfigTemplate::SALT_KEYS as $key ) {
				$salts[ $key ] = wp_generate_password( 64, true, true );
			}
			$template = WpConfigTemplate::render( (string) file_get_contents( $config ), $profile->table_prefix, $profile->environment_type, $salts );
			self::put( $dir . '/' . Job::CONFIG_FILE, strtr( $template, $plan->pairs ) );
		}

		$manifest = $dir . '/' . Job::MANIFEST_FILE;
		if ( file_exists( $manifest ) ) {
			wp_delete_file( $manifest );
		}

		$job['finished'] = time();
		self::put( $dir . '/' . Job::REPORT_FILE, Report::render( $job, $profile, $plan ) );
	}

	private static function gzip( string $path ): void {
		$source = fopen( $path, 'rb' );
		$target = gzopen( $path . '.gz', 'wb6' );
		if ( false === $source || false === $target ) {
			throw new RuntimeException( esc_html__( 'The database dump could not be compressed.', 'rmd-migrate-from-localdev' ) );
		}
		try {
			while ( ! feof( $source ) ) {
				$chunk = fread( $source, 1024 * 1024 );
				if ( false === $chunk || ( '' !== $chunk && false === gzwrite( $target, $chunk ) ) ) {
					throw new RuntimeException( esc_html__( 'The database dump could not be compressed.', 'rmd-migrate-from-localdev' ) );
				}
			}
		} finally {
			fclose( $source );
			gzclose( $target );
		}
		wp_delete_file( $path );
	}

	/**
	 * WordPress also finds wp-config.php one level above ABSPATH.
	 */
	private static function find_wp_config( string $root ): ?string {
		if ( is_readable( $root . '/wp-config.php' ) ) {
			return $root . '/wp-config.php';
		}
		$above = dirname( $root ) . '/wp-config.php';
		return is_readable( $above ) && ! file_exists( dirname( $root ) . '/wp-settings.php' ) ? $above : null;
	}

	private static function put( string $path, string $content ): void {
		wp_mkdir_p( dirname( $path ) );
		if ( false === file_put_contents( $path, $content ) ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: file path */
						__( 'Could not write %s.', 'rmd-migrate-from-localdev' ),
						$path
					)
				)
			);
		}
	}
}
