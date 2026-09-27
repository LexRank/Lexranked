<?php
/**
 * Data Quality storage and triggers.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Quality;

use LexRanked\Core\Database\Schema;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\VerificationRecord;
use LexRanked\Core\Ranking\RankingRunner;
use LexRanked\Core\Services;

/**
 * Computes the Data Quality Score of lawyers and firms and stores it on the
 * entity registry (lr_entities.quality_*). Recomputed when facts, the profile
 * or its verification records change, and daily (freshness decays with time).
 * The ranking engine never reads it.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; name internal, values prepared.
 */
final class QualityService {

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
		add_action( 'lexranked_facts_changed', array( $this, 'compute' ), 10, 2 );
		add_action( 'save_post_' . Lawyer::SLUG, array( $this, 'on_profile' ), 40, 2 );
		add_action( 'save_post_' . LawFirm::SLUG, array( $this, 'on_profile' ), 40, 2 );
		add_action( 'save_post_' . VerificationRecord::SLUG, array( $this, 'on_verification' ), 40 );
		add_action( RankingRunner::CRON_HOOK, array( $this, 'compute_all' ), 5 );
	}

	/**
	 * Profile saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function on_profile( int $post_id, \WP_Post $post ): void {
		if ( ! wp_is_post_revision( $post_id ) ) {
			$this->compute( Lawyer::SLUG === $post->post_type ? 'lawyer' : 'law_firm', $post_id );
		}
	}

	/**
	 * Verification record saved.
	 *
	 * @param int $post_id Verification post ID.
	 */
	public function on_verification( int $post_id ): void {
		$entity = get_post( (int) get_post_meta( $post_id, '_lr_entity_id', true ) );
		if ( $entity instanceof \WP_Post && in_array( $entity->post_type, array( Lawyer::SLUG, LawFirm::SLUG ), true ) ) {
			$this->on_profile( $entity->ID, $entity );
		}
	}

	/**
	 * Compute and store one entity's score.
	 *
	 * @param string $entity_type lawyer|law_firm.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>|null Result, or null for unknown entities.
	 */
	public function compute( string $entity_type, int $wp_id ): ?array {
		global $wpdb;
		$s    = $this->services;
		$post = get_post( $wp_id );
		if ( ! $post instanceof \WP_Post || ! in_array( $entity_type, array( 'lawyer', 'law_firm' ), true ) ) {
			return null;
		}
		$type   = 'law_firm' === $entity_type ? $s->law_firm : $s->lawyer;
		$record = $s->entities->record( $post, $type );
		$now    = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$policy = $s->settings->verification_policy( $entity_type );
		$checks = $policy->evaluate( $s->verifications->for_entities( array( $wp_id ) )[ $wp_id ] ?? array(), $now )['types'];
		$result = DataQuality::evaluate(
			$entity_type,
			$s->facts->for_entity( $entity_type, $wp_id ),
			self::profile_keys( $record ),
			$checks,
			(array) ( $s->settings->get( 'required_verifications' )[ $entity_type ] ?? array() ),
			$s->settings->freshness(),
			$now
		);
		$wpdb->update(
			$wpdb->prefix . Schema::ENTITIES,
			array(
				'quality_score' => $result['score'],
				'quality_json'  => (string) wp_json_encode( $result ),
				'quality_at'    => $now->format( 'Y-m-d H:i:s' ),
			),
			array(
				'wp_object' => 'post',
				'wp_id'     => $wp_id,
			)
		);
		return $result + array( 'calculatedAt' => $now->format( 'Y-m-d\TH:i:s\Z' ) );
	}

	/**
	 * Recompute every lawyer and firm (daily; migration; CLI).
	 *
	 * @return int Entities computed.
	 */
	public function compute_all(): int {
		global $wpdb;
		$table = $wpdb->prefix . Schema::ENTITIES;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT entity_type, wp_id FROM {$table} WHERE wp_object = %s AND status <> %s", 'post', 'merged' ), ARRAY_A );
		$n     = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$n += null === $this->compute( (string) $row['entity_type'], (int) $row['wp_id'] ) ? 0 : 1;
		}
		return $n;
	}

	/**
	 * Stored score of an entity (public DTO shape), or null.
	 *
	 * @param int $wp_id WordPress ID.
	 * @return array<string, mixed>|null
	 */
	public function stored( int $wp_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . Schema::ENTITIES;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT quality_json, quality_at FROM {$table} WHERE wp_object = 'post' AND wp_id = %d", $wp_id ), ARRAY_A );
		if ( ! is_array( $row ) || empty( $row['quality_json'] ) ) {
			return null;
		}
		$data = json_decode( (string) $row['quality_json'], true );
		return is_array( $data ) ? $data + array( 'calculatedAt' => str_replace( ' ', 'T', (string) $row['quality_at'] ) . 'Z' ) : null;
	}

	/**
	 * Evidence coverage per entity: the Data Quality completeness dimension
	 * (share of expected facts backed by a source), 0–1. Used by the page
	 * eligibility engine (Etap G) to decide which pages exist and are
	 * indexed; never by the ranking engine.
	 *
	 * @param array<int, int> $wp_ids Post IDs.
	 * @return array<int, float> wp_id => coverage (entities without a stored score are missing).
	 */
	public function coverage( array $wp_ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $wp_ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$table = $wpdb->prefix . Schema::ENTITIES;
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom table; IN() placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT wp_id, quality_json FROM {$table} WHERE wp_object = 'post' AND wp_id IN ({$in})", $ids ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$data = json_decode( (string) $row['quality_json'], true );
			foreach ( (array) ( $data['dimensions'] ?? array() ) as $dimension ) {
				if ( 'completeness' === ( $dimension['key'] ?? null ) ) {
					$out[ (int) $row['wp_id'] ] = max( 0.0, min( 1.0, (float) $dimension['score'] / 100 ) );
				}
			}
		}
		return $out;
	}

	/**
	 * Distribution for CLI/health: count, average, and bands.
	 *
	 * @return array{count: int, average: float|null, bands: array<string, int>}
	 */
	public function summary(): array {
		global $wpdb;
		$table = $wpdb->prefix . Schema::ENTITIES;
		$row   = $wpdb->get_row( "SELECT COUNT(quality_score) AS n, AVG(quality_score) AS avg_score, SUM(quality_score >= 80) AS high, SUM(quality_score >= 50 AND quality_score < 80) AS mid, SUM(quality_score < 50) AS low FROM {$table} WHERE wp_object = 'post' AND status = 'active'", ARRAY_A );
		return array(
			'count'   => (int) ( $row['n'] ?? 0 ),
			'average' => null === ( $row['avg_score'] ?? null ) ? null : round( (float) $row['avg_score'], 1 ),
			'bands'   => array(
				'80-100' => (int) ( $row['high'] ?? 0 ),
				'50-79'  => (int) ( $row['mid'] ?? 0 ),
				'0-49'   => (int) ( $row['low'] ?? 0 ),
			),
		);
	}

	/**
	 * Attributes that have a value on the profile (sourced or not).
	 *
	 * @param array<string, mixed> $record Entity record.
	 * @return array<int, string>
	 */
	public static function profile_keys( array $record ): array {
		$keys = array();
		foreach ( $record['fields'] as $key => $value ) {
			if ( null !== $value && '' !== $value && array() !== $value ) {
				$keys[] = (string) $key;
			}
		}
		if ( '' !== trim( (string) $record['title'] ) ) {
			$keys[] = 'name';
		}
		foreach ( $record['locations'] as $term ) {
			$keys[] = null === $term['state_code'] ? 'city' : 'state';
		}
		if ( array() !== $record['practice_areas'] ) {
			$keys[] = 'practice_areas';
		}
		return array_values( array_unique( $keys ) );
	}
}
