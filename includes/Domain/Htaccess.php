<?php
/**
 * Adjusts the WordPress rewrite block of a .htaccess file to the path of the
 * target site (e.g. "RewriteBase /marleninflow.de/" → "RewriteBase /").
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev\Domain;

final class Htaccess {

	/**
	 * @param string $content .htaccess of the local site.
	 * @param string $path    URL path of the target site without trailing slash ("" for the web root).
	 */
	public static function for_target( string $content, string $path ): string {
		$base = rtrim( $path, '/' ) . '/';

		if ( ! preg_match( '/^# BEGIN WordPress\s*$.*?^# END WordPress\s*$/ms', $content, $match, PREG_OFFSET_CAPTURE ) ) {
			return rtrim( $content ) . ( '' === trim( $content ) ? '' : "\n\n" ) . self::default_block( $base ) . "\n";
		}

		$block = $match[0][0];
		$block = (string) preg_replace( '/^(\s*RewriteBase\s+)\S+/m', '${1}' . $base, $block );
		$block = (string) preg_replace( '/^(\s*RewriteRule\s+\.\s+)\S*index\.php/m', '${1}' . $base . 'index.php', $block );

		return substr_replace( $content, $block, $match[0][1], strlen( $match[0][0] ) );
	}

	private static function default_block( string $base ): string {
		return implode(
			"\n",
			[
				'# BEGIN WordPress',
				'<IfModule mod_rewrite.c>',
				'RewriteEngine On',
				'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
				'RewriteBase ' . $base,
				'RewriteRule ^index\.php$ - [L]',
				'RewriteCond %{REQUEST_FILENAME} !-f',
				'RewriteCond %{REQUEST_FILENAME} !-d',
				'RewriteRule . ' . $base . 'index.php [L]',
				'</IfModule>',
				'# END WordPress',
			]
		);
	}
}
