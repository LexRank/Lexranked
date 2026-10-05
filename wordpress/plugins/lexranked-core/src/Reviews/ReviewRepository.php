<?php
/**
 * Client reviews table.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Reviews;

use LexRanked\Core\Database\Schema;

/**
 * Storage for client reviews. The reviewer's email is kept only until the
 * review is moderated (for confirmation and rate limits use the hash).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; the table name is internal and values are prepared.
 */
final class ReviewRepository {

	/**
	 * Table name.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::CLIENT_REVIEWS;
	}

	/**
	 * Insert a review.
	 *
	 * @param array<string, mixed> $row Columns.
	 * @return int Review ID.
	 * @throws \RuntimeException When the insert fails.
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			$this->table(),
			$row + array(
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not store the review.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update columns.
	 *
	 * @param int                  $id   Review ID.
	 * @param array<string, mixed> $data Columns.
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( $this->table(), $data + array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'review_id' => $id ) );
	}

	/**
	 * Find by ID.
	 *
	 * @param int $id Review ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE review_id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find by email-token hash.
	 *
	 * @param string $hash SHA-256 hex of the token.
	 * @return array<string, mixed>|null
	 */
	public function find_by_token_hash( string $hash ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE email_token_hash = %s", $hash ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The open or approved review of one reviewer for one profile, if any.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Profile post ID.
	 * @param string $email_hash  SHA-256 of the reviewer's email.
	 * @return array<string, mixed>|null
	 */
	public function existing( string $entity_type, int $entity_id, string $email_hash ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE entity_type = %s AND entity_id = %d AND email_hash = %s AND status <> %s ORDER BY review_id DESC LIMIT 1", $entity_type, $entity_id, $email_hash, ReviewStatus::Rejected->value ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Submissions since a time, by email hash or by profile.
	 *
	 * @param string $column email_hash|entity_id.
	 * @param mixed  $value  Value.
	 * @param string $since  Y-m-d H:i:s.
	 */
	public function count_since( string $column, mixed $value, string $since ): int {
		global $wpdb;
		$column = 'entity_id' === $column ? 'entity_id' : 'email_hash';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE {$column} = %s AND created_at >= %s", (string) $value, $since ) );
	}

	/**
	 * Delete reviews whose email was never confirmed (their email address with them).
	 *
	 * @param string $before Created before this UTC datetime (Y-m-d H:i:s).
	 * @return int Rows deleted.
	 */
	public function delete_unconfirmed( string $before ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE status = %s AND created_at < %s", ReviewStatus::PendingEmail->value, $before ) );
	}

	/**
	 * Reviews of one profile with a status, newest first.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Profile post ID.
	 * @param string $status      Status.
	 * @param int    $limit       Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_entity( string $entity_type, int $entity_id, string $status, int $limit = 500 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE entity_type = %s AND entity_id = %d AND status = %s ORDER BY approved_at DESC, review_id DESC LIMIT %d", $entity_type, $entity_id, $status, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Reviews with a status across all profiles, oldest first (moderation queue).
	 *
	 * @param string $status Status.
	 * @param int    $limit  Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function by_status( string $status, int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = %s ORDER BY review_id ASC LIMIT %d", $status, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Profiles with at least one approved review.
	 *
	 * @return array<int, array{entity_type: string, entity_id: int}>
	 */
	public function reviewed_entities(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT entity_type, entity_id FROM {$this->table()} WHERE status = %s", ReviewStatus::Approved->value ), ARRAY_A );
		return array_map(
			static fn( array $r ): array => array(
				'entity_type' => (string) $r['entity_type'],
				'entity_id'   => (int) $r['entity_id'],
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Count by status.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		global $wpdb;
		$out  = array_fill_keys( array_map( static fn( ReviewStatus $s ): string => $s->value, ReviewStatus::cases() ), 0 );
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status", ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}
}
