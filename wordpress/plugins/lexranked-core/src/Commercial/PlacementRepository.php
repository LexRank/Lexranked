<?php
/**
 * Placement storage.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Commercial;

use LexRanked\Core\Database\Schema;

/**
 * Reads/writes {prefix}lr_placements. Order references and notes are private.
 * This table is commercial data: the ranking engine never reads it.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; the table name is internal and values are prepared.
 */
final class PlacementRepository {

	/**
	 * Table name.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::PLACEMENTS;
	}

	/**
	 * Insert.
	 *
	 * @param array<string, mixed> $row     PlacementPolicy::validate() output.
	 * @param int                  $user_id Creating user.
	 * @return int Placement ID.
	 * @throws \RuntimeException When the insert fails.
	 */
	public function insert( array $row, int $user_id ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			$this->table(),
			$row + array(
				'created_by' => $user_id,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not store the placement.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update.
	 *
	 * @param int                  $id   Placement ID.
	 * @param array<string, mixed> $data Columns.
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( $this->table(), $data + array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'placement_id' => $id ) );
	}

	/**
	 * Find.
	 *
	 * @param int $id Placement ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE placement_id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Active placements of a product that have not ended.
	 *
	 * @param string $product Product.
	 * @param string $now     UTC datetime.
	 * @return array<int, array<string, mixed>>
	 */
	public function current( string $product, string $now ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE product = %s AND status = 'active' AND ends_at > %s ORDER BY starts_at, placement_id", $product, $now ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Placements of an entity.
	 *
	 * @param int $entity_id Entity ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_entity( int $entity_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE entity_id = %d ORDER BY placement_id DESC", $entity_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List.
	 *
	 * @param bool $include_ended Include ended and cancelled placements.
	 * @param int  $limit         Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( bool $include_ended, int $limit = 200 ): array {
		global $wpdb;
		$rows = $include_ended
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY placement_id DESC LIMIT %d", $limit ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status <> 'cancelled' AND ends_at > %s ORDER BY placement_id DESC LIMIT %d", gmdate( 'Y-m-d H:i:s' ), $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Entity IDs that have any placement.
	 *
	 * @return array<int, int>
	 */
	public function entity_ids(): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT entity_id FROM {$this->table()}" ) );
	}

	/**
	 * Delete all placements of the given entities.
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
