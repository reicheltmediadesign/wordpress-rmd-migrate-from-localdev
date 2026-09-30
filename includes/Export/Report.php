<?php
/**
 * MIGRATION.txt: what the export contains, how to upload it and what to check.
 *
 * @package RMD\MigrateFromLocaldev
 */

namespace RMD\MigrateFromLocaldev\Export;

use RMD\MigrateFromLocaldev\Domain\Profile;
use RMD\MigrateFromLocaldev\Domain\ReplacementPlan;

defined( 'ABSPATH' ) || exit;

final class Report {

	/**
	 * @param array<string, mixed> $job
	 */
	public static function render( array $job, Profile $profile, ReplacementPlan $plan ): string {
		$source = (array) $job['source'];
		$report = (array) $job['report'];
		$dump   = Job::SQL_FILE . ( $profile->gzip ? '.gz' : '' );
		$prefix = '' === $profile->table_prefix ? (string) $source['prefix'] : $profile->table_prefix;
		$lines  = [];

		$lines[] = 'RMD Migrate from Localdev ' . RMD_MFL_VERSION;
		$lines[] = str_repeat( '=', 40 );
		$lines[] = '';
		$lines[] = self::field( __( 'Profile', 'rmd-migrate-from-localdev' ), $profile->name );
		$lines[] = self::field( __( 'Created', 'rmd-migrate-from-localdev' ), wp_date( 'Y-m-d H:i', (int) $job['started'] ) );
		$lines[] = self::field( __( 'Source', 'rmd-migrate-from-localdev' ), $source['home'] . '  (' . $source['abspath'] . ')' );
		$lines[] = self::field( __( 'Target', 'rmd-migrate-from-localdev' ), $profile->target_url . ( '' === $profile->target_path ? '' : '  (' . $profile->target_path . ')' ) );
		$lines[] = self::field( __( 'Table prefix', 'rmd-migrate-from-localdev' ), $prefix );
		$lines[] = self::field(
			__( 'Search engines', 'rmd-migrate-from-localdev' ),
			match ( $profile->blog_public() ) {
				'0'     => __( 'indexing discouraged', 'rmd-migrate-from-localdev' ),
				'1'     => __( 'indexing allowed', 'rmd-migrate-from-localdev' ),
				default => __( 'as on the local site', 'rmd-migrate-from-localdev' ),
			}
		);
		$protected = $profile->include_files && $profile->has_basic_auth();
		$lines[]   = self::field(
			__( 'Password', 'rmd-migrate-from-localdev' ),
			$protected
				? sprintf(
					/* translators: %s: user name */
					__( 'protected, user "%s"', 'rmd-migrate-from-localdev' ),
					$profile->basic_auth_user
				)
				: __( 'none', 'rmd-migrate-from-localdev' )
		);
		$lines[] = '';

		$lines[] = self::heading( __( 'Contents', 'rmd-migrate-from-localdev' ) );
		if ( $profile->include_files ) {
			$lines[] = '- ' . Job::FILES_DIR . '/  ' . sprintf(
				/* translators: 1: number of files, 2: total size */
				__( 'The complete site (%1$s files, %2$s). Upload the contents of this folder into the web root of the target.', 'rmd-migrate-from-localdev' ),
				number_format_i18n( (int) $job['copy']['files'] ),
				size_format( (int) $job['copy']['bytes'] )
			);
		}
		$lines[] = '- ' . $dump . '  ' . __( 'Database dump for phpMyAdmin (Import tab). Tables with the same names are replaced.', 'rmd-migrate-from-localdev' );
		if ( file_exists( $job['dir'] . '/' . Job::CONFIG_FILE ) ) {
			$lines[] = '- ' . Job::CONFIG_FILE . '  ' . __( 'Only for a new installation: enter the database credentials of the target, rename it to wp-config.php and upload it. It has new security keys, debugging switched off and the table prefix of the dump.', 'rmd-migrate-from-localdev' );
		}
		$lines[] = '';

		$lines[] = self::heading( __( 'Next steps', 'rmd-migrate-from-localdev' ) );
		$steps   = [];
		if ( $profile->include_files ) {
			$steps[] = __( 'Upload the contents of files/ via SFTP. Keep the wp-config.php that already exists on the target (it is not part of the export).', 'rmd-migrate-from-localdev' );
		}
		$steps[] = sprintf(
			/* translators: %s: file name of the dump */
			__( 'Import %s in phpMyAdmin into the database of the target.', 'rmd-migrate-from-localdev' ),
			$dump
		);
		$steps[] = sprintf(
			/* translators: %s: table prefix */
			__( 'Check that $table_prefix in wp-config.php on the target is "%s".', 'rmd-migrate-from-localdev' ),
			$prefix
		);
		$steps[] = __( 'Log in on the target (the users and passwords of the local site apply) and save Settings → Permalinks once.', 'rmd-migrate-from-localdev' );
		$steps[] = __( 'Clear caches, and if you use Yoast SEO run SEO → Tools → "Optimize SEO data".', 'rmd-migrate-from-localdev' );
		if ( $protected ) {
			$steps[] = __( 'The site asks for the user name and password of the profile (.htaccess and .htpasswd in files/). External services such as payment webhooks cannot reach it; wp-cron.php stays open.', 'rmd-migrate-from-localdev' );
		}
		foreach ( $steps as $i => $step ) {
			$lines[] = ( $i + 1 ) . '. ' . $step;
		}
		$lines[] = '';

		$lines[] = self::heading( __( 'Database', 'rmd-migrate-from-localdev' ) );
		foreach ( (array) $job['db']['tables'] as $table ) {
			$stats   = $report['tables'][ $table['target'] ] ?? null;
			$lines[] = str_pad( (string) $table['target'], 40 ) . ' ' . ( null === $stats
				? __( 'structure only', 'rmd-migrate-from-localdev' )
				: sprintf(
					/* translators: 1: number of rows, 2: number of changed values */
					__( '%1$s rows, %2$s values changed', 'rmd-migrate-from-localdev' ),
					number_format_i18n( (int) $stats['rows'] ),
					number_format_i18n( (int) $stats['changed'] )
				) );
		}
		$lines[] = '';

		if ( [] !== $report['leftovers'] ) {
			$lines[] = self::heading( __( 'Please check: references to the local site remain', 'rmd-migrate-from-localdev' ) );
			$lines[] = sprintf(
				/* translators: %s: list of search terms */
				__( 'These values still contain %s after the replacement. Often harmless (e.g. SMTP host "localhost"), sometimes a spelling the plugin does not know.', 'rmd-migrate-from-localdev' ),
				'"' . implode( '", "', $plan->needles ) . '"'
			);
			foreach ( (array) $report['leftovers'] as $spot => $entry ) {
				$lines[] = sprintf( '- %s: %d × (%s)', $spot, (int) $entry['count'], implode( ', ', (array) $entry['samples'] ) );
			}
			$lines[] = '';
		}

		if ( [] !== $report['unparsable'] ) {
			$lines[] = self::heading( __( 'Serialized values that could not be read', 'rmd-migrate-from-localdev' ) );
			$lines[] = __( 'These values look like serialized PHP data but are malformed. They were exported unchanged.', 'rmd-migrate-from-localdev' );
			foreach ( (array) $report['unparsable'] as $spot => $count ) {
				$lines[] = sprintf( '- %s: %d', $spot, (int) $count );
			}
			$lines[] = '';
		}

		if ( [] !== $report['links'] ) {
			$lines[] = self::heading( __( 'Linked folders', 'rmd-migrate-from-localdev' ) );
			foreach ( (array) $report['links'] as $rel => $link ) {
				$lines[] = '- ' . $rel . ' → ' . $link['target'];
				$lines[] = '  ' . match ( $link['mode'] ) {
					'zip'        => sprintf(
						/* translators: 1: zip file path, 2: date */
						__( 'Unpacked from the release zip %1$s (built %2$s).', 'rmd-migrate-from-localdev' ),
						$link['zip'],
						wp_date( 'Y-m-d H:i', (int) $link['date'] )
					),
					'distignore' => __( 'Copied without the files listed in its .distignore.', 'rmd-migrate-from-localdev' ),
					default      => __( 'Copied completely (no .distignore found). Check for development files.', 'rmd-migrate-from-localdev' ),
				};
			}
			$lines[] = '';
		}

		if ( '' === $profile->target_path ) {
			$lines[] = __( 'No server path of the target was set, so local file paths were not replaced.', 'rmd-migrate-from-localdev' );
			$lines[] = '';
		}

		if ( [] !== $report['warnings'] ) {
			$lines[] = self::heading( __( 'Warnings', 'rmd-migrate-from-localdev' ) );
			foreach ( (array) $report['warnings'] as $warning ) {
				$lines[] = '- ' . $warning;
			}
			$lines[] = '';
		}

		return implode( "\r\n", $lines );
	}

	private static function heading( string $text ): string {
		return $text . "\r\n" . str_repeat( '-', max( 3, mb_strlen( $text ) ) );
	}

	private static function field( string $label, string $value ): string {
		return str_pad( $label . ':', 16 ) . $value;
	}
}
