<?php
/**
 * Builds the search => replace pairs for one migration: every spelling of the
 * local URL (http/https, protocol-relative, JSON-escaped, URL-encoded), links
 * relative to the local subfolder and the local file system path.
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev\Domain;

// WordPress-free layer: wp_parse_url() is not available here.
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url

final class ReplacementPlan {

	/** Characters that open a root-relative link: href="/sub/…", url(/sub/…), JSON "\/sub\/…". */
	private const LINK_OPENERS = [ '"', "'", '(' ];

	/**
	 * @param array<string, string> $pairs   Search => replacement.
	 * @param list<string>          $needles Strings that should not remain after the migration (reported, not replaced).
	 */
	private function __construct(
		public readonly array $pairs,
		public readonly array $needles
	) {}

	/**
	 * @param array<string, string> $urls          Local URL => target URL. The first entry is the site address (home).
	 * @param string                $local_path    Local ABSPATH.
	 * @param string                $target_path   Absolute path on the target server; empty = do not replace paths.
	 * @param bool                  $root_relative Also rewrite links like "/subfolder/wp-content/…".
	 */
	public static function build( array $urls, string $local_path, string $target_path, bool $root_relative ): self {
		$pairs = [];

		foreach ( $urls as $from => $to ) {
			$from = rtrim( (string) $from, '/' );
			$to   = rtrim( $to, '/' );
			if ( '' === $from || $from === $to ) {
				continue;
			}
			$from_rest = self::strip_scheme( $from );
			$to_rest   = self::strip_scheme( $to );

			self::add_url( $pairs, 'http:' . $from_rest, $to );
			self::add_url( $pairs, 'https:' . $from_rest, $to );
			self::add_url( $pairs, $from_rest, $to_rest );

			// Without scheme ("localhost/site", e.g. a displayed web address). Only with a
			// path, otherwise a bare host name would be replaced in unrelated text.
			if ( '' !== self::url_path( $from ) ) {
				self::add_url( $pairs, substr( $from_rest, 2 ), substr( $to_rest, 2 ) );
			}
		}

		$first       = (string) array_key_first( $urls );
		$local_base  = self::url_path( $first );
		$target_base = self::url_path( (string) ( $urls[ $first ] ?? '' ) );
		if ( $root_relative && '' !== $local_base && $local_base !== $target_base ) {
			foreach ( self::LINK_OPENERS as $opener ) {
				self::add( $pairs, $opener . $local_base . '/', $opener . $target_base . '/' );
				self::add( $pairs, $opener . self::json_slashes( $local_base . '/' ), $opener . self::json_slashes( $target_base . '/' ) );
			}
		}

		$needles = [];
		$host    = (string) parse_url( $first, PHP_URL_HOST );
		if ( '' !== $host && ! self::any_contains( array_values( $urls ), $host ) ) {
			$needles[] = $host;
		}

		$local = rtrim( str_replace( '\\', '/', $local_path ), '/' );
		if ( '' !== $local ) {
			$target = rtrim( str_replace( '\\', '/', $target_path ), '/' );
			$paths  = [ $local ];
			if ( preg_match( '/^[A-Za-z]:/', $local ) ) {
				$paths[] = ctype_upper( $local[0] ) ? lcfirst( $local ) : ucfirst( $local );
			}
			foreach ( $paths as $path ) {
				$backslashed = str_replace( '/', '\\', $path );
				if ( '' !== $target && $path !== $target ) {
					$pairs[ $path ]                                     = $target;
					$pairs[ $backslashed ]                              = $target;
					$pairs[ self::json_slashes( $path ) ]               = self::json_slashes( $target );
					$pairs[ str_replace( '\\', '\\\\', $backslashed ) ] = self::json_slashes( $target );
				}
			}
			$needles[] = $local;
			$needles[] = str_replace( '/', '\\', $local );
		}

		return new self( $pairs, array_values( array_unique( $needles ) ) );
	}

	/**
	 * Path part of a URL without trailing slash ("" for a site in the web root).
	 */
	public static function url_path( string $url ): string {
		return rtrim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
	}

	/**
	 * @param array<string, string> $pairs
	 */
	private static function add_url( array &$pairs, string $from, string $to ): void {
		self::add( $pairs, $from, $to );
		self::add( $pairs, self::json_slashes( $from ), self::json_slashes( $to ) );
		self::add( $pairs, rawurlencode( $from ), rawurlencode( $to ) );
	}

	/**
	 * @param array<string, string> $pairs
	 */
	private static function add( array &$pairs, string $from, string $to ): void {
		if ( '' !== $from && $from !== $to ) {
			$pairs[ $from ] = $to;
		}
	}

	private static function strip_scheme( string $url ): string {
		return (string) preg_replace( '#^[a-z][a-z0-9+.-]*:(?=//)#i', '', $url );
	}

	private static function json_slashes( string $value ): string {
		return str_replace( '/', '\\/', $value );
	}

	/**
	 * @param list<string> $haystacks
	 */
	private static function any_contains( array $haystacks, string $needle ): bool {
		foreach ( $haystacks as $haystack ) {
			if ( str_contains( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
