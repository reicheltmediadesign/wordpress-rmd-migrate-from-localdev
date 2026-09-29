<?php
/**
 * One export run. The state lives in an option so the export can be split into
 * short steps (one REST request each) and survives time limits and reloads.
 *
 * Phases: database → scan → copy (only with files) → finalize → done.
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev\Export;

use RMD\MigrateFromLocaldev\Domain\Profile;
use RMD\MigrateFromLocaldev\Domain\ReplacementPlan;
use RMD\MigrateFromLocaldev\Settings;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Job {

	public const OPTION = 'rmd_mfl_job';
	private const LOCK  = 'rmd_mfl_lock';

	/** Seconds of work per request, kept well below common time limits. */
	private const STEP_SECONDS = 12;

	public const SQL_FILE      = 'database.sql';
	public const FILES_DIR     = 'files';
	public const MANIFEST_FILE = '.manifest';
	public const REPORT_FILE   = 'MIGRATION.txt';
	public const CONFIG_FILE   = 'wp-config-template.php';

	/**
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		$job = get_option( self::OPTION, null );
		return is_array( $job ) && isset( $job['phase'], $job['dir'], $job['profile'] ) ? $job : null;
	}

	public static function is_running(): bool {
		$job = self::current();
		return null !== $job && 'done' !== $job['phase'];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function start( Profile $profile ): array {
		global $wpdb;

		if ( is_multisite() ) {
			throw new RuntimeException( esc_html__( 'Multisite networks are not supported.', 'rmd-migrate-from-localdev' ) );
		}
		if ( self::is_running() ) {
			throw new RuntimeException( esc_html__( 'Another export is still running. Continue or cancel it first.', 'rmd-migrate-from-localdev' ) );
		}

		$root = Exports::prepare_root();
		$dir  = $root . '/' . wp_date( 'Y-m-d_His' ) . '_' . $profile->id;
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( esc_html__( 'The export folder could not be created.', 'rmd-migrate-from-localdev' ) );
		}

		$job = [
			'id'       => basename( $dir ),
			'dir'      => $dir,
			'profile'  => $profile->to_array(),
			'source'   => [
				'home'    => untrailingslashit( home_url() ),
				'siteurl' => untrailingslashit( site_url() ),
				'abspath' => untrailingslashit( wp_normalize_path( ABSPATH ) ),
				'prefix'  => $wpdb->prefix,
			],
			'phase'    => 'database',
			'started'  => time(),
			'finished' => 0,
			'db'       => DatabaseExport::init( $profile ),
			'scan'     => [
				'stack' => [ '' ],
				'links' => [],
				'bytes' => 0,
				'files' => 0,
				'size'  => 0,
			],
			'copy'     => [
				'offset' => 0,
				'files'  => 0,
				'bytes'  => 0,
			],
			'report'   => [
				'tables'     => [],
				'leftovers'  => [],
				'unparsable' => [],
				'links'      => [],
				'warnings'   => [],
				'failed'     => 0,
			],
		];

		update_option( self::OPTION, $job, false );
		return $job;
	}

	/**
	 * Runs work for a few seconds and returns the updated job.
	 *
	 * @return array<string, mixed>
	 */
	public static function step(): array {
		$job = self::current();
		if ( null === $job ) {
			throw new RuntimeException( esc_html__( 'There is no export to continue.', 'rmd-migrate-from-localdev' ) );
		}
		if ( 'done' === $job['phase'] ) {
			return $job;
		}
		if ( get_transient( self::LOCK ) ) {
			throw new RuntimeException( esc_html__( 'The export is busy with the previous step. Please wait a moment.', 'rmd-migrate-from-localdev' ) );
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 5 * MINUTE_IN_SECONDS );
		}
		$deadline = microtime( true ) + self::STEP_SECONDS;

		try {
			$profile = self::profile( $job );
			switch ( $job['phase'] ) {
				case 'database':
					DatabaseExport::run( $job, $profile, self::plan( $job, $profile ), $deadline );
					if ( 'done' === $job['db']['state'] ) {
						$job['phase'] = $profile->include_files ? 'scan' : 'finalize';
					}
					break;

				case 'scan':
					FileExport::scan( $job, $profile, $deadline );
					if ( [] === $job['scan']['stack'] ) {
						$job['phase'] = 'copy';
					}
					break;

				case 'copy':
					if ( FileExport::copy( $job, $deadline ) ) {
						$job['phase'] = 'finalize';
					}
					break;

				case 'finalize':
					Finisher::run( $job, $profile, self::plan( $job, $profile ) );
					$job['phase']    = 'done';
					$job['finished'] = time();
					break;
			}
		} finally {
			update_option( self::OPTION, $job, false );
			delete_transient( self::LOCK );
		}

		return $job;
	}

	/**
	 * Stops a running export and removes its unfinished folder; forgets a finished one.
	 */
	public static function cancel(): void {
		$job = self::current();
		if ( null !== $job && 'done' !== $job['phase'] ) {
			Exports::delete( basename( (string) $job['dir'] ) );
		}
		delete_option( self::OPTION );
		delete_transient( self::LOCK );
	}

	/**
	 * @param array<string, mixed> $job
	 */
	public static function profile( array $job ): Profile {
		return Profile::parse( (array) $job['profile'] )['profile'];
	}

	/**
	 * @param array<string, mixed> $job
	 */
	public static function plan( array $job, Profile $profile ): ReplacementPlan {
		$source = (array) $job['source'];
		$urls   = [ (string) $source['home'] => $profile->target_url ];

		$siteurl = (string) $source['siteurl'];
		if ( $siteurl !== $source['home'] ) {
			$urls[ $siteurl ] = str_starts_with( $siteurl, (string) $source['home'] )
				? $profile->target_url . substr( $siteurl, strlen( (string) $source['home'] ) )
				: $profile->target_url;
		}

		return ReplacementPlan::build( $urls, (string) $source['abspath'], $profile->target_path, $profile->root_relative_links );
	}

	/**
	 * Adds a warning to the report (capped so the option stays small).
	 *
	 * @param array<string, mixed> $job
	 */
	public static function warn( array &$job, string $message ): void {
		if ( count( $job['report']['warnings'] ) < 100 ) {
			$job['report']['warnings'][] = $message;
		}
	}

	/**
	 * Data for the admin screen.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	public static function status( array $job ): array {
		$profile = self::profile( $job );
		$files   = $profile->include_files;
		$db      = (array) $job['db'];
		$tables  = max( 1, count( (array) $db['tables'] ) );

		$db_share  = $files ? 0.45 : 0.95;
		$db_part   = min( 1, (int) $db['index'] / $tables );
		$copy_part = $job['scan']['bytes'] > 0 ? min( 1, (int) $job['copy']['offset'] / (int) $job['scan']['bytes'] ) : 0;
		$progress  = match ( $job['phase'] ) {
			'database' => $db_share * $db_part,
			'scan'     => $db_share + 0.05,
			'copy'     => $db_share + 0.05 + 0.45 * $copy_part,
			'finalize' => 0.97,
			default    => 1.0,
		};

		$message = match ( $job['phase'] ) {
			'database' => sprintf(
				/* translators: 1: table name, 2: number of the table, 3: number of tables */
				__( 'Exporting table %1$s (%2$d of %3$d) …', 'rmd-migrate-from-localdev' ),
				(string) ( $db['tables'][ $db['index'] ]['name'] ?? '' ),
				min( (int) $db['index'] + 1, $tables ),
				$tables
			),
			'scan'     => sprintf(
				/* translators: %s: number of files */
				__( 'Collecting files … %s found', 'rmd-migrate-from-localdev' ),
				number_format_i18n( (int) $job['scan']['files'] )
			),
			'copy'     => sprintf(
				/* translators: 1: copied files, 2: all files, 3: copied size */
				__( 'Copying files … %1$s of %2$s (%3$s)', 'rmd-migrate-from-localdev' ),
				number_format_i18n( (int) $job['copy']['files'] ),
				number_format_i18n( (int) $job['scan']['files'] ),
				size_format( (int) $job['copy']['bytes'] )
			),
			'finalize' => __( 'Compressing the database and writing the report …', 'rmd-migrate-from-localdev' ),
			default    => __( 'Export finished.', 'rmd-migrate-from-localdev' ),
		};

		return [
			'id'        => (string) $job['id'],
			'phase'     => (string) $job['phase'],
			'done'      => 'done' === $job['phase'],
			'progress'  => round( $progress, 3 ),
			'message'   => $message,
			'profile'   => $profile->name,
			'dir'       => wp_normalize_path( (string) $job['dir'] ),
			'warnings'  => array_values( (array) $job['report']['warnings'] ),
			'leftovers' => count( (array) $job['report']['leftovers'] ),
		];
	}
}
