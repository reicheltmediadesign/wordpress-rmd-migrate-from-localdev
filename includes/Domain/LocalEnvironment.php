<?php
/**
 * Decides whether the current site is a local development install. The export
 * contains the whole database including password hashes, so it is refused on
 * anything that looks like a public site.
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev\Domain;

final class LocalEnvironment {

	private const LOCAL_SUFFIXES = [ '.localhost', '.local', '.test', '.lan', '.internal' ];

	public static function is_local( string $host, string $environment_type ): bool {
		if ( 'local' === $environment_type ) {
			return true;
		}

		$host = strtolower( trim( $host, '[]' ) );
		if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
			return true;
		}
		foreach ( self::LOCAL_SUFFIXES as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		return false;
	}
}
