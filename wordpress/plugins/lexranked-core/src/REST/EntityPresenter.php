<?php
/**
 * Assembles DTOs from WordPress data.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Content\StructuredSummary;
use LexRanked\Core\Eligibility\PageEligibility;
use LexRanked\Core\Entity\EntityType;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\PostTypes\Source;
use LexRanked\Core\REST\DTO\EntityMapper;
use LexRanked\Core\REST\DTO\FactMapper;
use LexRanked\Core\REST\DTO\LocationMapper;
use LexRanked\Core\REST\DTO\RankingMapper;
use LexRanked\Core\REST\DTO\SourceMapper;
use LexRanked\Core\Ranking\ContextEligibility;
use LexRanked\Core\Ranking\RankingQualifier;
use LexRanked\Core\Ranking\RankingRunner;
use LexRanked\Core\Services;
use LexRanked\Core\Support\Text;
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
		$eligibility   = $s->eligibility->profiles( 'lawyer', array_column( $records, 'id' ) );

		return array_map(
			fn( array $r ): array => EntityMapper::lawyer_summary(
				$r,
				$firms[ $r['fields']['firm_id'] ?? 0 ] ?? null,
				$policy->evaluate( $verifications[ $r['id'] ] ?? array(), $now )
			) + array( 'eligibility' => PageEligibility::compact( $eligibility[ $r['id'] ] ) ),
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
		$dto['clientReviews']  = $s->reviews->public_block( Lawyer::SLUG === $post->post_type ? 'lawyer' : 'law_firm', (int) $post->ID );
		$dto['facts']          = $this->facts( Lawyer::SLUG === $post->post_type ? 'lawyer' : 'law_firm', (int) $post->ID );
		$dto['dataQuality']    = $s->quality->stored( (int) $post->ID );
		$dto['eligibility']    = $s->eligibility->profiles( Lawyer::SLUG === $post->post_type ? 'lawyer' : 'law_firm', array( (int) $post->ID ) )[ (int) $post->ID ];
		$dto                   = $this->with_scoring( $dto, (int) $post->ID );
		$dto['aiSummary']      = StructuredSummary::for_detail( $dto );
		return $dto;
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
		$eligibility   = $s->eligibility->profiles( 'law_firm', array_column( $records, 'id' ) );

		return array_map(
			fn( array $r ): array => EntityMapper::firm_summary( $r, $policy->evaluate( $verifications[ $r['id'] ] ?? array(), $now ), $counts[ $r['id'] ] ?? 0 )
				+ array( 'eligibility' => PageEligibility::compact( $eligibility[ $r['id'] ] ) ),
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
		$dto['clientReviews']  = $s->reviews->public_block( Lawyer::SLUG === $post->post_type ? 'lawyer' : 'law_firm', (int) $post->ID );
		$dto['facts']          = $this->facts( Lawyer::SLUG === $post->post_type ? 'lawyer' : 'law_firm', (int) $post->ID );
		$dto['dataQuality']    = $s->quality->stored( (int) $post->ID );
		$dto['eligibility']    = $s->eligibility->profiles( Lawyer::SLUG === $post->post_type ? 'lawyer' : 'law_firm', array( (int) $post->ID ) )[ (int) $post->ID ];
		$dto                   = $this->with_scoring( $dto, (int) $post->ID );
		$dto['aiSummary']      = StructuredSummary::for_detail( $dto );
		return $dto;
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
		$entries = RankingMapper::entries_from_snapshots( $rows, $summaries, $previous, $previous_rows );
		// Contextual rankings: name the source behind each entry's qualifying fact.
		$sources = $this->source_dtos( array_map( static fn( array $e ): array => array( 'source_id' => $e['qualification']['sourceId'] ?? null ), $entries ) );
		foreach ( $entries as &$entry ) {
			if ( null !== $entry['qualification'] ) {
				$source                           = $sources[ (int) ( $entry['qualification']['sourceId'] ?? 0 ) ] ?? null;
				$entry['qualification']['source'] = null === $source ? null : array(
					'name'      => $source['name'] ?? null,
					'url'       => $source['url'] ?? null,
					'tierLabel' => $source['tierLabel'] ?? null,
				);
			}
		}
		unset( $entry );
		return array(
			'entries'       => $entries,
			'calculated_at' => $rows[0]['calculated_at'] ?? null,
		);
	}

	/**
	 * Sources behind a ranking's entries (Etap H): every published source
	 * that at least one entry's facts rest on, with how many facts and
	 * entries it supports, best tier first.
	 *
	 * @param string                           $entity_type lawyer|law_firm.
	 * @param array<int, array<string, mixed>> $entries     Entries.
	 * @return array<int, array<string, mixed>>
	 */
	public function ranking_sources( string $entity_type, array $entries ): array {
		$counts = $this->services->facts->source_counts( $entity_type, array_map( static fn( array $e ): int => (int) $e['entity']['id'], $entries ) );
		$dtos   = $this->source_dtos( array_map( static fn( int $id ): array => array( 'source_id' => $id ), array_keys( $counts ) ) );
		$out    = array();
		foreach ( $counts as $source_id => $c ) {
			if ( ! isset( $dtos[ $source_id ] ) ) {
				continue;
			}
			$d     = $dtos[ $source_id ];
			$out[] = array(
				'id'        => $source_id,
				'name'      => $d['name'],
				'url'       => $d['url'],
				'publisher' => $d['publisher'] ?? null,
				'type'      => $d['type'] ?? null,
				'tier'      => $d['tier'] ?? null,
				'tierLabel' => $d['tierLabel'] ?? null,
				'facts'     => $c['facts'],
				'entities'  => $c['entities'],
			);
		}
		usort( $out, static fn( array $a, array $b ): int => array( $a['tier'] ?? 9, -$a['facts'], $a['name'] ) <=> array( $b['tier'] ?? 9, -$b['facts'], $b['name'] ) );
		return $out;
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
			$record  = $this->services->entities->record( $post, $this->services->ranking );
			$context = $this->ranking_context( $record );
			if ( null !== $context && ! $context['eligibility']['eligible'] ) {
				continue;
				// A contextual ranking below its data threshold has no page.
			}
			$positions[] = array(
				'id'           => (int) $post->ID,
				'title'        => $record['title'],
				'path'         => RankingMapper::record_path( $record ),
				'position'     => $row['position'],
				'score'        => round( (float) $row['score'], 2 ),
				'calculatedAt' => $row['calculated_at'],
				'isDemo'       => (bool) $record['fields']['is_demo'],
				'neighbors'    => $this->neighbors( $row ),
			);
		}//end foreach
		return array(
			'breakdown' => null === $latest ? array() : EntityMapper::breakdown( $latest['components'] ),
			'rankings'  => $positions,
		);
	}

	/**
	 * Context DTO of a contextual ranking (Etap F), or null for an ordinary one:
	 * the qualifier, its label, the counts from the latest calculation and
	 * whether the page may exist, plus the broader ranking it narrows.
	 *
	 * @param array<string, mixed> $record Ranking record.
	 * @return array<string, mixed>|null
	 */
	public function ranking_context( array $record ): ?array {
		$qualifier = RankingQualifier::for_record( $record );
		if ( null === $qualifier ) {
			return null;
		}
		$practice = RankingQualifier::primary_practice( $record['practice_areas'], $qualifier );
		$problem  = null;
		$name     = null;
		if ( RankingQualifier::CASE_TYPE === $qualifier->type ) {
			$term = get_term_by( 'slug', $qualifier->value, PracticeArea::SLUG );
			if ( ! $term instanceof \WP_Term ) {
				$problem = 'The case type is not a practice area in the taxonomy.';
			} elseif ( null === $practice || (int) $term->parent !== (int) $practice['id'] ) {
				$problem = sprintf( '%s is not a sub-area of the ranking\'s practice area.', $term->name );
			} else {
				$name = $term->name;
			}
		}

		$stats = json_decode( (string) get_post_meta( (int) $record['id'], RankingRunner::CONTEXT_STATS_META, true ), true );
		if ( ! is_array( $stats ) || ( $stats['qualifier'] ?? null ) !== $qualifier->to_array() ) {
			$stats = null;
			// Never calculated, or the context changed since.
		}
		$f = $record['fields'];
		return array(
			'type'         => $qualifier->type,
			'value'        => $qualifier->value,
			'segment'      => $qualifier->segment(),
			'label'        => $qualifier->label( $name ),
			'attribute'    => $qualifier->attribute(),
			'eligibility'  => ContextEligibility::evaluate(
				$stats,
				(int) ( $f['min_entities'] ?? $this->services->settings->get( 'min_ranking_entities' ) ),
				(int) ( $f['min_verified'] ?? ContextEligibility::MIN_VERIFIED ),
				$problem
			),
			'calculatedAt' => $stats['calculated_at'] ?? null,
			'parent'       => $this->parent_ranking( $record, $practice ),
		);
	}

	/**
	 * The published ordinary ranking with the same location and practice area.
	 *
	 * @param array<string, mixed>      $record   Contextual ranking record.
	 * @param array<string, mixed>|null $practice Its practice-area term.
	 * @return array{id: int, title: string, path: string|null}|null
	 */
	private function parent_ranking( array $record, ?array $practice ): ?array {
		$location = array_column( $record['locations'], 'id' );
		if ( array() === $location ) {
			return null;
		}
		$tax = array(
			'relation' => 'AND',
			array(
				'taxonomy'         => Location::SLUG,
				'field'            => 'term_id',
				'terms'            => $location,
				'include_children' => false,
			),
		);
		if ( null !== $practice ) {
			$tax[] = array(
				'taxonomy'         => PracticeArea::SLUG,
				'field'            => 'term_id',
				'terms'            => array( (int) $practice['id'] ),
				'include_children' => false,
			);
		}
		$want = RankingMapper::path( LocationMapper::from_terms( $record['locations'] ), null === $practice ? null : array( 'slug' => $practice['slug'] ) );
		foreach ( get_posts(
			array(
				'post_type'        => Ranking::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => 50,
				'no_found_rows'    => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'post__not_in'     => array( (int) $record['id'] ),
				'tax_query'        => $tax,
			)
		) as $post ) {
			$candidate = $this->services->entities->record( $post, $this->services->ranking );
			if ( null === RankingQualifier::for_record( $candidate ) && $candidate['fields']['entity_type'] === $record['fields']['entity_type'] && RankingMapper::record_path( $candidate ) === $want ) {
				return array(
					'id'    => (int) $post->ID,
					'title' => $candidate['title'],
					'path'  => $want,
				);
			}
		}
		return null;
	}

	/**
	 * The published entries directly above and below an entity in the same
	 * ranking run: the natural "compare with" candidates (Etap E).
	 *
	 * @param array<string, mixed> $row Snapshot row of the entity.
	 * @return array<int, array<string, mixed>>
	 */
	private function neighbors( array $row ): array {
		$type = EntityType::tryFrom( (string) $row['entity_type'] );
		if ( null === $type ) {
			return array();
		}
		$out = array();
		foreach ( $this->services->snapshots->run_rows( (string) $row['run_id'] ) as $other ) {
			if ( 1 !== abs( $other['position'] - (int) $row['position'] ) ) {
				continue;
			}
			$post      = get_post( $other['entity_id'] );
			$entity_id = $this->services->registry->id_for( $type, (int) $other['entity_id'] );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || null === $entity_id ) {
				continue;
			}
			$out[] = array(
				'id'       => (int) $post->ID,
				'entityId' => $entity_id,
				'name'     => Text::title( $post ),
				'position' => $other['position'],
			);
		}
		return $out;
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
					$titles[ $row['entity_id'] ] = ( $post instanceof \WP_Post && 'publish' === $post->post_status ) ? Text::title( $post ) : null;
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
