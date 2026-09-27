<?php
/**
 * Fact layer storage.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Fact;

use LexRanked\Core\Attribute\Attributes;
use LexRanked\Core\Database\Schema;
use LexRanked\Core\Entity\EntityType;
use LexRanked\Core\Services;

/**
 * Keeps lr_facts in step with the evidence: whenever an entity's claims
 * change (new evidence, review decision, refreshed retrieval) its facts are
 * rebuilt once at the end of the request.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; name internal, values prepared.
 */
final class FactService {

	/**
	 * Entities waiting for a rebuild: "type:wp_id" => [type, wp_id].
	 *
	 * @var array<string, array{0: string, 1: int}>
	 */
	private array $pending = array();

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'lexranked_claims_changed', array( $this, 'queue' ), 10, 2 );
	}

	/**
	 * Table.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::FACTS;
	}

	/**
	 * Queue a rebuild.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 */
	public function queue( string $entity_type, int $wp_id ): void {
		if ( array() === $this->pending ) {
			add_action( 'shutdown', array( $this, 'flush' ), 5 );
		}
		$this->pending[ $entity_type . ':' . $wp_id ] = array( $entity_type, $wp_id );
	}

	/**
	 * Rebuild queued entities.
	 */
	public function flush(): void {
		$pending       = $this->pending;
		$this->pending = array();
		foreach ( $pending as [ $type, $wp_id ] ) {
			$this->rebuild( $type, $wp_id );
		}
	}

	/**
	 * Rebuild one entity's facts from its approved claims.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return int Facts stored.
	 */
	public function rebuild( string $entity_type, int $wp_id ): int {
		global $wpdb;
		$type = EntityType::tryFrom( $entity_type );
		$eid  = null === $type ? null : $this->services->registry->id_for( $type, $wp_id );
		if ( null === $eid ) {
			return 0;
		}
		$facts = FactBuilder::build( $this->services->claims->for_entity( $entity_type, $wp_id ), $this->services->settings->source_tiers() );
		$now   = gmdate( 'Y-m-d H:i:s' );
		foreach ( $facts as $fact ) {
			// REPLACE on the (entity, attribute) key; wpdb writes PHP nulls as SQL NULL.
			$wpdb->replace(
				$this->table(),
				array(
					'lr_entity_id' => $eid,
					'entity_type'  => $entity_type,
					'wp_id'        => $wp_id,
					'attribute'    => $fact['attribute'],
					'value'        => (string) json_encode( $fact['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stored as JSON.
					'status'       => $fact['status'],
					'confidence'   => $fact['confidence'],
					'source_tier'  => $fact['source_tier'],
					'claim_id'     => $fact['claim_id'],
					'source_id'    => $fact['source_id'],
					'claim_count'  => $fact['claim_count'],
					'observed_at'  => $fact['observed_at'],
					'verified_at'  => $fact['verified_at'],
					'computed_at'  => $now,
				)
			);
		}//end foreach
		// Attributes that lost all their evidence disappear from the fact layer.
		$keep = array_keys( $facts );
		if ( array() === $keep ) {
			$wpdb->delete( $this->table(), array( 'lr_entity_id' => $eid ), array( '%d' ) );
		} else {
			$in = implode( ',', array_fill( 0, count( $keep ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE lr_entity_id = %d AND attribute NOT IN ({$in})", array_merge( array( $eid ), $keep ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders built above.
		}
		return count( $facts );
	}

	/**
	 * Rebuild every lawyer and firm (migration, CLI).
	 *
	 * @return int Entities processed.
	 */
	public function rebuild_all(): int {
		global $wpdb;
		$entities = $wpdb->prefix . Schema::ENTITIES;
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT entity_type, wp_id FROM {$entities} WHERE wp_object = %s", 'post' ), ARRAY_A );
		$n        = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$this->rebuild( (string) $row['entity_type'], (int) $row['wp_id'] );
			++$n;
		}
		return $n;
	}

	/**
	 * Facts of an entity, keyed by attribute (decoded).
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, array<string, mixed>>
	 */
	public function for_entity( string $entity_type, int $wp_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE entity_type = %s AND wp_id = %d ORDER BY attribute ASC", $entity_type, $wp_id ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row['value']             = json_decode( (string) $row['value'], true );
			$out[ $row['attribute'] ] = $row;
		}
		return $out;
	}

	/**
	 * Coverage figures for the whole fact layer (health, docs).
	 *
	 * @return array{facts: int, verified: int, conflicts: int, attributes: int}
	 */
	public function summary(): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS facts, SUM(status = %s) AS verified, SUM(status = %s) AS conflicts, COUNT(DISTINCT attribute) AS attributes FROM {$this->table()}", FactBuilder::VERIFIED, FactBuilder::CONFLICT ), ARRAY_A );
		return array(
			'facts'      => (int) ( $row['facts'] ?? 0 ),
			'verified'   => (int) ( $row['verified'] ?? 0 ),
			'conflicts'  => (int) ( $row['conflicts'] ?? 0 ),
			'attributes' => max( 0, min( count( Attributes::facts() ), (int) ( $row['attributes'] ?? 0 ) ) ),
		);
	}
}
