<?php
/**
 * Profile claim storage.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Commercial;

use LexRanked\Core\Database\Schema;

/**
 * Reads/writes {prefix}lr_profile_claims. Private data (claimant contact
 * details): never exposed by the public API, only to claim reviewers.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; the table name is internal and values are prepared.
 */
final class ProfileClaimRepository {

	/**
	 * Table name.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::PROFILE_CLAIMS;
	}

	/**
	 * Insert a new claim.
	 *
	 * @param array<string, mixed> $row Validated ClaimRequest output plus token fields and status.
	 * @return int Claim ID.
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
			throw new \RuntimeException( 'Could not store the claim.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update columns.
	 *
	 * @param int                  $id   Claim ID.
	 * @param array<string, mixed> $data Columns.
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( $this->table(), $data + array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'claim_id' => $id ) );
	}

	/**
	 * Find by ID.
	 *
	 * @param int $id Claim ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE claim_id = %d", $id ), ARRAY_A );
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
	 * The approved claim of an entity.
	 *
	 * @param int $entity_id Entity ID.
	 * @return array<string, mixed>|null
	 */
	public function approved_for( int $entity_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE entity_id = %d AND status = %s ORDER BY claim_id DESC LIMIT 1", $entity_id, ClaimStatus::Approved->value ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Entity IDs with an approved claim, among the given IDs.
	 *
	 * @param array<int, int> $entity_ids Entity IDs.
	 * @return array<int, true>
	 */
	public function claimed_among( array $entity_ids ): array {
		$entity_ids = array_values( array_unique( array_map( 'intval', $entity_ids ) ) );
		if ( array() === $entity_ids ) {
			return array();
		}
		global $wpdb;
		$in  = implode( ',', array_fill( 0, count( $entity_ids ), '%d' ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT entity_id FROM {$this->table()} WHERE status = %s AND entity_id IN ({$in})", array_merge( array( ClaimStatus::Approved->value ), $entity_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
		return array_fill_keys( array_map( 'intval', (array) $ids ), true );
	}

	/**
	 * An open (unconfirmed, in review or approved) claim by this email for this entity.
	 *
	 * @param int    $entity_id Entity ID.
	 * @param string $email     Email.
	 * @return array<string, mixed>|null
	 */
	public function open_for( int $entity_id, string $email ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE entity_id = %d AND claimant_email = %s AND status IN (%s, %s, %s) ORDER BY claim_id DESC LIMIT 1", $entity_id, $email, ClaimStatus::PendingEmail->value, ClaimStatus::PendingReview->value, ClaimStatus::Approved->value ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Claims created since a time, by email or by entity.
	 *
	 * @param string $column claimant_email|entity_id.
	 * @param mixed  $value  Value.
	 * @param string $since  UTC datetime.
	 */
	public function count_since( string $column, mixed $value, string $since ): int {
		global $wpdb;
		$column = 'entity_id' === $column ? 'entity_id' : 'claimant_email';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE {$column} = %s AND created_at >= %s", (string) $value, $since ) );
	}

	/**
	 * List claims.
	 *
	 * @param string|null $status Filter.
	 * @param int         $limit  Max rows.
	 * @param int         $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( ?string $status, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$rows = null === $status
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY claim_id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = %s ORDER BY claim_id DESC LIMIT %d OFFSET %d", $status, $limit, $offset ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Counts per status.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		global $wpdb;
		$out = array_fill_keys( ClaimStatus::values(), 0 );
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status", ARRAY_A ) as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Creation time of the oldest claim waiting for review.
	 */
	public function oldest_in_review(): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(updated_at) FROM {$this->table()} WHERE status = %s", ClaimStatus::PendingReview->value ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Expire unconfirmed claims whose link has run out.
	 *
	 * @param string $now UTC datetime.
	 * @return int Rows expired.
	 */
	public function expire_unconfirmed( string $now ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = %s, email_token_hash = NULL, updated_at = %s WHERE status = %s AND email_token_expires < %s", ClaimStatus::Expired->value, $now, ClaimStatus::PendingEmail->value, $now ) );
	}

	/**
	 * Erase the claimant's personal data from closed (rejected / expired) claims.
	 *
	 * @param string $before Closed before this UTC datetime.
	 * @return int Rows purged.
	 */
	public function purge_closed( string $before ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET claimant_name = '', claimant_email = '', claimant_phone = '', bar_number = '', message = NULL, email_token_hash = NULL, personal_data_purged = 1 WHERE status IN (%s, %s) AND personal_data_purged = 0 AND updated_at < %s", ClaimStatus::Rejected->value, ClaimStatus::Expired->value, $before ) );
	}

	/**
	 * Delete all claims of the given entities (demo purge, entity deletion).
	 *
	 * @param array<int, int> $entity_ids Entity IDs.
	 */
	public function delete_for( array $entity_ids ): void {
		global $wpdb;
		foreach ( array_map( 'intval', $entity_ids ) as $id ) {
			$wpdb->delete( $this->table(), array( 'entity_id' => $id ), array( '%d' ) );
		}
	}
}
