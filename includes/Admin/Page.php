<?php
/**
 * Tools → Migrate from localdev: profiles, running exports, finished exports
 * and the export folder setting.
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev\Admin;

use RMD\MigrateFromLocaldev\Domain\Profile;
use RMD\MigrateFromLocaldev\Export\Exports;
use RMD\MigrateFromLocaldev\Export\Job;
use RMD\MigrateFromLocaldev\Plugin;
use RMD\MigrateFromLocaldev\Rest\ExportController;
use RMD\MigrateFromLocaldev\Settings;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Page {

	public const SLUG = 'rmd-migrate-from-localdev';

	private const ERRORS_TRANSIENT = 'rmd_mfl_form_errors_';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
		add_action( 'admin_post_rmd_mfl_save_profile', [ self::class, 'save_profile' ] );
		add_action( 'admin_post_rmd_mfl_delete_profile', [ self::class, 'delete_profile' ] );
		add_action( 'admin_post_rmd_mfl_delete_export', [ self::class, 'delete_export' ] );
		add_action( 'admin_post_rmd_mfl_save_settings', [ self::class, 'save_settings' ] );
		add_filter( 'plugin_action_links_' . RMD_MFL_BASENAME, [ self::class, 'action_links' ] );
	}

	public static function menu(): void {
		add_management_page(
			__( 'Migrate from localdev', 'rmd-migrate-from-localdev' ),
			__( 'Migrate from localdev', 'rmd-migrate-from-localdev' ),
			Plugin::CAP,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public static function action_links( array $links ): array {
		return [ 'rmd-mfl' => '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Export', 'rmd-migrate-from-localdev' ) . '</a>' ] + $links;
	}

	/**
	 * @param array<string, string> $args
	 */
	public static function url( array $args = [] ): string {
		return add_query_arg( [ 'page' => self::SLUG ] + $args, admin_url( 'tools.php' ) );
	}

	public static function assets( string $hook ): void {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'rmd-mfl-admin', RMD_MFL_URL . 'assets/css/admin.css', [], RMD_MFL_VERSION );
		wp_enqueue_script( 'rmd-mfl-admin', RMD_MFL_URL . 'assets/js/admin.js', [ 'wp-api-fetch' ], RMD_MFL_VERSION, true );

		$job = Job::current();
		wp_localize_script(
			'rmd-mfl-admin',
			'rmdMflAdmin',
			[
				'path'    => '/' . ExportController::NAMESPACE . '/export',
				'status'  => null === $job ? null : Job::status( $job ),
				'strings' => [
					'confirmCancel' => __( 'Cancel the export and delete its unfinished folder?', 'rmd-migrate-from-localdev' ),
					'confirmDelete' => __( 'Delete this permanently?', 'rmd-migrate-from-localdev' ),
					'starting'      => __( 'Starting export …', 'rmd-migrate-from-localdev' ),
					'failed'        => __( 'The export stopped with an error:', 'rmd-migrate-from-localdev' ),
					'retry'         => __( 'Continue', 'rmd-migrate-from-localdev' ),
					'cancel'        => __( 'Cancel export', 'rmd-migrate-from-localdev' ),
					'dismiss'       => __( 'Dismiss', 'rmd-migrate-from-localdev' ),
					'folder'        => __( 'Export folder:', 'rmd-migrate-from-localdev' ),
					'copy'          => __( 'Copy path', 'rmd-migrate-from-localdev' ),
					'copied'        => __( 'Copied', 'rmd-migrate-from-localdev' ),
					'warnings'      => __( 'Warnings', 'rmd-migrate-from-localdev' ),
					'leftovers'     => __( 'Some values still refer to the local site. See MIGRATION.txt in the export folder.', 'rmd-migrate-from-localdev' ),
					'finished'      => __( 'Done. Upload the export as described in MIGRATION.txt.', 'rmd-migrate-from-localdev' ),
				],
			]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'rmd-migrate-from-localdev' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap rmd-mfl">';
		if ( 'edit' === $action ) {
			self::render_edit();
		} else {
			self::render_overview();
		}
		echo '</div>';
	}

	private static function render_overview(): void {
		global $wpdb;

		$local = Plugin::is_local();
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Migrate from localdev', 'rmd-migrate-from-localdev' ) . '</h1> ';
		printf( '<a href="%s" class="page-title-action">%s</a>', esc_url( self::url( [ 'action' => 'edit' ] ) ), esc_html__( 'Add profile', 'rmd-migrate-from-localdev' ) );
		echo '<hr class="wp-header-end">';

		self::render_notices();

		if ( ! $local ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This site does not look like a local development site, so exports are disabled. The plugin only runs on localhost, *.local/*.test hosts or with WP_ENVIRONMENT_TYPE set to "local".', 'rmd-migrate-from-localdev' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Creates a copy of this site for upload: all files and a database dump in which every address and path of this local site is replaced by that of the target, safe for serialized data.', 'rmd-migrate-from-localdev' ) . '</p>';
		echo '<table class="rmd-mfl-source"><tbody>';
		self::source_row( __( 'Local address', 'rmd-migrate-from-localdev' ), untrailingslashit( home_url() ) );
		self::source_row( __( 'Local path', 'rmd-migrate-from-localdev' ), untrailingslashit( wp_normalize_path( ABSPATH ) ) );
		self::source_row( __( 'Table prefix', 'rmd-migrate-from-localdev' ), $wpdb->prefix );
		echo '</tbody></table>';

		echo '<div id="rmd-mfl-progress" class="rmd-mfl-progress" hidden></div>';

		echo '<h2>' . esc_html__( 'Profiles', 'rmd-migrate-from-localdev' ) . '</h2>';
		$profiles = Settings::profiles();
		if ( [] === $profiles ) {
			echo '<p>' . esc_html__( 'Create a profile for each target, e.g. "Development" and "Production".', 'rmd-migrate-from-localdev' ) . '</p>';
		} else {
			echo '<table class="widefat striped rmd-mfl-profiles"><thead><tr>';
			echo '<th>' . esc_html__( 'Profile', 'rmd-migrate-from-localdev' ) . '</th>';
			echo '<th>' . esc_html__( 'Target', 'rmd-migrate-from-localdev' ) . '</th>';
			echo '<th>' . esc_html__( 'Content', 'rmd-migrate-from-localdev' ) . '</th>';
			echo '<th></th></tr></thead><tbody>';
			foreach ( $profiles as $profile ) {
				self::profile_row( $profile, $local );
			}
			echo '</tbody></table>';
		}

		self::render_exports();
		self::render_settings();
	}

	private static function source_row( string $label, string $value ): void {
		printf( '<tr><th scope="row">%s</th><td><code>%s</code></td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	private static function profile_row( Profile $profile, bool $local ): void {
		$delete = wp_nonce_url(
			add_query_arg(
				[
					'action'  => 'rmd_mfl_delete_profile',
					'profile' => $profile->id,
				],
				admin_url( 'admin-post.php' )
			),
			'rmd_mfl_delete_profile_' . $profile->id
		);

		echo '<tr>';
		printf(
			'<td><strong><a href="%s">%s</a></strong><div class="row-actions"><a href="%s">%s</a> | <span class="trash"><a href="%s" class="rmd-mfl-confirm">%s</a></span></div></td>',
			esc_url(
				self::url(
					[
						'action'  => 'edit',
						'profile' => $profile->id,
					]
				)
			),
			esc_html( $profile->name ),
			esc_url(
				self::url(
					[
						'action'  => 'edit',
						'profile' => $profile->id,
					]
				)
			),
			esc_html__( 'Edit', 'rmd-migrate-from-localdev' ),
			esc_url( $delete ),
			esc_html__( 'Delete', 'rmd-migrate-from-localdev' )
		);
		printf(
			'<td><code>%s</code>%s</td>',
			esc_html( $profile->target_url ),
			'' === $profile->target_path ? '' : '<br><code>' . esc_html( $profile->target_path ) . '</code>'
		);
		printf(
			'<td>%s</td>',
			esc_html( $profile->include_files ? __( 'Database and files', 'rmd-migrate-from-localdev' ) : __( 'Database only', 'rmd-migrate-from-localdev' ) )
		);
		printf(
			'<td class="rmd-mfl-actions"><button type="button" class="button button-primary rmd-mfl-export" data-profile="%s" %s>%s</button></td>',
			esc_attr( $profile->id ),
			disabled( ! $local, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute string.
			esc_html__( 'Export', 'rmd-migrate-from-localdev' )
		);
		echo '</tr>';
	}

	private static function render_exports(): void {
		$exports = Exports::all();
		echo '<h2>' . esc_html__( 'Exports', 'rmd-migrate-from-localdev' ) . '</h2>';
		if ( [] === $exports ) {
			echo '<p>' . esc_html__( 'No exports yet.', 'rmd-migrate-from-localdev' ) . '</p>';
			return;
		}

		$running = Job::is_running() ? basename( (string) Job::current()['dir'] ) : '';
		echo '<table class="widefat striped rmd-mfl-exports"><thead><tr>';
		echo '<th>' . esc_html__( 'Export', 'rmd-migrate-from-localdev' ) . '</th>';
		echo '<th>' . esc_html__( 'Database', 'rmd-migrate-from-localdev' ) . '</th>';
		echo '<th>' . esc_html__( 'Folder', 'rmd-migrate-from-localdev' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		foreach ( $exports as $export ) {
			$delete = wp_nonce_url(
				add_query_arg(
					[
						'action' => 'rmd_mfl_delete_export',
						'export' => $export['name'],
					],
					admin_url( 'admin-post.php' )
				),
				'rmd_mfl_delete_export_' . $export['name']
			);
			$state  = $export['complete'] ? '' : ' <span class="rmd-mfl-badge">' . esc_html( $running === $export['name'] ? __( 'running', 'rmd-migrate-from-localdev' ) : __( 'incomplete', 'rmd-migrate-from-localdev' ) ) . '</span>';
			printf(
				'<tr><td><strong>%s</strong>%s</td><td>%s</td><td><code class="rmd-mfl-path">%s</code></td><td class="rmd-mfl-actions">%s</td></tr>',
				esc_html( $export['name'] ),
				$state, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				esc_html( $export['database'] > 0 ? size_format( $export['database'] ) : '–' ),
				esc_html( $export['path'] ),
				$running === $export['name'] ? '' : '<a href="' . esc_url( $delete ) . '" class="button button-link-delete rmd-mfl-confirm">' . esc_html__( 'Delete', 'rmd-migrate-from-localdev' ) . '</a>'
			);
		}
		echo '</tbody></table>';
	}

	private static function render_settings(): void {
		$stored = get_option( Settings::OPTION, [] );
		$value  = is_array( $stored ) && isset( $stored['export_dir'] ) ? (string) $stored['export_dir'] : '';

		echo '<h2>' . esc_html__( 'Settings', 'rmd-migrate-from-localdev' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="rmd_mfl_save_settings">';
		wp_nonce_field( 'rmd_mfl_save_settings' );
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="rmd-mfl-export-dir">' . esc_html__( 'Export folder', 'rmd-migrate-from-localdev' ) . '</label></th><td>';
		printf(
			'<input type="text" id="rmd-mfl-export-dir" name="export_dir" value="%s" class="large-text code" placeholder="%s">',
			esc_attr( $value ),
			esc_attr( Settings::default_export_dir() )
		);
		echo '<p class="description">' . esc_html__( 'Absolute path, ideally outside the web root (e.g. C:\Users\name\Exports). The folder is left out of every export. Empty = default.', 'rmd-migrate-from-localdev' ) . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save settings', 'rmd-migrate-from-localdev' ) );
		echo '</form>';
	}

	private static function render_edit(): void {
		global $wpdb;

		$id      = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$profile = '' === $id ? null : Settings::profile( $id );
		$values  = null === $profile ? Profile::defaults() : $profile->to_array();

		$draft = get_transient( self::ERRORS_TRANSIENT . get_current_user_id() );
		if ( is_array( $draft ) && ( $draft['id'] ?? '' ) === $id ) {
			delete_transient( self::ERRORS_TRANSIENT . get_current_user_id() );
			$values = array_merge( $values, (array) $draft['values'] );
			echo '<div class="notice notice-error"><ul class="ul-disc">';
			foreach ( (array) $draft['errors'] as $code ) {
				echo '<li>' . esc_html( self::error_message( (string) $code ) ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<h1>' . esc_html( null === $profile ? __( 'Add profile', 'rmd-migrate-from-localdev' ) : __( 'Edit profile', 'rmd-migrate-from-localdev' ) ) . '</h1>';
		printf( '<p><a href="%s">← %s</a></p>', esc_url( self::url() ), esc_html__( 'Back to overview', 'rmd-migrate-from-localdev' ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="rmd_mfl_save_profile">';
		printf( '<input type="hidden" name="profile[id]" value="%s">', esc_attr( $id ) );
		wp_nonce_field( 'rmd_mfl_save_profile' );

		echo '<h2>' . esc_html__( 'Target', 'rmd-migrate-from-localdev' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_field( 'name', __( 'Name', 'rmd-migrate-from-localdev' ), (string) $values['name'], __( 'E.g. "Development" or "Production".', 'rmd-migrate-from-localdev' ), 'regular-text', true );
		self::text_field( 'target_url', __( 'Site address', 'rmd-migrate-from-localdev' ), (string) $values['target_url'], __( 'Address of the site on the target, e.g. https://example.com. Replaces every spelling of the local address.', 'rmd-migrate-from-localdev' ), 'regular-text code', true, 'https://example.com' );
		self::text_field( 'target_path', __( 'Server path', 'rmd-migrate-from-localdev' ), (string) $values['target_path'], __( 'Absolute path of the WordPress folder on the target server, e.g. /mnt/web123/a1/23/51234567/htdocs/example. Local file paths stored by plugins are replaced with it. Leave empty if unknown; remaining local paths are listed in the report.', 'rmd-migrate-from-localdev' ), 'large-text code' );
		self::text_field( 'table_prefix', __( 'Table prefix', 'rmd-migrate-from-localdev' ), (string) $values['table_prefix'], __( 'Empty = keep the local prefix. Must match $table_prefix in wp-config.php on the target.', 'rmd-migrate-from-localdev' ), 'small-text code', false, $wpdb->prefix );
		self::select_field( 'environment_type', __( 'Environment type', 'rmd-migrate-from-localdev' ), (string) $values['environment_type'], self::environment_labels(), __( 'Written as WP_ENVIRONMENT_TYPE into the wp-config.php template.', 'rmd-migrate-from-localdev' ) );
		self::select_field( 'search_engines', __( 'Search engines', 'rmd-migrate-from-localdev' ), (string) $values['search_engines'], self::search_engine_labels(), __( 'Sets "Discourage search engines from indexing this site" (Settings → Reading) in the dump. Recommended for development and staging sites. Search engines usually follow it, but it does not protect the site.', 'rmd-migrate-from-localdev' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Files', 'rmd-migrate-from-localdev' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox_field( 'include_files', __( 'Copy files', 'rmd-migrate-from-localdev' ), (bool) $values['include_files'], __( 'Copy the complete WordPress folder. Without it only the database is exported.', 'rmd-migrate-from-localdev' ) );
		self::textarea_field( 'exclude_patterns', __( 'Leave out', 'rmd-migrate-from-localdev' ), (array) $values['exclude_patterns'], __( 'One rule per line, like .gitignore: "name" matches everywhere, "/name" only in the WordPress folder, a trailing "/" only folders, "*" is a wildcard. wp-config.php, .htaccess (rewritten for the target), the export folder and this plugin are always left out. Linked folders (junctions) with a release zip in dist/ are replaced by that zip, otherwise their .distignore applies.', 'rmd-migrate-from-localdev' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Password protection', 'rmd-migrate-from-localdev' ) . '</h2>';
		echo '<p>' . esc_html__( 'For development and staging sites: visitors must enter a user name and password (HTTP basic authentication). The export writes the rules into .htaccess and the password file .htpasswd into files/, so the protection is renewed with every upload. Needs the server path and copied files.', 'rmd-migrate-from-localdev' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox_field( 'basic_auth', __( 'Protect the site', 'rmd-migrate-from-localdev' ), (bool) $values['basic_auth'], __( 'Ask for a user name and password. wp-cron.php stays open; external services such as payment webhooks cannot reach the site.', 'rmd-migrate-from-localdev' ) );
		self::text_field( 'basic_auth_user', __( 'User name', 'rmd-migrate-from-localdev' ), (string) $values['basic_auth_user'], __( 'Without spaces and colons.', 'rmd-migrate-from-localdev' ), 'regular-text' );
		$has_password = '' !== (string) $values['basic_auth_hash'];
		printf(
			'<tr><th scope="row"><label for="rmd-mfl-basic_auth_password">%s</label></th><td><input type="password" id="rmd-mfl-basic_auth_password" name="profile[basic_auth_password]" value="" class="regular-text" autocomplete="new-password" placeholder="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Password', 'rmd-migrate-from-localdev' ),
			esc_attr( $has_password ? __( 'unchanged', 'rmd-migrate-from-localdev' ) : '' ),
			esc_html( $has_password ? __( 'A password is set. Enter a new one to replace it. Only a hash is stored, so the password cannot be shown again.', 'rmd-migrate-from-localdev' ) : __( 'Only a hash is stored, so the password cannot be shown again. Note it down.', 'rmd-migrate-from-localdev' ) )
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Database', 'rmd-migrate-from-localdev' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::textarea_field( 'empty_tables', __( 'Tables without data', 'rmd-migrate-from-localdev' ), (array) $values['empty_tables'], __( 'Table names without prefix, one per line. They are created empty, e.g. logs or indexes that plugins rebuild.', 'rmd-migrate-from-localdev' ), 4 );
		self::checkbox_field( 'replace_guid', __( 'GUIDs', 'rmd-migrate-from-localdev' ), (bool) $values['replace_guid'], __( 'Also replace the local address in post GUIDs (recommended when a local site goes online for the first time).', 'rmd-migrate-from-localdev' ) );
		self::checkbox_field( 'root_relative_links', __( 'Relative links', 'rmd-migrate-from-localdev' ), (bool) $values['root_relative_links'], __( 'Adjust links like "/subfolder/wp-content/…" when the local site lives in a subfolder.', 'rmd-migrate-from-localdev' ) );
		self::checkbox_field( 'skip_revisions', __( 'Revisions', 'rmd-migrate-from-localdev' ), (bool) $values['skip_revisions'], __( 'Leave out post revisions.', 'rmd-migrate-from-localdev' ) );
		self::checkbox_field( 'skip_spam_comments', __( 'Spam', 'rmd-migrate-from-localdev' ), (bool) $values['skip_spam_comments'], __( 'Leave out spam and trashed comments.', 'rmd-migrate-from-localdev' ) );
		self::checkbox_field( 'portable_collations', __( 'Compatibility', 'rmd-migrate-from-localdev' ), (bool) $values['portable_collations'], __( 'Replace collations of MySQL 8 and new MariaDB versions that older database servers reject.', 'rmd-migrate-from-localdev' ) );
		self::checkbox_field( 'gzip', __( 'Compression', 'rmd-migrate-from-localdev' ), (bool) $values['gzip'], __( 'Save the dump as .sql.gz (phpMyAdmin imports it directly and the upload limit is reached later).', 'rmd-migrate-from-localdev' ) );
		echo '</tbody></table>';

		submit_button( null === $profile ? __( 'Add profile', 'rmd-migrate-from-localdev' ) : __( 'Save profile', 'rmd-migrate-from-localdev' ) );
		echo '</form>';
	}

	private static function text_field( string $key, string $label, string $value, string $description, string $css_class, bool $required = false, string $placeholder = '' ): void {
		printf(
			'<tr><th scope="row"><label for="rmd-mfl-%1$s">%2$s</label></th><td><input type="text" id="rmd-mfl-%1$s" name="profile[%1$s]" value="%3$s" class="%4$s" placeholder="%5$s" %6$s><p class="description">%7$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_attr( $css_class ),
			esc_attr( $placeholder ),
			$required ? 'required' : '',
			esc_html( $description )
		);
	}

	private static function checkbox_field( string $key, string $label, bool $checked, string $description ): void {
		printf(
			'<tr><th scope="row">%2$s</th><td><input type="hidden" name="profile[%1$s]" value="0"><label><input type="checkbox" name="profile[%1$s]" value="1" %3$s> %4$s</label></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			checked( $checked, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute string.
			esc_html( $description )
		);
	}

	/**
	 * @param array<string, string> $options
	 */
	private static function select_field( string $key, string $label, string $value, array $options, string $description ): void {
		printf( '<tr><th scope="row"><label for="rmd-mfl-%1$s">%2$s</label></th><td><select id="rmd-mfl-%1$s" name="profile[%1$s]">', esc_attr( $key ), esc_html( $label ) );
		foreach ( $options as $option => $option_label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $option_label ) );
		}
		echo '</select><p class="description">' . esc_html( $description ) . '</p></td></tr>';
	}

	/**
	 * @param list<string> $lines
	 */
	private static function textarea_field( string $key, string $label, array $lines, string $description, int $rows = 10 ): void {
		printf(
			'<tr><th scope="row"><label for="rmd-mfl-%1$s">%2$s</label></th><td><textarea id="rmd-mfl-%1$s" name="profile[%1$s]" rows="%3$d" class="large-text code">%4$s</textarea><p class="description">%5$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			(int) $rows,
			esc_textarea( implode( "\n", $lines ) ),
			esc_html( $description )
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function environment_labels(): array {
		return [
			'production'  => __( 'Production', 'rmd-migrate-from-localdev' ),
			'staging'     => __( 'Staging', 'rmd-migrate-from-localdev' ),
			'development' => __( 'Development', 'rmd-migrate-from-localdev' ),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function search_engine_labels(): array {
		return [
			'keep'       => __( 'Keep the setting of the local site', 'rmd-migrate-from-localdev' ),
			'discourage' => __( 'Discourage indexing', 'rmd-migrate-from-localdev' ),
			'allow'      => __( 'Allow indexing', 'rmd-migrate-from-localdev' ),
		];
	}

	private static function error_message( string $code ): string {
		$messages = [
			'name_required'                => __( 'Enter a name.', 'rmd-migrate-from-localdev' ),
			'target_url_required'          => __( 'Enter the site address of the target.', 'rmd-migrate-from-localdev' ),
			'invalid_target_url'           => __( 'The site address must start with http:// or https:// and must not contain a query or fragment.', 'rmd-migrate-from-localdev' ),
			'invalid_target_path'          => __( 'The server path must be absolute (start with /).', 'rmd-migrate-from-localdev' ),
			'invalid_table_prefix'         => __( 'The table prefix may only contain letters, digits and underscores.', 'rmd-migrate-from-localdev' ),
			'invalid_basic_auth_user'      => __( 'Enter a user name for the password protection (without spaces and colons).', 'rmd-migrate-from-localdev' ),
			'basic_auth_password_required' => __( 'Enter a password for the password protection.', 'rmd-migrate-from-localdev' ),
			'basic_auth_needs_target_path' => __( 'The password protection needs the server path of the target.', 'rmd-migrate-from-localdev' ),
		];
		return $messages[ $code ] ?? $code;
	}

	private static function render_notices(): void {
		$notice = isset( $_GET['rmd_mfl_notice'] ) ? sanitize_key( wp_unslash( $_GET['rmd_mfl_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error  = get_transient( self::ERRORS_TRANSIENT . 'message_' . get_current_user_id() );
		if ( is_string( $error ) && '' !== $error ) {
			delete_transient( self::ERRORS_TRANSIENT . 'message_' . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}

		$messages = [
			'profile_saved'   => __( 'Profile saved.', 'rmd-migrate-from-localdev' ),
			'profile_deleted' => __( 'Profile deleted.', 'rmd-migrate-from-localdev' ),
			'export_deleted'  => __( 'Export deleted.', 'rmd-migrate-from-localdev' ),
			'settings_saved'  => __( 'Settings saved.', 'rmd-migrate-from-localdev' ),
		];
		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
	}

	public static function save_profile(): void {
		self::guard( 'rmd_mfl_save_profile' );

		$raw    = isset( $_POST['profile'] ) && is_array( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Normalized by Profile::parse(), nonce checked in guard().
		$id     = sanitize_key( (string) ( $raw['id'] ?? '' ) );
		$stored = '' === $id ? null : Settings::profile( $id );
		$is_new = null === $stored;

		// The password hash never comes from the form: keep the stored one unless a new password was entered.
		$raw['basic_auth_hash'] = null === $stored ? '' : $stored->basic_auth_hash;
		$result                 = Profile::parse( [ 'id' => $is_new ? '' : $id ] + $raw );

		if ( [] !== $result['errors'] ) {
			set_transient(
				self::ERRORS_TRANSIENT . get_current_user_id(),
				[
					'id'     => $is_new ? '' : $id,
					'errors' => $result['errors'],
					'values' => array_intersect_key( $raw, Profile::defaults() ),
				],
				5 * MINUTE_IN_SECONDS
			);
			self::redirect(
				self::url(
					array_filter(
						[
							'action'  => 'edit',
							'profile' => $is_new ? '' : $id,
						]
					)
				)
			);
		}

		Settings::save_profile( $result['profile'], $is_new );
		self::redirect( self::url( [ 'rmd_mfl_notice' => 'profile_saved' ] ) );
	}

	public static function delete_profile(): void {
		$id = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in guard().
		self::guard( 'rmd_mfl_delete_profile_' . $id );
		Settings::delete_profile( $id );
		self::redirect( self::url( [ 'rmd_mfl_notice' => 'profile_deleted' ] ) );
	}

	public static function delete_export(): void {
		$name = isset( $_GET['export'] ) ? sanitize_file_name( wp_unslash( $_GET['export'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in guard().
		self::guard( 'rmd_mfl_delete_export_' . $name );
		try {
			$job = Job::current();
			if ( null !== $job && basename( (string) $job['dir'] ) === $name ) {
				Job::cancel();
			}
			Exports::delete( $name );
		} catch ( Throwable $e ) {
			self::fail( wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) );
		}
		self::redirect( self::url( [ 'rmd_mfl_notice' => 'export_deleted' ] ) );
	}

	public static function save_settings(): void {
		self::guard( 'rmd_mfl_save_settings' );
		$dir   = isset( $_POST['export_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['export_dir'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$error = Settings::save_export_dir( $dir );
		if ( null !== $error ) {
			self::fail( $error );
		}
		self::redirect( self::url( [ 'rmd_mfl_notice' => 'settings_saved' ] ) );
	}

	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'rmd-migrate-from-localdev' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private static function fail( string $message ): never {
		set_transient( self::ERRORS_TRANSIENT . 'message_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		self::redirect( self::url() );
	}

	private static function redirect( string $url ): never {
		wp_safe_redirect( $url );
		exit;
	}
}
