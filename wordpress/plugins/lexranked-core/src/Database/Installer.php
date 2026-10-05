<?php
/**
 * Install / upgrade routine.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Database;

use LexRanked\Core\Security\Capabilities;

/**
 * Creates/updates custom tables, roles and capabilities.
 *
 * Runs on activation and whenever the stored schema version is older than
 * Schema::VERSION, so updating the plugin from a ZIP without reactivating
 * still applies migrations.
 */
final class Installer {

	public const VERSION_OPTION = 'lexranked_db_version';

	/**
	 * Run install if needed.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) !== Schema::VERSION ) {
			self::install();
		}
	}

	/**
	 * Apply schema + capabilities (idempotent).
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::statements( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
		Capabilities::install();
		// Schema v4: hash pre-existing claims and index entities for candidate matching.
		$services = \LexRanked\Core\Plugin::services();
		$services->claims->backfill_hashes();
		$services->entity_index->reindex_all();
		// Schema v7: stable entity IDs for every lawyer, firm, location and practice area.
		$services->registry->backfill();
		// Schema v8: evidence keyed by entity, normalised values, the fact layer.
		$services->claims->backfill_entity_keys();
		$services->facts->rebuild_all();
		// Schema v9: the Data Quality Score of every lawyer and firm.
		$services->quality->compute_all();
		// Schema v6: commercial status is derived from claims and placements.
		$services->commercial->sync_all();
		// Schema v10: sites on the previous default score version move to the new one
		// once (a version chosen deliberately in Settings is kept on later upgrades).
		self::adopt_default_score_version();
		// DTO shapes may have changed: drop cached API responses.
		\LexRanked\Core\Support\ContentVersion::bump();
		update_option( self::VERSION_OPTION, Schema::VERSION, false );
	}

	/**
	 * Move a site still on the previous default score version to the new default.
	 */
	private static function adopt_default_score_version(): void {
		$settings = get_option( \LexRanked\Core\Settings\Settings::OPTION, array() );
		if ( ! is_array( $settings ) || \LexRanked\Core\Ranking\ScoreVersions::PREVIOUS_DEFAULT !== ( $settings['score_version'] ?? null ) ) {
			return;
		}
		$settings['score_version'] = \LexRanked\Core\Ranking\ScoreVersions::DEFAULT_VERSION;
		update_option( \LexRanked\Core\Settings\Settings::OPTION, $settings );
	}
}
