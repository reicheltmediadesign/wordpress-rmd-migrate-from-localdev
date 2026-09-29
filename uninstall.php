<?php
/**
 * Removes the plugin's options. Export folders are kept; delete them in the
 * file system if they are no longer needed.
 *
 * @package RMD\MigrateFromLocaldev
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'rmd_mfl_settings' );
delete_option( 'rmd_mfl_profiles' );
delete_option( 'rmd_mfl_job' );
delete_option( 'external_updates-rmd-migrate-from-localdev' );
delete_transient( 'rmd_mfl_lock' );
