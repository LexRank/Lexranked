-- Reference DDL for LexRanked custom tables (MySQL/MariaDB, prefix wp_).
-- Source of truth: wordpress/plugins/lexranked-core/src/Database/Schema.php (applied with dbDelta).
-- Schema version: 2

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
  created_at datetime NOT NULL,
  PRIMARY KEY  (claim_id),
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

