<?php
/**
 * Assembles DTOs from WordPress data.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\Source;
use LexRanked\Core\REST\DTO\EntityMapper;
use LexRanked\Core\REST\DTO\RankingMapper;
use LexRanked\Core\REST\DTO\SourceMapper;
use LexRanked\Core\Services;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Batch-loads related data (firms, verification, sources) and hands plain
 * records to the pure DTO mappers.
 */
final class EntityPresenter {

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Current time (UTC).
	 */
	private function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Lawyer summaries for a list of posts (one verification query for the batch).
	 *
	 * @param array<int, \WP_Post> $posts Lawyer posts.
	 * @return array<int, array<string, mixed>>
	 */
	public function lawyer_summaries( array $posts ): array {
		$s             = $this->services;
		$records       = array_map( fn( \WP_Post $p ): array => $s->entities->record( $p, $s->lawyer ), $posts );
		$firms         = $this->firm_records( array_filter( array_map( static fn( array $r ): ?int => $r['fields']['firm_id'], $records ) ) );
		$verifications = $s->verifications->for_entities( array_column( $records, 'id' ) );
		$policy        = $s->settings->verification_policy( 'lawyer' );
		$now           = $this->now();

		return array_map(
			fn( array $r ): array => EntityMapper::lawyer_summary(
				$r,
				$firms[ $r['fields']['firm_id'] ?? 0 ] ?? null,
				$policy->evaluate( $verifications[ $r['id'] ] ?? array(), $now )
			),
			$records
		);
	}

	/**
	 * Lawyer detail DTO.
	 *
	 * @param \WP_Post $post            Lawyer post.
	 * @param bool     $include_private Include private fields.
	 * @return array<string, mixed>
	 */
	public function lawyer_detail( \WP_Post $post, bool $include_private ): array {
		$s            = $this->services;
		$record       = $s->entities->record( $post, $s->lawyer );
		$firm_id      = $record['fields']['firm_id'];
		$firm         = null !== $firm_id ? ( $this->firm_records( array( $firm_id ) )[ $firm_id ] ?? null ) : null;
		$verification = $s->settings->verification_policy( 'lawyer' )->evaluate( $s->verifications->for_entities( array( $post->ID ) )[ $post->ID ] ?? array(), $this->now() );
		$freshness    = $s->settings->freshness()->evaluate( 'profile', $verification['verified_at'], $this->now() );

		return EntityMapper::lawyer_detail(
			$record,
			$firm,
			$verification,
			$freshness,
			$this->evidence( 'lawyer', $post->ID ),
			(string) wp_kses_post( wpautop( $post->post_content ) ),
			$include_private
		);
	}

	/**
	 * Firm summaries.
	 *
	 * @param array<int, \WP_Post> $posts Firm posts.
	 * @return array<int, array<string, mixed>>
	 */
	public function firm_summaries( array $posts ): array {
		$s             = $this->services;
		$records       = array_map( fn( \WP_Post $p ): array => $s->entities->record( $p, $s->law_firm ), $posts );
		$verifications = $s->verifications->for_entities( array_column( $records, 'id' ) );
		$counts        = $this->lawyer_counts( array_column( $records, 'id' ) );
		$policy        = $s->settings->verification_policy( 'law_firm' );
		$now           = $this->now();

		return array_map(
			fn( array $r ): array => EntityMapper::firm_summary( $r, $policy->evaluate( $verifications[ $r['id'] ] ?? array(), $now ), $counts[ $r['id'] ] ?? 0 ),
			$records
		);
	}

	/**
	 * Firm detail DTO.
	 *
	 * @param \WP_Post $post Firm post.
	 * @return array<string, mixed>
	 */
	public function firm_detail( \WP_Post $post ): array {
		$s            = $this->services;
		$record       = $s->entities->record( $post, $s->law_firm );
		$verification = $s->settings->verification_policy( 'law_firm' )->evaluate( $s->verifications->for_entities( array( $post->ID ) )[ $post->ID ] ?? array(), $this->now() );
		$lawyers      = $this->lawyer_summaries( $this->firm_lawyer_posts( $post->ID ) );

		return EntityMapper::firm_detail(
			$record,
			$verification,
			$lawyers,
			$s->settings->freshness()->evaluate( 'profile', $verification['verified_at'], $this->now() ),
			$this->evidence( 'law_firm', $post->ID ),
			(string) wp_kses_post( wpautop( $post->post_content ) )
		);
	}

