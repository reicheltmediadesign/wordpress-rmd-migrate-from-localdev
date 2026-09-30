<?php
declare(strict_types=1);

namespace RMD\MigrateFromLocaldev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\MigrateFromLocaldev\Domain\BasicAuth;
use RMD\MigrateFromLocaldev\Domain\Profile;

final class BasicAuthTest extends TestCase {

	public function test_hash_is_bcrypt_and_verifies(): void {
		$hash = BasicAuth::hash( 'geheim 123' );

		self::assertTrue( BasicAuth::is_hash( $hash ) );
		self::assertStringStartsWith( '$2y$', $hash );
		self::assertTrue( password_verify( 'geheim 123', $hash ) );
		self::assertFalse( BasicAuth::is_hash( 'geheim' ) );
	}

	public function test_user_names(): void {
		self::assertTrue( BasicAuth::is_valid_user( 'marlen' ) );
		self::assertFalse( BasicAuth::is_valid_user( '' ) );
		self::assertFalse( BasicAuth::is_valid_user( 'a:b' ) );
		self::assertFalse( BasicAuth::is_valid_user( 'a b' ) );
	}

	public function test_htpasswd_line(): void {
		self::assertSame( "marlen:\$2y\$10\$abc\n", BasicAuth::htpasswd( 'marlen', '$2y$10$abc' ) );
	}

	public function test_htaccess_block_keeps_wp_cron_open(): void {
		$block = BasicAuth::htaccess_block( '/mnt/web123/htdocs/marleninflow.de/.htpasswd' );

		self::assertStringContainsString( 'AuthType Basic', $block );
		self::assertStringContainsString( 'AuthUserFile "/mnt/web123/htdocs/marleninflow.de/.htpasswd"', $block );
		self::assertStringContainsString( 'Require valid-user', $block );
		self::assertStringContainsString( "<Files \"wp-cron.php\">\n\tRequire all granted\n</Files>", $block );
	}

	public function test_prepend_replaces_an_older_block(): void {
		$wordpress = "# BEGIN WordPress\nRewriteBase /\n# END WordPress\n";
		$once      = BasicAuth::prepend( $wordpress, BasicAuth::htaccess_block( '/old/.htpasswd' ) );
		$twice     = BasicAuth::prepend( $once, BasicAuth::htaccess_block( '/new/.htpasswd' ) );

		self::assertSame( 1, substr_count( $twice, BasicAuth::BEGIN_MARKER ) );
		self::assertStringContainsString( '/new/.htpasswd', $twice );
		self::assertStringNotContainsString( '/old/.htpasswd', $twice );
		self::assertStringEndsWith( $wordpress, $twice );
		self::assertStringStartsWith( BasicAuth::BEGIN_MARKER, BasicAuth::prepend( '', BasicAuth::htaccess_block( '/x' ) ) );
	}

	public function test_profile_hashes_password_and_keeps_only_the_hash(): void {
		$profile = Profile::parse(
			[
				'name'                => 'Staging',
				'target_url'          => 'https://rmd-dev.de/marleninflow.de',
				'target_path'         => '/mnt/web123/htdocs/marleninflow.de',
				'basic_auth'          => '1',
				'basic_auth_user'     => 'marlen',
				'basic_auth_password' => 'geheim',
			]
		)['profile'];

		self::assertTrue( $profile->has_basic_auth() );
		self::assertTrue( password_verify( 'geheim', $profile->basic_auth_hash ) );
		self::assertArrayNotHasKey( 'basic_auth_password', $profile->to_array() );
		self::assertStringNotContainsString( 'geheim', serialize( $profile->to_array() ) );

		$reloaded = Profile::parse( $profile->to_array() )['profile'];
		self::assertSame( $profile->basic_auth_hash, $reloaded->basic_auth_hash );
	}

	public function test_profile_validation(): void {
		$result = Profile::parse(
			[
				'name'            => 'Staging',
				'target_url'      => 'https://rmd-dev.de',
				'basic_auth'      => '1',
				'basic_auth_user' => 'a:b',
			]
		);

		self::assertSame( [ 'invalid_basic_auth_user', 'basic_auth_password_required', 'basic_auth_needs_target_path' ], $result['errors'] );
		self::assertFalse( $result['profile']->has_basic_auth() );
		self::assertFalse( Profile::parse( [] )['profile']->has_basic_auth() );
	}
}
