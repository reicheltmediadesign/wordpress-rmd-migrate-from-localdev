<?php
/**
 * Plugin Name:       RMD Migrate from Localdev
 * Plugin URI:        https://github.com/reicheltmediadesign/wordpress-rmd-migrate-from-localdev
 * Description:       Prepares a local development site for upload: a database dump with serialization-safe search and replace and a copy of all files, ready for SFTP and phpMyAdmin.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Philipp Reichelt, reichelt media.design
 * Author URI:        https://reicheltmedia.design
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rmd-migrate-from-localdev
 * Domain Path:       /languages
 * Update URI:        https://github.com/reicheltmediadesign/wordpress-rmd-migrate-from-localdev
 *
 * @package RMD\MigrateFromLocaldev
 */

// This file must stay parseable by PHP 7.x so the version notice below can be shown instead of a fatal error.

defined( 'ABSPATH' ) || exit;

define( 'RMD_MFL_VERSION', '0.1.0' );
define( 'RMD_MFL_FILE', __FILE__ );
define( 'RMD_MFL_DIR', plugin_dir_path( __FILE__ ) );
define( 'RMD_MFL_URL', plugin_dir_url( __FILE__ ) );
define( 'RMD_MFL_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'RMD Migrate from Localdev requires PHP %1$s or newer. This site runs PHP %2$s, so the plugin stays inactive.', 'rmd-migrate-from-localdev' ),
					'8.1',
					PHP_VERSION
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'RMD\\MigrateFromLocaldev\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = RMD_MFL_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

if ( is_readable( RMD_MFL_DIR . 'vendor/autoload.php' ) ) {
	require RMD_MFL_DIR . 'vendor/autoload.php';
}

RMD\MigrateFromLocaldev\Plugin::boot();
