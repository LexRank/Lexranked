<?php
/**
 * Candidate storage.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

use LexRanked\Core\Database\Schema;

/**
 * Reads/writes {prefix}lr_candidates. Internal research data: never exposed
 * by the public API.
 */
final class CandidateRepository {

	public const STATUS_NEW          = 'new';
	public const STATUS_MATCHED      = 'matched';
	public const STATUS_CREATED      = 'created';
	public const STATUS_NEEDS_REVIEW = 'needs_review';
	public const STATUS_REJECTED     = 'rejected';

	public const STATUSES = array( self::STATUS_NEW, self::STATUS_MATCHED, self::STATUS_CREATED, self::STATUS_NEEDS_REVIEW, self::STATUS_REJECTED );

	/**
	 * Table name.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::CANDIDATE;
	}

	/**
	 * Insert a validated candidate unless its dedupe key exists.
	 *
	 * @param array<string, mixed> $row    Output of CandidateInput::validate().
	 * @param int                  $job_id Job ID.
	 * @return array{candidate: array<string, mixed>, created: bool}
	 * @throws \RuntimeException When the insert fails.
	 */
	public function upsert( array $row, int $job_id ): array {
		$existing = $this->find_by_key( (string) $row['dedupe_key'] );
		if ( null !== $existing ) {
			return array(
				'candidate' => $existing,
				'created'   => false,
			);
		}
		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'dedupe_key'      => $row['dedupe_key'],
			'job_id'          => $job_id,
			'entity_type'     => $row['entity_type'],
			'name'            => $row['name'],
			'normalized_name' => $row['normalized_name'],
			'city'            => $row['city'],
			'state'           => $row['state'],
			'practice_area'   => $row['practice_area'],
			'website'         => $row['website'],
			'source_url'      => $row['source_url'],
			'status'          => self::STATUS_NEW,
			'payload'         => (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-independent by design.
				array(
					'source_type' => $row['source_type'],
					'identifiers' => $row['identifiers'] ?? array(),
					'extra'       => null === $row['payload'] ? null : json_decode( (string) $row['payload'], true ),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			),
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$ok = $wpdb->insert( $this->table(), $data );
		if ( false === $ok ) {
			// A concurrent worker may have inserted the same key between the check and the insert.
			$existing = $this->find_by_key( (string) $row['dedupe_key'] );
			if ( null !== $existing ) {
				return array(
					'candidate' => $existing,
					'created'   => false,
				);
			}
			throw new \RuntimeException( 'Could not store candidate.' );
		}
		return array(
			'candidate' => (array) $this->find( (int) $wpdb->insert_id ),
			'created'   => true,
		);
	}

	/**
	 * By ID.
	 *
	 * @param int $candidate_id Candidate ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $candidate_id ): ?array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE candidate_id = %d", $candidate_id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * By dedupe key.
	 *
	 * @param string $key Dedupe key.
	 * @return array<string, mixed>|null
	 */
	public function find_by_key( string $key ): ?array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE dedupe_key = %s", $key ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * List candidates.
	 *
	 * @param string|null $status Filter by status.
	 * @param int         $limit  Max rows.
	 * @param int         $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( ?string $status, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = $this->table();
		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY candidate_id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY candidate_id ASC LIMIT %d OFFSET %d", $status, $limit, $offset ), ARRAY_A );
		}
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Candidate count per status.
	 *
	 * @param int|null $job_id Restrict to one job.
	 * @return array<string, int>
	 */
	public function counts( ?int $job_id = null ): array {
		global $wpdb;
		$table = $this->table();
		if ( null === $job_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, no user input.
			$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE job_id = %d GROUP BY status", $job_id ), ARRAY_A );
		}
		$out = array_fill_keys( self::STATUSES, 0 );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Record a resolution.
	 *
	 * @param int        $candidate_id Candidate ID.
	 * @param string     $status       New status.
	 * @param int|null   $entity_id    Linked entity.
	 * @param float|null $confidence   Match confidence.
	 * @param string     $reason       Reason.
	 * @throws \InvalidArgumentException When the status is unknown.
	 */
	public function resolve( int $candidate_id, string $status, ?int $entity_id, ?float $confidence, string $reason ): void {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new \InvalidArgumentException( 'Invalid candidate status.' );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			$this->table(),
			array(
				'status'           => $status,
				'entity_id'        => $entity_id,
				'match_confidence' => $confidence,
				'reason'           => mb_substr( $reason, 0, 500 ),
				'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'candidate_id' => $candidate_id ),
			array( '%s', '%d', '%f', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Candidates awaiting a decision with an ID above $after (AI review pages).
	 *
	 * @param int $after Last candidate ID seen.
	 * @param int $limit Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function needs_review_after( int $after, int $limit ): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND candidate_id > %d ORDER BY candidate_id ASC LIMIT %d", self::STATUS_NEEDS_REVIEW, $after, $limit ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Store an advisory AI note (never changes the status).
	 *
	 * @param int                  $candidate_id Candidate ID.
	 * @param array<string, mixed> $note         Note.
	 */
	public function set_ai_note( int $candidate_id, array $note ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			$this->table(),
			array(
				'ai_note'    => (string) json_encode( $note, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-independent by design.
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'candidate_id' => $candidate_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, mixed> $row DB row.
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row ): array {
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		return array(
			'id'              => (int) $row['candidate_id'],
			'jobId'           => (int) $row['job_id'],
			'entityType'      => (string) $row['entity_type'],
			'name'            => (string) $row['name'],
			'normalizedName'  => (string) $row['normalized_name'],
			'city'            => null === $row['city'] ? null : (string) $row['city'],
			'state'           => null === $row['state'] ? null : (string) $row['state'],
			'practiceArea'    => null === $row['practice_area'] ? null : (string) $row['practice_area'],
			'website'         => null === $row['website'] ? null : (string) $row['website'],
			'sourceUrl'       => (string) $row['source_url'],
			'sourceType'      => is_array( $payload ) ? (string) ( $payload['source_type'] ?? '' ) : '',
			'identifiers'     => is_array( $payload ) && is_array( $payload['identifiers'] ?? null ) ? $payload['identifiers'] : array(),
			'status'          => (string) $row['status'],
			'entityId'        => null === $row['entity_id'] ? null : (int) $row['entity_id'],
			'matchConfidence' => null === $row['match_confidence'] ? null : (float) $row['match_confidence'],
			'reason'          => null === $row['reason'] ? null : (string) $row['reason'],
			'aiNote'          => empty( $row['ai_note'] ) ? null : json_decode( (string) $row['ai_note'], true ),
			'createdAt'       => str_replace( ' ', 'T', (string) $row['created_at'] ) . 'Z',
			'updatedAt'       => str_replace( ' ', 'T', (string) $row['updated_at'] ) . 'Z',
		);
	}
}
