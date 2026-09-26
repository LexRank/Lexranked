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
		update_option( self::VERSION_OPTION, Schema::VERSION, false );
	}
}
