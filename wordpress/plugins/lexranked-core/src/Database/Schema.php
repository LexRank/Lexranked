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
	public const VERSION = '6';

	public const CLAIMS         = 'lr_claims';
	public const AUDIT_LOG      = 'lr_audit_log';
	public const SNAPSHOTS      = 'lr_ranking_snapshots';
	public const CANDIDATE      = 'lr_candidates';
	public const JOB_LOG        = 'lr_research_log';
	public const PROFILE_CLAIMS = 'lr_profile_claims';
	public const PLACEMENTS     = 'lr_placements';

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
		$cands  = $prefix . self::CANDIDATE;
		$logs   = $prefix . self::JOB_LOG;
		$pclaim = $prefix . self::PROFILE_CLAIMS;
		$place  = $prefix . self::PLACEMENTS;

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
  claim_hash char(40) DEFAULT NULL,
  job_id bigint(20) unsigned NOT NULL DEFAULT 0,
  review_status varchar(20) NOT NULL DEFAULT 'approved',
  method varchar(20) NOT NULL DEFAULT 'manual',
  created_at datetime NOT NULL,
  PRIMARY KEY  (claim_id),
  UNIQUE KEY claim_hash (claim_hash),
  KEY review_status (review_status),
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
			$cands  => "CREATE TABLE {$cands} (
  candidate_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  dedupe_key char(40) NOT NULL,
  job_id bigint(20) unsigned NOT NULL DEFAULT 0,
  entity_type varchar(20) NOT NULL,
  name varchar(255) NOT NULL,
  normalized_name varchar(255) NOT NULL,
  city varchar(100) DEFAULT NULL,
  state varchar(100) DEFAULT NULL,
  practice_area varchar(100) DEFAULT NULL,
  website varchar(2048) DEFAULT NULL,
  source_url varchar(2048) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'new',
  entity_id bigint(20) unsigned DEFAULT NULL,
  match_confidence decimal(4,3) DEFAULT NULL,
  reason varchar(500) DEFAULT NULL,
  payload longtext NULL,
  ai_note longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (candidate_id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY status (status),
  KEY job_id (job_id)
) {$charset_collate};",
			$logs   => "CREATE TABLE {$logs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id bigint(20) unsigned NOT NULL,
  level varchar(10) NOT NULL,
  stage varchar(40) NOT NULL DEFAULT '',
  message varchar(500) NOT NULL,
  context longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY job (job_id,id)
) {$charset_collate};",
			$pclaim => "CREATE TABLE {$pclaim} (
  claim_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entity_id bigint(20) unsigned NOT NULL,
  entity_type varchar(20) NOT NULL,
  status varchar(20) NOT NULL,
  claimant_name varchar(200) NOT NULL DEFAULT '',
  claimant_email varchar(254) NOT NULL DEFAULT '',
  claimant_phone varchar(40) NOT NULL DEFAULT '',
  claimant_role varchar(32) NOT NULL DEFAULT '',
  bar_state char(2) NOT NULL DEFAULT '',
  bar_number varchar(40) NOT NULL DEFAULT '',
  message text NULL,
  email_token_hash char(64) DEFAULT NULL,
  email_token_expires datetime DEFAULT NULL,
  email_verified_at datetime DEFAULT NULL,
  identity_method varchar(32) NOT NULL DEFAULT '',
  review_note varchar(1000) NOT NULL DEFAULT '',
  reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  reviewed_at datetime DEFAULT NULL,
  personal_data_purged tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (claim_id),
  UNIQUE KEY email_token_hash (email_token_hash),
  KEY entity (entity_id,status),
  KEY status (status,updated_at),
  KEY claimant_email (claimant_email(100),created_at)
) {$charset_collate};",
			$place  => "CREATE TABLE {$place} (
  placement_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entity_id bigint(20) unsigned NOT NULL,
  entity_type varchar(20) NOT NULL,
  product varchar(20) NOT NULL,
  ranking_id bigint(20) unsigned NOT NULL DEFAULT 0,
  location_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
  practice_area_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
  starts_at datetime NOT NULL,
  ends_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  premium_message text NULL,
  cta_url varchar(2048) NOT NULL DEFAULT '',
  order_ref varchar(100) NOT NULL DEFAULT '',
  notes text NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (placement_id),
  KEY entity (entity_id,product),
  KEY product_window (product,status,starts_at,ends_at),
  KEY ranking_id (ranking_id)
) {$charset_collate};",
		);
	}
}
