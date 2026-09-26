-- Reference DDL for LexRanked custom tables (MySQL/MariaDB, prefix wp_).
-- Source of truth: wordpress/plugins/lexranked-core/src/Database/Schema.php (applied with dbDelta).
-- Schema version: 5

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
