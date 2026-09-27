<?php
/**
 * Assembles DTOs from WordPress data.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Entity\EntityType;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\Source;
use LexRanked\Core\REST\DTO\EntityMapper;
use LexRanked\Core\REST\DTO\FactMapper;
use LexRanked\Core\REST\DTO\LocationMapper;
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
		$s = $this->services;
		$s->registry->ids_for( EntityType::Lawyer, array_map( static fn( \WP_Post $p ): int => (int) $p->ID, $posts ) );
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

		$dto                   = EntityMapper::lawyer_detail(
			$record,
			$firm,
			$verification,
			$freshness,
			$this->evidence( 'lawyer', $post->ID ),
			(string) wp_kses_post( wpautop( $post->post_content ) ),
			$include_private
		);
		$dto['premiumContent'] = $s->commercial->premium_content( (int) $post->ID );
		$dto['facts']          = $this->facts( Lawyer::SLUG === $post->post_type ? 'lawyer' : 'law_firm', (int) $post->ID );
		$dto['dataQuality']    = $s->quality->stored( (int) $post->ID );
		return $this->with_scoring( $dto, (int) $post->ID );
	}

	/**
	 * Firm summaries.
	 *
	 * @param array<int, \WP_Post> $posts Firm posts.
	 * @return array<int, array<string, mixed>>
	 */
	public function firm_summaries( array $posts ): array {
		$s = $this->services;
		$s->registry->ids_for( EntityType::LawFirm, array_map( static fn( \WP_Post $p ): int => (int) $p->ID, $posts ) );
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

		$dto                   = EntityMapper::firm_detail(
			$record,
			$verification,
			$lawyers,
			$s->settings->freshness()->evaluate( 'profile', $verification['verified_at'], $this->now() ),
			$this->evidence( 'law_firm', $post->ID ),
			(string) wp_kses_post( wpautop( $post->post_content ) )
		);
		$dto['premiumContent'] = $s->commercial->premium_content( (int) $post->ID );
		$dto['facts']          = $this->facts( Lawyer::SLUG === $post->post_type ? 'lawyer' : 'law_firm', (int) $post->ID );
		$dto['dataQuality']    = $s->quality->stored( (int) $post->ID );
		return $this->with_scoring( $dto, (int) $post->ID );
	}

	/**
	 * Entries for a ranking from its latest snapshot run.
	 *
	 * @param array<string, mixed> $record Ranking record.
	 * @return array{entries: array<int, array<string, mixed>>, calculated_at: string|null}
	 */
	public function ranking_entries( array $record ): array {
		$snapshots = $this->services->snapshots;
		$runs      = $snapshots->run_ids( (int) $record['id'], 2 );
		if ( array() === $runs ) {
			return array(
				'entries'       => array(),
				'calculated_at' => null,
			);
		}
		$rows          = $snapshots->run_rows( $runs[0] );
		$previous      = null;
		$previous_rows = array();
		if ( isset( $runs[1] ) ) {
			$previous = array();
			foreach ( $snapshots->run_rows( $runs[1] ) as $row ) {
				$previous[ $row['entity_id'] ]      = $row['position'];
				$previous_rows[ $row['entity_id'] ] = $row;
			}
		}
		$is_firm   = 'law_firm' === ( $rows[0]['entity_type'] ?? 'lawyer' );
		$ids       = array_column( $rows, 'entity_id' );
		$posts     = array() === $ids ? array() : get_posts(
			array(
				'post_type'        => $is_firm ? LawFirm::SLUG : Lawyer::SLUG,
				'post_status'      => 'publish',
				'post__in'         => $ids,
				'posts_per_page'   => count( $ids ),
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		$summaries = array();
		foreach ( $is_firm ? $this->firm_summaries( $posts ) : $this->lawyer_summaries( $posts ) as $summary ) {
			$summaries[ $summary['id'] ] = $summary;
		}
		return array(
			'entries'       => RankingMapper::entries_from_snapshots( $rows, $summaries, $previous, $previous_rows ),
			'calculated_at' => $rows[0]['calculated_at'] ?? null,
		);
	}

	/**
	 * Score breakdown (entity context) and ranking positions for a profile.
	 *
	 * @param int $entity_id Entity ID.
	 * @return array{breakdown: array<int, array<string, mixed>>, rankings: array<int, array<string, mixed>>}
	 */
	public function scoring_details( int $entity_id ): array {
		$snapshots = $this->services->snapshots;
		$latest    = $snapshots->latest_for_entity( $entity_id );
		$positions = array();
		foreach ( $snapshots->latest_positions_for_entity( $entity_id ) as $row ) {
			$post = get_post( $row['ranking_id'] );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
				continue;
			}
			$record      = $this->services->entities->record( $post, $this->services->ranking );
			$location    = LocationMapper::from_terms( $record['locations'] );
			$practice    = EntityMapper::practice_areas( $record['practice_areas'] )[0] ?? null;
			$positions[] = array(
				'id'           => (int) $post->ID,
				'title'        => $record['title'],
				'path'         => RankingMapper::path( $location, $practice ),
				'position'     => $row['position'],
				'score'        => round( (float) $row['score'], 2 ),
				'calculatedAt' => $row['calculated_at'],
				'isDemo'       => (bool) $record['fields']['is_demo'],
			);
		}
		return array(
			'breakdown' => null === $latest ? array() : EntityMapper::breakdown( $latest['components'] ),
			'rankings'  => $positions,
		);
	}

	/**
	 * Recent runs of a ranking for the history endpoint.
	 *
	 * @param int $ranking_id Ranking ID.
	 * @param int $limit      Runs.
	 * @return array<int, array<string, mixed>>
	 */
	public function ranking_history( int $ranking_id, int $limit ): array {
		$history = array();
		$titles  = array();
		foreach ( $this->services->snapshots->run_ids( $ranking_id, $limit ) as $run_id ) {
			$rows    = $this->services->snapshots->run_rows( $run_id );
			$entries = array();
			foreach ( $rows as $row ) {
				if ( ! array_key_exists( $row['entity_id'], $titles ) ) {
					$post                        = get_post( $row['entity_id'] );
					$titles[ $row['entity_id'] ] = ( $post instanceof \WP_Post && 'publish' === $post->post_status ) ? get_the_title( $post ) : null;
				}
				if ( null === $titles[ $row['entity_id'] ] ) {
					continue;
				}
				$entries[] = array(
					'entityId' => $row['entity_id'],
					'name'     => $titles[ $row['entity_id'] ],
					'position' => $row['position'],
					'score'    => round( (float) $row['score'], 2 ),
				);
			}
			$history[] = array(
				'runId'        => $run_id,
				'calculatedAt' => $rows[0]['calculated_at'] ?? null,
				'scoreVersion' => $rows[0]['score_version'] ?? null,
				'entries'      => $entries,
			);
		}//end foreach
		return $history;
	}

	/**
	 * Add the score breakdown and ranking positions to a detail DTO.
	 *
	 * @param array<string, mixed> $dto       Detail DTO.
	 * @param int                  $entity_id Entity ID.
	 * @return array<string, mixed>
	 */
	private function with_scoring( array $dto, int $entity_id ): array {
		$details                     = $this->scoring_details( $entity_id );
		$dto['ranking']['breakdown'] = $details['breakdown'];
		$dto['rankings']             = $details['rankings'];
		return $dto;
	}

	/**
	 * Evidence DTOs for an entity.
	 *
	 * @param string $entity_type lawyer|law_firm.
	 * @param int    $entity_id   ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function evidence( string $entity_type, int $entity_id ): array {
		$claims = $this->services->claims->for_entity( $entity_type, $entity_id );
		return SourceMapper::evidence( $claims, $this->source_dtos( $claims ), $this->services->settings->source_tiers() );
	}

	/**
	 * Normalised facts with their source and freshness (Etap B).
	 *
	 * @param string $entity_type lawyer|law_firm.
	 * @param int    $entity_id   ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function facts( string $entity_type, int $entity_id ): array {
		$s      = $this->services;
		$claims = $s->claims->for_entity( $entity_type, $entity_id );
		return FactMapper::facts( $s->facts->for_entity( $entity_type, $entity_id ), array_column( $claims, null, 'claim_id' ), $this->source_dtos( $claims ), $s->settings->freshness(), $this->now() );
	}

	/**
	 * Published source DTOs referenced by claims, keyed by ID.
	 *
	 * @param array<int, array<string, mixed>> $claims Claims.
	 * @return array<int, array<string, mixed>>
	 */
	private function source_dtos( array $claims ): array {
		$s       = $this->services;
		$ids     = array_values( array_unique( array_filter( array_column( $claims, 'source_id' ) ) ) );
		$sources = array();
		if ( array() === $ids ) {
			return $sources;
		}
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
			$sources[ $post->ID ] = SourceMapper::source( $s->entities->record( $post, $s->source ), $s->settings->source_tiers() );
		}
		return $sources;
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
