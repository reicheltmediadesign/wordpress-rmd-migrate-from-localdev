<?php
/**
 * Update checks. Instead of the GitHub REST API (60 unauthenticated requests
 * per hour and IP) the checker reads a small update.json that the release
 * workflow attaches to every release. "releases/latest/download/…" is a plain
 * web redirect and not rate limited.
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev;

defined( 'ABSPATH' ) || exit;

final class Updater {

	public const REPOSITORY   = 'https://github.com/reicheltmediadesign/wordpress-rmd-migrate-from-localdev/';
	public const METADATA_URL = self::REPOSITORY . 'releases/latest/download/update.json';
	public const SLUG         = 'rmd-migrate-from-localdev';

	public static function init(): void {
		$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return;
		}

		$factory::buildUpdateChecker( self::METADATA_URL, RMD_MFL_FILE, self::SLUG );
	}
}
