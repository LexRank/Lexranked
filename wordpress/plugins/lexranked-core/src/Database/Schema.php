<?php
/**
 * Custom table definitions.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Database;

/**
 * DDL for LexRanked custom tables (dbDelta format).
 *
 * Kept ANSI-leaning so the data can later move to PostgreSQL; the reference
 * copy lives in database/schema/.
 */
final class Schema {

	/** Bump when the DDL below changes; triggers dbDelta on next load. */
	public const VERSION = '3';

	public const CLAIMS    = 'lr_claims';
	public const AUDIT_LOG = 'lr_audit_log';
	public const SNAPSHOTS = 'lr_ranking_snapshots';

	/**
	 * CREATE TABLE statements.
	 *
	 * @param string $prefix          Table prefix (e.g. wp_).
	 * @param string $charset_collate Charset/collation clause.
	 * @return array<string, string> Table name => SQL.
	 */
	public static function statements( string $prefix, string $charset_collate ): array {
		$claims = $prefix . self::CLAIMS;
		$audit  = $prefix . self::AUDIT_LOG;
		$snaps  = $prefix . self::SNAPSHOTS;

		return array(
			$claims => "CREATE TABLE {$claims} (
  claim_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entity_id bigint(20) unsigned NOT NULL,
  entity_type varchar(20) NOT NULL,
  field_name varchar(64) NOT NULL,
  value longtext NOT NULL,
  source_id bigint(20) unsigned DEFAULT NULL,
  source_url varchar(2048) NOT NULL DEFAULT '',
  source_type varchar(64) NOT NULL DEFAULT '',
  retrieved_at datetime NOT NULL,
  confidence decimal(4,3) NOT NULL DEFAULT 0.000,
  verification_status varchar(20) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  PRIMARY KEY  (claim_id),
  KEY entity (entity_type,entity_id),
  KEY entity_field (entity_id,field_name),
  KEY source_id (source_id)
) {$charset_collate};",
			$audit  => "CREATE TABLE {$audit} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  occurred_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(64) NOT NULL,
  object_type varchar(32) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  details longtext NULL,
  PRIMARY KEY  (id),
  KEY occurred_at (occurred_at),
  KEY object (object_type,object_id)
) {$charset_collate};",
			$snaps  => "CREATE TABLE {$snaps} (
  snapshot_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id char(36) NOT NULL,
  ranking_id bigint(20) unsigned NOT NULL DEFAULT 0,
  entity_id bigint(20) unsigned NOT NULL,
  entity_type varchar(20) NOT NULL,
  position int(10) unsigned NOT NULL DEFAULT 0,
  score decimal(6,2) NOT NULL,
  score_version varchar(20) NOT NULL,
  context longtext NOT NULL,
  components longtext NOT NULL,
  inputs longtext NOT NULL,
  calculated_at datetime NOT NULL,
  PRIMARY KEY  (snapshot_id),
  KEY ranking_run (ranking_id,run_id),
  KEY entity (entity_id,ranking_id),
  KEY calculated_at (calculated_at)
) {$charset_collate};",
		);
	}
}
