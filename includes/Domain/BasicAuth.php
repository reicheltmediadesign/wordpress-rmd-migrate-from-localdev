<?php
/**
 * HTTP basic authentication for staging sites: an .htaccess block and the
 * matching .htpasswd line (bcrypt, understood by Apache 2.4).
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev\Domain;

final class BasicAuth {

	public const BEGIN_MARKER = '# BEGIN RMD Migrate from Localdev: password protection';
	public const END_MARKER   = '# END RMD Migrate from Localdev: password protection';

	/** Requested by WordPress itself (loopback), so it must stay reachable. */
	public const OPEN_FILES = [ 'wp-cron.php' ];

	public static function hash( string $password ): string {
		return password_hash( $password, PASSWORD_BCRYPT );
	}

	public static function is_hash( string $hash ): bool {
		return (bool) preg_match( '#^\$2[aby]\$\d{2}\$[./A-Za-z0-9]{53}$#', $hash );
	}

	public static function is_valid_user( string $user ): bool {
		return '' !== $user && ! preg_match( '/[:\s]/', $user );
	}

	public static function htpasswd( string $user, string $hash ): string {
		return $user . ':' . $hash . "\n";
	}

	/**
	 * @param string $htpasswd_path Absolute path of the .htpasswd file on the target server.
	 */
	public static function htaccess_block( string $htpasswd_path ): string {
		$lines = [
			self::BEGIN_MARKER,
			'AuthType Basic',
			'AuthName "Restricted"',
			'AuthUserFile "' . str_replace( '"', '', $htpasswd_path ) . '"',
			'Require valid-user',
		];
		foreach ( self::OPEN_FILES as $file ) {
			$lines[] = '<Files "' . $file . '">';
			$lines[] = "\tRequire all granted";
			$lines[] = '</Files>';
		}
		$lines[] = self::END_MARKER;

		return implode( "\n", $lines );
	}

	/**
	 * Puts the block at the top of an .htaccess file, replacing an older one.
	 */
	public static function prepend( string $htaccess, string $block ): string {
		$pattern  = '/^' . preg_quote( self::BEGIN_MARKER, '/' ) . '$.*?^' . preg_quote( self::END_MARKER, '/' ) . '$\R*/ms';
		$htaccess = (string) preg_replace( $pattern, '', $htaccess );

		return $block . "\n\n" . ltrim( $htaccess );
	}
}
