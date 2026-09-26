<?php
/**
 * Evidence claim storage.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Repository;

use LexRanked\Core\Database\Schema;
use LexRanked\Core\Sources\ClaimValidator;

/**
 * Reads/writes rows in {prefix}lr_claims.
 */
final class ClaimRepository {

	/**
	 * Constructor.
	 *
	 * @param ClaimValidator $validator Claim validator.
	 */
	public function __construct( private readonly ClaimValidator $validator ) {
	}

	/**
	 * Table name.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::CLAIMS;
	}

	/** Claim is part of the public record. */
	public const REVIEW_APPROVED = 'approved';

	/** Claim came from research for a published entity and awaits an editor. */
	public const REVIEW_PENDING = 'pending_review';

	/** Editor rejected the claim; kept for audit, never shown. */
	public const REVIEW_REJECTED = 'rejected';

	public const REVIEW_STATUSES = array( self::REVIEW_APPROVED, self::REVIEW_PENDING, self::REVIEW_REJECTED );

	/**
	 * How a claim was obtained: typed by an editor, read by a person into a
	 * seed dataset, parsed from schema.org structured data, or extracted by an
	 * AI model from a source document (quote-checked, low confidence).
	 */
	public const METHODS = array( 'manual', 'seed', 'structured_data', 'ai' );

	/**
	 * Validate and insert a claim (editor/seed path: approved immediately).
	 *
	 * @param array<string, mixed> $claim Raw claim.
	 * @return int Claim ID.
	 * @throws \RuntimeException When the insert fails (ValidationException, a subclass of
	 *                           InvalidArgumentException, propagates from the validator).
	 */
	public function insert( array $claim ): int {
		return $this->insert_unique( $claim )['claim_id'];
	}

