<?php
declare(strict_types=1);

namespace RMD\MigrateFromLocaldev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\MigrateFromLocaldev\Domain\Profile;

final class ProfileTest extends TestCase {

	public function test_valid_form_data(): void {
		$result = Profile::parse(
			[
				'name'             => 'Produktiv',
				'target_url'       => 'https://marleninflow.de/',
				'target_path'      => '/mnt/web123/htdocs/marleninflow/',
				'table_prefix'     => 'wp_',
				'environment_type' => 'staging',
				'include_files'    => '1',
				'exclude_patterns' => ".git/\n\n  *.log  \n.git/",
				'empty_tables'     => "yoast_indexable\nbad table;",
				'replace_guid'     => '0',
			]
		);
		$profile = $result['profile'];

		self::assertSame( [], $result['errors'] );
		self::assertSame( 'produktiv', $profile->id );
		self::assertSame( 'https://marleninflow.de', $profile->target_url );
		self::assertSame( '/mnt/web123/htdocs/marleninflow', $profile->target_path );
		self::assertSame( 'wp_', $profile->table_prefix );
		self::assertSame( 'staging', $profile->environment_type );
		self::assertTrue( $profile->include_files );
		self::assertSame( [ '.git/', '*.log' ], $profile->exclude_patterns );
		self::assertSame( [ 'yoast_indexable' ], $profile->empty_tables );
		self::assertFalse( $profile->replace_guid );
		self::assertTrue( $profile->gzip );
		self::assertTrue( $profile->is_complete() );
	}

	public function test_defaults_for_missing_keys(): void {
		$profile = Profile::parse( [] )['profile'];

		self::assertSame( Profile::DEFAULT_EXCLUDES, $profile->exclude_patterns );
		self::assertSame( Profile::DEFAULT_EMPTY_TABLES, $profile->empty_tables );
		self::assertSame( 'production', $profile->environment_type );
		self::assertFalse( $profile->is_complete() );
	}

	public function test_invalid_values_fall_back(): void {
		$result = Profile::parse(
			[
				'id'               => 'Not Valid!',
				'name'             => 'Dev',
				'target_url'       => 'ftp://example.com',
				'target_path'      => 'relative/path',
				'table_prefix'     => 'wp-',
				'environment_type' => 'local',
			]
		);

		self::assertSame( [ 'invalid_target_url', 'invalid_target_path', 'invalid_table_prefix' ], $result['errors'] );
		self::assertSame( 'dev', $result['profile']->id );
		self::assertSame( '', $result['profile']->target_url );
		self::assertSame( '', $result['profile']->target_path );
		self::assertSame( '', $result['profile']->table_prefix );
		self::assertSame( 'production', $result['profile']->environment_type );
	}

	public function test_round_trip(): void {
		$profile = Profile::parse(
			[
				'name'       => 'Entwicklung',
				'target_url' => 'https://dev.example.com/site',
				'gzip'       => false,
			]
		)['profile'];

		self::assertEquals( $profile, Profile::parse( $profile->to_array() )['profile'] );
	}

	public function test_required_fields(): void {
		self::assertSame( [ 'name_required', 'target_url_required' ], Profile::parse( [] )['errors'] );
	}

	public function test_url_validation(): void {
		self::assertTrue( Profile::is_http_url( 'https://example.com/sub' ) );
		self::assertFalse( Profile::is_http_url( 'https://example.com/?a=1' ) );
		self::assertFalse( Profile::is_http_url( 'example.com' ) );
	}
}
