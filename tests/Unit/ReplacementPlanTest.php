<?php
declare(strict_types=1);

namespace RMD\MigrateFromLocaldev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\MigrateFromLocaldev\Domain\ReplacementPlan;
use RMD\MigrateFromLocaldev\Domain\SerializedReplacer;

final class ReplacementPlanTest extends TestCase {

	private function plan( string $target_path = '/mnt/web123/htdocs/marleninflow', bool $root_relative = true ): ReplacementPlan {
		return ReplacementPlan::build(
			[ 'http://localhost/marleninflow.de' => 'https://marleninflow.de' ],
			'C:\\xampp\\htdocs\\marleninflow.de/',
			$target_path,
			$root_relative
		);
	}

	private function apply( ReplacementPlan $plan, string $value ): string {
		return ( new SerializedReplacer( $plan->pairs ) )->replace( $value );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function variants(): iterable {
		yield 'plain' => [ 'http://localhost/marleninflow.de/kontakt/', 'https://marleninflow.de/kontakt/' ];
		yield 'https' => [ 'https://localhost/marleninflow.de/', 'https://marleninflow.de/' ];
		yield 'protocol-relative' => [ 'src="//localhost/marleninflow.de/a.js"', 'src="//marleninflow.de/a.js"' ];
		yield 'json' => [ '{"url":"http:\/\/localhost\/marleninflow.de\/x"}', '{"url":"https:\/\/marleninflow.de\/x"}' ];
		yield 'urlencoded' => [ 'u=http%3A%2F%2Flocalhost%2Fmarleninflow.de%2Fx', 'u=https%3A%2F%2Fmarleninflow.de%2Fx' ];
		yield 'root-relative href' => [ '<a href="/marleninflow.de/kontakt/">', '<a href="/kontakt/">' ];
		yield 'root-relative css' => [ 'background:url(/marleninflow.de/wp-content/a.png)', 'background:url(/wp-content/a.png)' ];
		yield 'root-relative json' => [ '{"href":"\/marleninflow.de\/x"}', '{"href":"\/x"}' ];
		yield 'path backslashes' => [ 'C:\\xampp\\htdocs\\marleninflow.de\\wp-content', '/mnt/web123/htdocs/marleninflow\\wp-content' ];
		yield 'path forward slashes' => [ 'C:/xampp/htdocs/marleninflow.de/wp-content', '/mnt/web123/htdocs/marleninflow/wp-content' ];
		yield 'path lowercase drive' => [ 'c:/xampp/htdocs/marleninflow.de/x', '/mnt/web123/htdocs/marleninflow/x' ];
		yield 'path json' => [ '"C:\\\\xampp\\\\htdocs\\\\marleninflow.de\\\\x"', '"\\/mnt\\/web123\\/htdocs\\/marleninflow\\\\x"' ];
		yield 'without scheme' => [ 'Web: localhost/marleninflow.de', 'Web: marleninflow.de' ];
		yield 'without scheme json' => [ '{"web":"localhost\/marleninflow.de"}', '{"web":"marleninflow.de"}' ];
		yield 'bare host stays' => [ 'SMTP host: localhost', 'SMTP host: localhost' ];
		yield 'unrelated text' => [ 'Visit marleninflow.de today', 'Visit marleninflow.de today' ];
	}

	/**
	 * @dataProvider variants
	 */
	public function test_variants( string $input, string $expected ): void {
		self::assertSame( $expected, $this->apply( $this->plan(), $input ) );
	}

	public function test_without_target_path_paths_stay(): void {
		$plan = $this->plan( '' );
		self::assertSame( 'C:/xampp/htdocs/marleninflow.de/x', $this->apply( $plan, 'C:/xampp/htdocs/marleninflow.de/x' ) );
		self::assertContains( 'C:/xampp/htdocs/marleninflow.de', $plan->needles );
	}

	public function test_root_relative_can_be_disabled(): void {
		self::assertSame( 'href="/marleninflow.de/x"', $this->apply( $this->plan( '', false ), 'href="/marleninflow.de/x"' ) );
	}

	public function test_target_in_subfolder(): void {
		$plan = ReplacementPlan::build( [ 'http://localhost/site' => 'https://dev.example.com/site-dev' ], '', '', true );

		self::assertSame( 'href="/site-dev/x" https://dev.example.com/site-dev/y', $this->apply( $plan, 'href="/site/x" http://localhost/site/y' ) );
	}

	public function test_site_in_web_root_does_not_replace_bare_host(): void {
		$plan = ReplacementPlan::build( [ 'http://marleninflow.local' => 'https://marleninflow.de' ], '', '', true );

		self::assertSame( 'info@marleninflow.local https://marleninflow.de/x', $this->apply( $plan, 'info@marleninflow.local http://marleninflow.local/x' ) );
	}

	public function test_needles_contain_local_host(): void {
		self::assertContains( 'localhost', $this->plan()->needles );
	}

	public function test_needle_skipped_when_target_contains_host(): void {
		$plan = ReplacementPlan::build( [ 'http://example.com.local' => 'https://example.com.local.de' ], '', '', true );
		self::assertNotContains( 'example.com.local', $plan->needles );
	}

	public function test_site_address_and_wordpress_address(): void {
		$plan = ReplacementPlan::build(
			[
				'http://localhost/site'    => 'https://example.com',
				'http://localhost/site/wp' => 'https://example.com/wp',
			],
			'',
			'',
			true
		);

		self::assertSame( 'https://example.com/wp/wp-admin https://example.com/about', $this->apply( $plan, 'http://localhost/site/wp/wp-admin http://localhost/site/about' ) );
	}
}
