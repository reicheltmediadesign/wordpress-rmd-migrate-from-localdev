<?php
/**
 * Global settings (export directory) and profile storage.
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev;

use RMD\MigrateFromLocaldev\Domain\Profile;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION          = 'rmd_mfl_settings';
	public const PROFILES_OPTION = 'rmd_mfl_profiles';

	/** Every option of this plugin starts with this; they are left out of the dump. */
	public const OPTION_PREFIX = 'rmd_mfl_';

	public static function default_export_dir(): string {
		return wp_normalize_path( WP_CONTENT_DIR ) . '/rmd-migrate-exports';
	}

	public static function export_dir(): string {
		$settings = get_option( self::OPTION, [] );
		$dir      = is_array( $settings ) && isset( $settings['export_dir'] ) && is_string( $settings['export_dir'] ) ? $settings['export_dir'] : '';

		return '' === trim( $dir ) ? self::default_export_dir() : untrailingslashit( wp_normalize_path( $dir ) );
	}

	/**
	 * @return string|null Error message, or null when saved.
	 */
	public static function save_export_dir( string $dir ): ?string {
		$dir = trim( $dir );
		if ( '' !== $dir && ! path_is_absolute( $dir ) ) {
			return __( 'The export folder must be an absolute path.', 'rmd-migrate-from-localdev' );
		}
		$dir = '' === $dir ? '' : untrailingslashit( wp_normalize_path( $dir ) );
		if ( '' !== $dir && ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) ) {
			return __( 'The export folder could not be created or is not writable.', 'rmd-migrate-from-localdev' );
		}
		update_option( self::OPTION, [ 'export_dir' => $dir ], false );
		return null;
	}

	/**
	 * @return array<string, Profile>
	 */
	public static function profiles(): array {
		$stored   = get_option( self::PROFILES_OPTION, [] );
		$profiles = [];
		foreach ( is_array( $stored ) ? $stored : [] as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$profile = Profile::parse( $raw )['profile'];
			if ( $profile->is_complete() ) {
				$profiles[ $profile->id ] = $profile;
			}
		}
		uasort( $profiles, static fn( Profile $a, Profile $b ): int => strcasecmp( $a->name, $b->name ) );
		return $profiles;
	}

	public static function profile( string $id ): ?Profile {
		return self::profiles()[ $id ] ?? null;
	}

	/**
	 * Saves a profile. A new profile gets a unique id derived from its name.
	 */
	public static function save_profile( Profile $profile, bool $is_new ): Profile {
		$profiles = self::profiles();

		if ( $is_new ) {
			$base = Profile::slug( $profile->name );
			$id   = $base;
			for ( $i = 2; isset( $profiles[ $id ] ); $i++ ) {
				$id = $base . '-' . $i;
			}
			$profile = Profile::parse( [ 'id' => $id ] + $profile->to_array() )['profile'];
		}

		$profiles[ $profile->id ] = $profile;
		update_option( self::PROFILES_OPTION, array_map( static fn( Profile $p ): array => $p->to_array(), $profiles ), false );
		return $profile;
	}

	public static function delete_profile( string $id ): void {
		$profiles = self::profiles();
		unset( $profiles[ $id ] );
		update_option( self::PROFILES_OPTION, array_map( static fn( Profile $p ): array => $p->to_array(), $profiles ), false );
	}
}