	/**
	 * Ordered entries for a ranking record.
	 *
	 * @param array<string, mixed> $record Ranking record.
	 * @return array<int, array<string, mixed>>
	 */
	public function ranking_entries( array $record ): array {
		$practice_ids = array_column( $record['practice_areas'], 'id' );
		if ( array() === $record['locations'] || array() === $practice_ids ) {
			return array();
		}
		// The most specific location assigned to the ranking (city if present).
		$specific = null;
		foreach ( $record['locations'] as $term ) {
			if ( null === $specific || 0 !== $term['parent'] ) {
				$specific = $term;
			}
		}
		$type      = 'law_firm' === $record['fields']['entity_type'] ? LawFirm::SLUG : Lawyer::SLUG;
		$posts     = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'publish',
				'posts_per_page'   => 500,
				'no_found_rows'    => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'tax_query'        => array(
					'relation' => 'AND',
					array(
						'taxonomy' => Location::SLUG,
						'field'    => 'term_id',
						'terms'    => array( $specific['id'] ),
					),
					array(
						'taxonomy' => PracticeArea::SLUG,
						'field'    => 'term_id',
						'terms'    => array( $practice_ids[0] ),
					),
				),
			)
		);
		$summaries = Lawyer::SLUG === $type ? $this->lawyer_summaries( $posts ) : $this->firm_summaries( $posts );
		return RankingMapper::order( $summaries, $record['fields']['score_version'] );
	}

	/**
	 * Evidence DTOs for an entity.
	 *
	 * @param string $entity_type lawyer|law_firm.
	 * @param int    $entity_id   ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function evidence( string $entity_type, int $entity_id ): array {
		$s       = $this->services;
		$claims  = $s->claims->for_entity( $entity_type, $entity_id );
		$tiers   = $s->settings->source_tiers();
		$ids     = array_values( array_unique( array_filter( array_column( $claims, 'source_id' ) ) ) );
		$sources = array();
		if ( array() !== $ids ) {
			foreach ( get_posts(
				array(
					'post_type'        => Source::SLUG,
					'post_status'      => 'publish',
					'post__in'         => $ids,
					'posts_per_page'   => count( $ids ),
					'no_found_rows'    => true,
					'suppress_filters' => false,
				)
			) as $post ) {
				$sources[ $post->ID ] = SourceMapper::source( $s->entities->record( $post, $s->source ), $tiers );
			}
		}
		return SourceMapper::evidence( $claims, $sources, $tiers );
	}

	/**
	 * Published firm records keyed by ID.
	 *
	 * @param array<int, int> $ids Firm IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private function firm_records( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'        => LawFirm::SLUG,
				'post_status'      => 'publish',
				'post__in'         => $ids,
				'posts_per_page'   => count( $ids ),
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		) as $post ) {
			$out[ $post->ID ] = $this->services->entities->record( $post, $this->services->law_firm );
		}
		return $out;
	}

	/**
	 * Published lawyers of a firm, by name.
	 *
	 * @param int $firm_id Firm ID.
	 * @return array<int, \WP_Post>
	 */
	public function firm_lawyer_posts( int $firm_id ): array {
		return get_posts(
			array(
				'post_type'        => Lawyer::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => 200,
				'no_found_rows'    => true,
				'orderby'          => array(
					'title' => 'ASC',
					'ID'    => 'ASC',
				),
				'suppress_filters' => false,
				'meta_query'       => array(
					array(
						'key'   => $this->services->lawyer->field( 'firm_id' )?->meta_key(),
						'value' => $firm_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);
	}

	/**
	 * Published lawyer counts per firm.
	 *
	 * @param array<int, int> $firm_ids Firm IDs.
	 * @return array<int, int>
	 */
	private function lawyer_counts( array $firm_ids ): array {
		if ( array() === $firm_ids ) {
			return array();
		}
		global $wpdb;
		$key          = $this->services->lawyer->field( 'firm_id' )?->meta_key();
		$placeholders = implode( ',', array_fill( 0, count( $firm_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN() placeholders are generated; all values prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS firm_id, COUNT(*) AS n FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value IN ({$placeholders}) GROUP BY pm.meta_value",
				array_merge( array( $key, Lawyer::SLUG ), array_map( 'intval', $firm_ids ) )
			)
		);
		// phpcs:enable
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (int) $row->firm_id ] = (int) $row->n;
		}
		return $counts;
	}
}
