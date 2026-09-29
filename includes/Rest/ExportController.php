<?php
/**
 * REST endpoints the admin screen uses to run an export step by step.
 *
 *   GET    /rmd-migrate-from-localdev/v1/export        status of the current export
 *   POST   /rmd-migrate-from-localdev/v1/export        start an export { profile }
 *   POST   /rmd-migrate-from-localdev/v1/export/step   do the next few seconds of work
 *   DELETE /rmd-migrate-from-localdev/v1/export        cancel (or forget a finished export)
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev\Rest;

use RMD\MigrateFromLocaldev\Export\Job;
use RMD\MigrateFromLocaldev\Plugin;
use RMD\MigrateFromLocaldev\Settings;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class ExportController {

	public const NAMESPACE = 'rmd-migrate-from-localdev/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/export',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'status' ],
					'permission_callback' => [ self::class, 'permission' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'start' ],
					'permission_callback' => [ self::class, 'permission' ],
					'args'                => [
						'profile' => [
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^[a-z0-9-]{1,64}$',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'cancel' ],
					'permission_callback' => [ self::class, 'permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/export/step',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'step' ],
				'permission_callback' => [ self::class, 'permission' ],
			]
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function permission() {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to export this site.', 'rmd-migrate-from-localdev' ), [ 'status' => rest_authorization_required_code() ] );
		}
		if ( ! Plugin::is_local() ) {
			return new WP_Error( 'rmd_mfl_not_local', __( 'Exports are only possible on a local development site.', 'rmd-migrate-from-localdev' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public static function status(): WP_REST_Response {
		$job = Job::current();
		return new WP_REST_Response( null === $job ? null : Job::status( $job ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start( WP_REST_Request $request ) {
		$profile = Settings::profile( (string) $request->get_param( 'profile' ) );
		if ( null === $profile ) {
			return new WP_Error( 'rmd_mfl_unknown_profile', __( 'Unknown profile.', 'rmd-migrate-from-localdev' ), [ 'status' => 404 ] );
		}
		return self::guarded( static fn(): array => Job::status( Job::start( $profile ) ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function step() {
		return self::guarded( static fn(): array => Job::status( Job::step() ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel() {
		return self::guarded(
			static function (): ?array {
				Job::cancel();
				return null;
			}
		);
	}

	/**
	 * Turns exceptions into a REST error with the message, so the admin screen can show it.
	 *
	 * @param callable(): ?array<string, mixed> $callback
	 * @return WP_REST_Response|WP_Error
	 */
	private static function guarded( callable $callback ) {
		try {
			return new WP_REST_Response( $callback() );
		} catch ( Throwable $e ) {
			// Messages are escaped when thrown; the admin screen inserts them as text.
			return new WP_Error( 'rmd_mfl_export_failed', wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ), [ 'status' => 500 ] );
		}
	}
}