	/**
	 * Validate and insert a claim unless an identical one (same entity, field,
	 * value and source) already exists. A duplicate only refreshes retrieved_at
	 * when the new retrieval is newer, so re-running a research job is idempotent.
	 *
	 * @param array<string, mixed> $claim         Raw claim.
	 * @param int                  $job_id        Research job that produced it (0 = editor/seed).
	 * @param string               $review_status One of REVIEW_STATUSES.
	 * @param string               $method        One of METHODS.
	 * @return array{claim_id: int, duplicate: bool, row: array<string, mixed>}
	 * @throws \RuntimeException When the insert fails.
	 */
	public function insert_unique( array $claim, int $job_id = 0, string $review_status = self::REVIEW_APPROVED, string $method = 'manual' ): array {
		global $wpdb;
		$row   = $this->validator->validate( $claim );
		$hash  = self::hash( $row );
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT claim_id, retrieved_at FROM {$table} WHERE claim_hash = %s", $hash ), ARRAY_A );
		if ( is_array( $existing ) ) {
			if ( $row['retrieved_at'] > (string) $existing['retrieved_at'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
				$wpdb->update( $table, array( 'retrieved_at' => $row['retrieved_at'] ), array( 'claim_id' => (int) $existing['claim_id'] ), array( '%s' ), array( '%d' ) );
			}
			return array(
				'claim_id'  => (int) $existing['claim_id'],
				'duplicate' => true,
				'row'       => $row,
			);
		}

		$row['claim_hash']    = $hash;
		$row['job_id']        = $job_id;
		$row['review_status'] = in_array( $review_status, self::REVIEW_STATUSES, true ) ? $review_status : self::REVIEW_PENDING;
		$row['method']        = in_array( $method, self::METHODS, true ) ? $method : 'manual';
		$row['created_at']    = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$ok = $wpdb->insert( $table, $row, array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%s', '%s', '%s' ) );
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not store claim.' );
		}
		return array(
			'claim_id'  => (int) $wpdb->insert_id,
			'duplicate' => false,
			'row'       => $row,
		);
	}

	/**
	 * Identity hash of a validated claim row. retrieved_at, confidence and
	 * status are deliberately excluded: the same fact from the same source is
	 * one claim, however often it is re-read.
	 *
	 * @param array<string, mixed> $row Validated row (value JSON-encoded).
	 */
	public static function hash( array $row ): string {
		return sha1(
			implode(
				"\x1f",
				array(
					(string) $row['entity_type'],
					(string) (int) $row['entity_id'],
					(string) $row['field_name'],
					(string) $row['value'],
					(string) ( $row['source_id'] ?? '' ),
					(string) $row['source_url'],
					(string) $row['source_type'],
				)
			)
		);
	}

	/**
	 * Fill claim_hash for rows created before schema v4. Rows that duplicate an
	 * earlier row keep a NULL hash (they stay, but are never matched).
	 *
	 * @return int Rows updated.
	 */
	public function backfill_hashes(): int {
		global $wpdb;
		$table   = $this->table();
		$updated = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, no user input.
			$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE claim_hash IS NULL AND review_status <> 'duplicate' ORDER BY claim_id ASC LIMIT 500", ARRAY_A );
			$rows = is_array( $rows ) ? $rows : array();
			foreach ( $rows as $row ) {
				$hash = self::hash( $row );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
				$taken = $wpdb->get_var( $wpdb->prepare( "SELECT claim_id FROM {$table} WHERE claim_hash = %s", $hash ) );
				$data  = null === $taken ? array( 'claim_hash' => $hash ) : array( 'review_status' => 'duplicate' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
				$wpdb->update( $table, $data, array( 'claim_id' => (int) $row['claim_id'] ), array( '%s' ), array( '%d' ) );
				++$updated;
			}
			$batch = count( $rows );
		} while ( 500 === $batch );
		return $updated;
	}

	/**
	 * Claims awaiting editorial review, oldest first.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function pending_review( int $limit = 200 ): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE review_status = %s ORDER BY claim_id ASC LIMIT %d", self::REVIEW_PENDING, $limit ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One claim.
	 *
	 * @param int $claim_id Claim ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $claim_id ): ?array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE claim_id = %d", $claim_id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Change a claim's review status.
	 *
	 * @param int    $claim_id Claim ID.
	 * @param string $status   One of REVIEW_STATUSES.
	 * @throws \InvalidArgumentException When the status is unknown.
	 */
	public function set_review_status( int $claim_id, string $status ): void {
		global $wpdb;
		if ( ! in_array( $status, self::REVIEW_STATUSES, true ) ) {
			throw new \InvalidArgumentException( 'Invalid review status.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update( $this->table(), array( 'review_status' => $status ), array( 'claim_id' => $claim_id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Claims for one entity, newest first. Only approved claims unless
	 * $include_unreviewed (research resolution, admin review).
	 *
	 * @param string $entity_type        Entity type.
	 * @param int    $entity_id          Entity ID.
	 * @param bool   $include_unreviewed Include pending_review claims (never rejected ones).
	 * @return array<int, array<string, mixed>>
	 */
	public function for_entity( string $entity_type, int $entity_id, bool $include_unreviewed = false ): array {
		global $wpdb;
		$table    = $this->table();
		$statuses = $include_unreviewed ? array( self::REVIEW_APPROVED, self::REVIEW_PENDING ) : array( self::REVIEW_APPROVED );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE entity_type = %s AND entity_id = %d AND review_status IN (%s, %s) ORDER BY field_name ASC, retrieved_at DESC, claim_id DESC LIMIT 500", $entity_type, $entity_id, $statuses[0], $statuses[1] ?? $statuses[0] ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Distinct source IDs referenced by an entity's claims.
	 *
	 * @param int $entity_id Entity ID.
	 * @return array<int, int>
	 */
	public function source_ids_for_entity( int $entity_id ): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source_id FROM {$table} WHERE entity_id = %d AND source_id IS NOT NULL AND review_status = %s", $entity_id, self::REVIEW_APPROVED ) );
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Delete all claims for an entity (used when purging demo data).
	 *
	 * @param int $entity_id Entity ID.
	 */
	public function delete_for_entity( int $entity_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->delete( $this->table(), array( 'entity_id' => $entity_id ), array( '%d' ) );
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, mixed> $row DB row.
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row ): array {
		return array(
			'claim_id'            => (int) $row['claim_id'],
			'entity_id'           => (int) $row['entity_id'],
			'entity_type'         => (string) $row['entity_type'],
			'field_name'          => (string) $row['field_name'],
			'value'               => json_decode( (string) $row['value'], true ),
			'source_id'           => null === $row['source_id'] ? null : (int) $row['source_id'],
			'source_url'          => (string) $row['source_url'],
			'source_type'         => (string) $row['source_type'],
			'retrieved_at'        => str_replace( ' ', 'T', (string) $row['retrieved_at'] ) . 'Z',
			'confidence'          => (float) $row['confidence'],
			'verification_status' => (string) $row['verification_status'],
			'job_id'              => (int) ( $row['job_id'] ?? 0 ),
			'review_status'       => (string) ( $row['review_status'] ?? self::REVIEW_APPROVED ),
			'method'              => (string) ( $row['method'] ?? 'manual' ),
		);
	}
}
