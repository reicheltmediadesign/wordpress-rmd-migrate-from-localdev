<?php
declare(strict_types=1);

namespace RMD\MigrateFromLocaldev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\MigrateFromLocaldev\Domain\Htaccess;
use RMD\MigrateFromLocaldev\Domain\LocalEnvironment;
use RMD\MigrateFromLocaldev\Domain\WpConfigTemplate;

final class TargetFilesTest extends TestCase {

	private const HTACCESS = <<<'HTACCESS'
# Custom rule
Header set X-Test 1

# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /marleninflow.de/
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /marleninflow.de/index.php [L]
</IfModule>

# END WordPress
HTACCESS;

	public function test_htaccess_rewrite_base_for_root(): void {
		$result = Htaccess::for_target( self::HTACCESS, '' );

		self::assertStringContainsString( "RewriteBase /\n", $result );
		self::assertStringContainsString( 'RewriteRule . /index.php [L]', $result );
		self::assertStringContainsString( 'Header set X-Test 1', $result );
		self::assertStringNotContainsString( 'marleninflow.de', $result );
	}

	public function test_htaccess_rewrite_base_for_subfolder(): void {
		$result = Htaccess::for_target( self::HTACCESS, '/dev' );

		self::assertStringContainsString( "RewriteBase /dev/\n", $result );
		self::assertStringContainsString( 'RewriteRule . /dev/index.php [L]', $result );
	}

	public function test_htaccess_without_wordpress_block(): void {
		$result = Htaccess::for_target( "Options -Indexes\n", '' );

		self::assertStringStartsWith( "Options -Indexes\n\n# BEGIN WordPress", $result );
		self::assertStringContainsString( 'RewriteBase /', $result );
	}

	public function test_wp_config_template(): void {
		$source = <<<'PHP'
<?php
define( 'DB_NAME', 'marleninflow.de' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'ro\'ot' );
define( 'DB_HOST', 'localhost' );
define( 'AUTH_KEY',         'old key' );
$table_prefix = 'l9jauo_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_HOME', 'http://localhost/marleninflow.de' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP;

		$result = WpConfigTemplate::render( $source, 'wp_', 'production', [ 'AUTH_KEY' => "new'key" ] );

		self::assertStringContainsString( "define( 'DB_NAME', 'ENTER_DATABASE_NAME' );", $result );
		self::assertStringContainsString( "define( 'DB_PASSWORD', 'ENTER_DATABASE_PASSWORD' );", $result );
		self::assertStringContainsString( "define( 'DB_HOST', 'ENTER_DATABASE_HOST' );", $result );
		self::assertStringContainsString( "define( 'AUTH_KEY',         'new\\'key' );", $result );
		self::assertStringContainsString( "\$table_prefix = 'wp_';", $result );
		self::assertStringContainsString( "define( 'WP_DEBUG', false );", $result );
		self::assertStringContainsString( "define( 'WP_DEBUG_LOG', false );", $result );
		self::assertStringContainsString( "define( 'WP_ENVIRONMENT_TYPE', 'production' );\n\nif ( ! defined( 'ABSPATH' ) )", $result );
		self::assertStringNotContainsString( 'root', $result );
	}

	public function test_wp_config_existing_environment_type(): void {
		$result = WpConfigTemplate::render( "<?php\ndefine( 'WP_ENVIRONMENT_TYPE', 'local' );\n", '', 'staging', [] );

		self::assertSame( "<?php\ndefine( 'WP_ENVIRONMENT_TYPE', 'staging' );\n", $result );
	}

	public function test_local_environment(): void {
		self::assertTrue( LocalEnvironment::is_local( 'example.com', 'local' ) );
		self::assertTrue( LocalEnvironment::is_local( 'localhost', 'production' ) );
		self::assertTrue( LocalEnvironment::is_local( '[::1]', 'production' ) );
		self::assertTrue( LocalEnvironment::is_local( 'marleninflow.test', 'production' ) );
		self::assertFalse( LocalEnvironment::is_local( 'marleninflow.de', 'production' ) );
		self::assertFalse( LocalEnvironment::is_local( 'localhost.example.com', 'development' ) );
	}
}
