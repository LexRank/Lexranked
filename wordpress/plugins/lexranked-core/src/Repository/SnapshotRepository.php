<?php
/**
 * Ranking snapshot storage.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Repository;

use LexRanked\Core\Database\Schema;

/**
 * Append-only rows in {prefix}lr_ranking_snapshots.
 *
 * Every calculation writes one row per entity with its position, score,
 * version, per-component breakdown and the exact inputs used, so any past
 * result can be explained and reproduced. ranking_id 0 holds entity-level
 * scores (the entity's own context).
 */
final class SnapshotRepository {

	/**
	 * Table name.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::SNAPSHOTS;
	}

	/**
	 * Insert a whole run atomically (all rows or none).
	 *
	 * @param array<int, array<string, mixed>> $rows Rows (run_id, ranking_id, entity_id, entity_type, position, score, score_version, context, components, inputs, calculated_at).
	 * @throws \RuntimeException When an insert fails.
	 */
	public function insert_run( array $rows ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom append-only table.
		$wpdb->query( 'START TRANSACTION' );
		foreach ( $rows as $row ) {
			$ok = $wpdb->insert(
				$this->table(),
				array(
					'run_id'        => $row['run_id'],
					'ranking_id'    => $row['ranking_id'],
					'entity_id'     => $row['entity_id'],
					'entity_type'   => $row['entity_type'],
					'position'      => $row['position'],
					'score'         => $row['score'],
					'score_version' => $row['score_version'],
					'context'       => wp_json_encode( $row['context'] ),
					'components'    => wp_json_encode( $row['components'] ),
					'inputs'        => wp_json_encode( $row['inputs'] ),
					'calculated_at' => $row['calculated_at'],
				),
				array( '%s', '%d', '%d', '%s', '%d', '%f', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $ok ) {
				$wpdb->query( 'ROLLBACK' );
				throw new \RuntimeException( 'Could not store ranking snapshot.' );
			}
		}//end foreach
		$wpdb->query( 'COMMIT' );
		// phpcs:enable
	}

	/**
	 * Most recent run IDs for a ranking, newest first.
	 *
	 * @param int $ranking_id Ranking ID (0 = entity level).
	 * @param int $limit      Max runs.
	 * @return array<int, string>
	 */
	public function run_ids( int $ranking_id, int $limit = 1 ): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT run_id FROM {$table} WHERE ranking_id = %d GROUP BY run_id ORDER BY MAX(snapshot_id) DESC LIMIT %d", $ranking_id, max( 1, $limit ) ) );
		return array_map( 'strval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Rows of one run, by position.
	 *
	 * @param string $run_id Run ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function run_rows( string $run_id ): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s ORDER BY position ASC, entity_id ASC", $run_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Latest entity-level snapshot for an entity.
	 *
	 * @param int $entity_id Entity ID.
	 * @return array<string, mixed>|null
	 */
	public function latest_for_entity( int $entity_id ): ?array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE entity_id = %d AND ranking_id = 0 ORDER BY snapshot_id DESC LIMIT 1", $entity_id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Positions of an entity in the latest run of every ranking.
	 *
	 * @param int $entity_id Entity ID.
	 * @return array<int, array<string, mixed>> Rows keyed by nothing, newest ranking runs only.
	 */
	public function latest_positions_for_entity( int $entity_id ): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.* FROM {$table} s
				 INNER JOIN (
				   SELECT ranking_id, run_id FROM {$table}
				   WHERE snapshot_id IN (SELECT MAX(snapshot_id) FROM {$table} WHERE ranking_id > 0 GROUP BY ranking_id)
				 ) latest ON latest.run_id = s.run_id
				 WHERE s.entity_id = %d
				 ORDER BY s.position ASC",
				$entity_id
			),
			ARRAY_A
		);
		// phpcs:enable
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Delete snapshots for entities (demo purge).
	 *
	 * @param int $entity_id Entity or ranking ID.
	 */
	public function delete_for( int $entity_id ): void {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE entity_id = %d OR ranking_id = %d", $entity_id, $entity_id ) );
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, mixed> $row DB row.
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row ): array {
		return array(
			'snapshot_id'   => (int) $row['snapshot_id'],
			'run_id'        => (string) $row['run_id'],
			'ranking_id'    => (int) $row['ranking_id'],
			'entity_id'     => (int) $row['entity_id'],
			'entity_type'   => (string) $row['entity_type'],
			'position'      => (int) $row['position'],
			'score'         => (float) $row['score'],
			'score_version' => (string) $row['score_version'],
			'context'       => (array) json_decode( (string) $row['context'], true ),
			'components'    => (array) json_decode( (string) $row['components'], true ),
			'inputs'        => (array) json_decode( (string) $row['inputs'], true ),
			'calculated_at' => str_replace( ' ', 'T', (string) $row['calculated_at'] ) . 'Z',
		);
	}
}
