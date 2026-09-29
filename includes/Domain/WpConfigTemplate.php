<?php
/**
 * Turns the local wp-config.php into a template for the target server: database
 * credentials become placeholders, salts are renewed, debugging is switched off
 * and table prefix and environment type are set for the target.
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev\Domain;

final class WpConfigTemplate {

	public const PLACEHOLDERS = [
		'DB_NAME'     => 'ENTER_DATABASE_NAME',
		'DB_USER'     => 'ENTER_DATABASE_USER',
		'DB_PASSWORD' => 'ENTER_DATABASE_PASSWORD',
		'DB_HOST'     => 'ENTER_DATABASE_HOST',
	];

	public const SALT_KEYS = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ];

	/** A quoted PHP string literal, honouring escaped quotes. */
	private const STRING_VALUE = '(?<q>[\'"])(?:\\\\.|(?!\k<q>).)*\k<q>';

	private const DEBUG_CONSTANTS = [ 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES' ];

	/**
	 * @param array<string, string> $salts Salt constant => new value.
	 */
	public static function render( string $source, string $table_prefix, string $environment_type, array $salts ): string {
		$config = $source;

		foreach ( self::PLACEHOLDERS as $constant => $placeholder ) {
			$config = self::set_string( $config, $constant, $placeholder );
		}
		foreach ( $salts as $constant => $value ) {
			$config = self::set_string( $config, $constant, $value );
		}
		foreach ( self::DEBUG_CONSTANTS as $constant ) {
			$config = (string) preg_replace( self::define_pattern( $constant, 'true' ), '${1}false${2}', $config );
		}

		if ( '' !== $table_prefix ) {
			$config = (string) preg_replace_callback(
				'/^(\s*\$table_prefix\s*=\s*)([\'"]).*?\2(\s*;)/m',
				static fn( array $m ): string => $m[1] . "'" . $table_prefix . "'" . $m[3],
				$config
			);
		}

		if ( preg_match( self::define_pattern( 'WP_ENVIRONMENT_TYPE', self::STRING_VALUE ), $config ) ) {
			$config = self::set_string( $config, 'WP_ENVIRONMENT_TYPE', $environment_type );
		} else {
			$line   = "define( 'WP_ENVIRONMENT_TYPE', '" . $environment_type . "' );\n";
			$marker = preg_match( '/^.*\bdefined\(\s*[\'"]ABSPATH[\'"]\s*\).*$/m', $config, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] : null;
			$config = null === $marker ? rtrim( $config ) . "\n" . $line : substr_replace( $config, $line . "\n", $marker, 0 );
		}

		return $config;
	}

	private static function set_string( string $config, string $constant, string $value ): string {
		$escaped = str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $value );
		return (string) preg_replace_callback(
			self::define_pattern( $constant, self::STRING_VALUE ),
			static fn( array $m ): string => $m['pre'] . "'" . $escaped . "'" . $m['post'],
			$config
		);
	}

	/**
	 * Matches define( 'NAME', <value> ) with the groups "pre" (everything before
	 * the value, group 1) and "post" (everything after it).
	 */
	private static function define_pattern( string $constant, string $value ): string {
		return '/(?<pre>define\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]\s*,\s*)' . $value . '(?<post>\s*\))/i';
	}
}
