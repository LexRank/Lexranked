-- Reference DDL for LexRanked custom tables (MySQL/MariaDB, prefix wp_).
-- Source of truth: wordpress/plugins/lexranked-core/src/Database/Schema.php (applied with dbDelta).
-- Schema version: 7

CREATE TABLE wp_lr_claims (
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wp_lr_audit_log (
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wp_lr_ranking_snapshots (
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wp_lr_candidates (
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wp_lr_research_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id bigint(20) unsigned NOT NULL,
  level varchar(10) NOT NULL,
  stage varchar(40) NOT NULL DEFAULT '',
  message varchar(500) NOT NULL,
  context longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY job (job_id,id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wp_lr_profile_claims (
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wp_lr_placements (
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
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wp_lr_entities (
  entity_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entity_type varchar(32) NOT NULL,
  canonical_name varchar(255) NOT NULL,
  slug varchar(200) NOT NULL,
  status varchar(20) NOT NULL,
  wp_object varchar(10) NOT NULL,
  wp_id bigint(20) unsigned NOT NULL,
  merged_into bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (entity_id),
  UNIQUE KEY wp_ref (wp_object,wp_id),
  KEY type_slug (entity_type,slug),
  KEY type_status (entity_type,status)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wp_lr_entity_aliases (
  alias_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entity_id bigint(20) unsigned NOT NULL,
  entity_type varchar(32) NOT NULL,
  alias_type varchar(10) NOT NULL,
  value varchar(255) NOT NULL,
  normalized varchar(255) NOT NULL,
  is_current tinyint(1) NOT NULL DEFAULT 1,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (alias_id),
  UNIQUE KEY entity_alias (entity_id,alias_type,normalized(150)),
  KEY lookup (entity_type,alias_type,normalized(150))
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
