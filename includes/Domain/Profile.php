<?php
/**
 * A migration target ("Development", "Production") with all export options.
 * The only place that defines the stored shape; everything read from the
 * database or a form goes through Profile::parse().
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev\Domain;

// WordPress-free layer: wp_parse_url() is not available here.
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url

final class Profile {

	public const ENVIRONMENT_TYPES = [ 'production', 'staging', 'development' ];

	/** Search engine visibility on the target: keep the local setting, discourage or allow indexing. */
	public const SEARCH_ENGINE_MODES = [ 'keep', 'discourage', 'allow' ];

	public const DEFAULT_EXCLUDES = [
		'.git/',
		'.github/',
		'.svn/',
		'node_modules/',
		'.idea/',
		'.vscode/',
		'/*.md',
		'/wp-content/cache/',
		'/wp-content/upgrade/',
		'/wp-content/upgrade-temp-backup/',
		'/wp-content/ai1wm-backups/',
		'/wp-content/updraft/',
		'/wp-content/uploads/backwpup-*/',
		'*.log',
		'.DS_Store',
		'Thumbs.db',
		'desktop.ini',
	];

	/** Tables (without prefix) exported without rows because the plugins rebuild them. */
	public const DEFAULT_EMPTY_TABLES = [
		'actionscheduler_logs',
		'yoast_indexable',
		'yoast_indexable_hierarchy',
		'yoast_seo_links',
	];

	/**
	 * @param list<string> $exclude_patterns
	 * @param list<string> $empty_tables
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $target_url,
		public readonly string $target_path,
		public readonly string $table_prefix,
		public readonly string $environment_type,
		public readonly bool $include_files,
		public readonly array $exclude_patterns,
		public readonly array $empty_tables,
		public readonly bool $replace_guid,
		public readonly bool $root_relative_links,
		public readonly bool $skip_revisions,
		public readonly bool $skip_spam_comments,
		public readonly bool $portable_collations,
		public readonly bool $gzip,
		public readonly string $search_engines,
		public readonly bool $basic_auth,
		public readonly string $basic_auth_user,
		public readonly string $basic_auth_hash
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'id'                  => '',
			'name'                => '',
			'target_url'          => '',
			'target_path'         => '',
			'table_prefix'        => '',
			'environment_type'    => 'production',
			'include_files'       => true,
			'exclude_patterns'    => self::DEFAULT_EXCLUDES,
			'empty_tables'        => self::DEFAULT_EMPTY_TABLES,
			'replace_guid'        => true,
			'root_relative_links' => true,
			'skip_revisions'      => false,
			'skip_spam_comments'  => true,
			'portable_collations' => true,
			'gzip'                => true,
			'search_engines'      => 'keep',
			'basic_auth'          => false,
			'basic_auth_user'     => '',
			'basic_auth_hash'     => '',
		];
	}

	/**
	 * Normalizes raw data (stored option or submitted form). Invalid values fall
	 * back to the default and are reported as error codes.
	 *
	 * @param array<mixed> $raw
	 * @return array{profile: self, errors: list<string>}
	 */
	public static function parse( array $raw ): array {
		$defaults = self::defaults();
		$errors   = [];

		$name = trim( self::string( $raw['name'] ?? '' ) );
		if ( '' === $name ) {
			$errors[] = 'name_required';
		}

		$target_url = rtrim( trim( self::string( $raw['target_url'] ?? '' ) ), '/' );
		if ( ! self::is_http_url( $target_url ) ) {
			if ( '' !== $target_url ) {
				$errors[] = 'invalid_target_url';
			} else {
				$errors[] = 'target_url_required';
			}
			$target_url = '';
		}

		$target_path = rtrim( str_replace( '\\', '/', trim( self::string( $raw['target_path'] ?? '' ) ) ), '/' );
		if ( '' !== $target_path && ! preg_match( '#^(?:/|[A-Za-z]:/)[^/]#', $target_path ) ) {
			$errors[]    = 'invalid_target_path';
			$target_path = '';
		}

		$prefix = trim( self::string( $raw['table_prefix'] ?? '' ) );
		if ( '' !== $prefix && ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
			$errors[] = 'invalid_table_prefix';
			$prefix   = '';
		}

		$environment = self::string( $raw['environment_type'] ?? $defaults['environment_type'] );
		if ( ! in_array( $environment, self::ENVIRONMENT_TYPES, true ) ) {
			$environment = $defaults['environment_type'];
		}

		$search_engines = self::string( $raw['search_engines'] ?? $defaults['search_engines'] );
		if ( ! in_array( $search_engines, self::SEARCH_ENGINE_MODES, true ) ) {
			$search_engines = $defaults['search_engines'];
		}

		// Only the bcrypt hash is stored; a submitted password replaces it.
		$auth_user = trim( self::string( $raw['basic_auth_user'] ?? '' ) );
		$auth_hash = self::string( $raw['basic_auth_hash'] ?? '' );
		$password  = self::string( $raw['basic_auth_password'] ?? '' );
		if ( '' !== $password ) {
			$auth_hash = BasicAuth::hash( $password );
		}
		if ( ! BasicAuth::is_hash( $auth_hash ) ) {
			$auth_hash = '';
		}
		$basic_auth = self::bool( $raw, 'basic_auth' );
		if ( $basic_auth ) {
			if ( ! BasicAuth::is_valid_user( $auth_user ) ) {
				$errors[] = 'invalid_basic_auth_user';
			}
			if ( '' === $auth_hash ) {
				$errors[] = 'basic_auth_password_required';
			}
			if ( '' === $target_path ) {
				$errors[] = 'basic_auth_needs_target_path';
			}
		}

		$id = self::string( $raw['id'] ?? '' );
		if ( ! preg_match( '/^[a-z0-9-]{1,64}$/', $id ) ) {
			$id = self::slug( $name );
		}

		$profile = new self(
			$id,
			$name,
			$target_url,
			$target_path,
			$prefix,
			$environment,
			self::bool( $raw, 'include_files' ),
			self::lines( $raw['exclude_patterns'] ?? $defaults['exclude_patterns'] ),
			array_values( array_filter( self::lines( $raw['empty_tables'] ?? $defaults['empty_tables'] ), static fn( string $t ): bool => (bool) preg_match( '/^[A-Za-z0-9_$-]+$/', $t ) ) ),
			self::bool( $raw, 'replace_guid' ),
			self::bool( $raw, 'root_relative_links' ),
			self::bool( $raw, 'skip_revisions' ),
			self::bool( $raw, 'skip_spam_comments' ),
			self::bool( $raw, 'portable_collations' ),
			self::bool( $raw, 'gzip' ),
			$search_engines,
			$basic_auth,
			$auth_user,
			$auth_hash
		);

		return [
			'profile' => $profile,
			'errors'  => $errors,
		];
	}

	/**
	 * Value for the blog_public option on the target, or null to keep the local one.
	 */
	public function blog_public(): ?string {
		return match ( $this->search_engines ) {
			'discourage' => '0',
			'allow'      => '1',
			default      => null,
		};
	}

	/**
	 * Password protection is written only when everything it needs is present.
	 */
	public function has_basic_auth(): bool {
		return $this->basic_auth && BasicAuth::is_valid_user( $this->basic_auth_user ) && '' !== $this->basic_auth_hash && '' !== $this->target_path;
	}

	public function is_complete(): bool {
		return '' !== $this->name && '' !== $this->target_url;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	public static function slug( string $name ): string {
		$slug = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', $name ) );
		$slug = trim( substr( $slug, 0, 64 ), '-' );
		return '' === $slug ? 'profile' : $slug;
	}

	public static function is_http_url( string $url ): bool {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		$host = parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && '' !== $host && null === parse_url( $url, PHP_URL_QUERY ) && null === parse_url( $url, PHP_URL_FRAGMENT );
	}

	/**
	 * @param mixed $value
	 */
	private static function string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param array<mixed> $raw
	 */
	private static function bool( array $raw, string $key ): bool {
		if ( ! array_key_exists( $key, $raw ) ) {
			return (bool) self::defaults()[ $key ];
		}
		return in_array( $raw[ $key ], [ true, 1, '1', 'yes', 'on' ], true );
	}

	/**
	 * @param mixed $value List or text with one entry per line.
	 * @return list<string>
	 */
	private static function lines( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\R/', $value ) ?: [];
		}
		if ( ! is_array( $value ) ) {
			return [];
		}
		$lines = [];
		foreach ( $value as $line ) {
			$line = trim( self::string( $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return array_values( array_unique( $lines ) );
	}
}
