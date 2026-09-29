<?php
/**
 * Plugin bootstrap: wires all services to WordPress hooks.
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev;

use RMD\MigrateFromLocaldev\Domain\LocalEnvironment;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Everything the plugin does needs full access to the site's data and files. */
	public const CAP = 'manage_options';

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', [ self::class, 'load_textdomain' ], 1 );

		Updater::init();
		Rest\ExportController::init();

		if ( is_admin() ) {
			Admin\Page::init();
		}
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'rmd-migrate-from-localdev', false, dirname( RMD_MFL_BASENAME ) . '/languages' );
	}

	/**
	 * Exports are only allowed on local development sites. The filter
	 * rmd_mfl_is_local_environment can override the detection.
	 */
	public static function is_local(): bool {
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$local = LocalEnvironment::is_local( $host, wp_get_environment_type() );

		return (bool) apply_filters( 'rmd_mfl_is_local_environment', $local, $host );
	}
}
