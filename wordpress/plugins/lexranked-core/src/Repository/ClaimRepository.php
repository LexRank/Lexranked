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

	/**
	 * Validate and insert a claim.
	 *
	 * @param array<string, mixed> $claim Raw claim.
	 * @return int Claim ID.
	 * @throws \RuntimeException When the insert fails (ValidationException, a subclass of
	 *                           InvalidArgumentException, propagates from the validator).
	 */
	public function insert( array $claim ): int {
		global $wpdb;
		$row               = $this->validator->validate( $claim );
		$row['created_at'] = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$ok = $wpdb->insert( $this->table(), $row, array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%f', '%s', '%s' ) );
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not store claim.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Claims for one entity, newest first.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_entity( string $entity_type, int $entity_id ): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE entity_type = %s AND entity_id = %d ORDER BY field_name ASC, retrieved_at DESC, claim_id DESC LIMIT 500", $entity_type, $entity_id ), ARRAY_A );
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
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source_id FROM {$table} WHERE entity_id = %d AND source_id IS NOT NULL", $entity_id ) );
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
		);
	}
}
