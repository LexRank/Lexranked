<?php
/**
 * Runs the ranking engine against stored data.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\Repository\SnapshotRepository;
use LexRanked\Core\Security\AuditLog;
use LexRanked\Core\Services;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Recalculates entity scores and rankings, writing snapshots.
 */
final class RankingRunner {

	public const CRON_HOOK  = 'lexranked_recalculate';
	public const DEBOUNCE_S = 60;

	/** Ranking post meta: counts behind a contextual ranking's eligibility (Etap F). */
	public const CONTEXT_STATS_META = '_lr_context_stats';

	/** Option holding the time of the last full recalculation (health checks). */
	public const LAST_RUN_OPTION = 'lexranked_last_calculation';

	/**
	 * Constructor.
	 *
	 * @param Services           $services  Services.
	 * @param SnapshotRepository $snapshots Snapshot storage.
	 * @param ScoreVersions      $versions  Version registry.
	 */
	public function __construct(
		private readonly Services $services,
		private readonly SnapshotRepository $snapshots,
		private readonly ScoreVersions $versions = new ScoreVersions()
	) {
	}

	/**
	 * Register cron + debounced recalculation on content changes.
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_all' ) );
		add_action( self::CRON_HOOK . '_soon', array( $this, 'run_all' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		foreach ( array( Lawyer::SLUG, LawFirm::SLUG, Ranking::SLUG, 'lr_verification' ) as $post_type ) {
			add_action( 'save_post_' . $post_type, array( $this, 'schedule_soon' ) );
		}
		// v1.1+ scores are built from the fact layer: new or re-reviewed evidence recalculates too.
		add_action( 'lexranked_facts_changed', array( $this, 'schedule_soon' ) );
	}

	/**
	 * Debounced single recalculation shortly after edits.
	 */
	public function schedule_soon(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK . '_soon' ) ) {
			wp_schedule_single_event( time() + self::DEBOUNCE_S, self::CRON_HOOK . '_soon' );
		}
	}

	/**
	 * Active version id (settings), falling back to the default.
	 */
	public function active_version(): ScoreVersion {
		$id = (string) ( $this->services->settings->get( 'score_version' ) ?? ScoreVersions::DEFAULT_VERSION );
		try {
			return $this->versions->get( $id );
		} catch ( \InvalidArgumentException $e ) {
			return $this->versions->get( ScoreVersions::DEFAULT_VERSION );
		}
	}

	/**
	 * Entity scores, then every published ranking.
	 *
	 * @return array{entities: int, rankings: int}
	 */
	public function run_all(): array {
		$entities = $this->score_entities( Lawyer::SLUG ) + $this->score_entities( LawFirm::SLUG );
		$rankings = 0;
		foreach ( get_posts(
			array(
				'post_type'        => Ranking::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => 500,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		) as $ranking_id ) {
			$this->run_ranking( (int) $ranking_id );
			++$rankings;
		}
		update_option( self::LAST_RUN_OPTION, gmdate( 'Y-m-d\TH:i:s\Z' ), false );
		/**
		 * Scores and rankings were recalculated (public data changed).
		 */
		do_action( 'lexranked_scores_updated' );
		return array(
			'entities' => $entities,
			'rankings' => $rankings,
		);
	}

	/**
	 * Score every published entity of a type in its own context; store
	 * entity-level snapshots and update the score meta used for sorting.
	 *
	 * @param string $post_type lr_lawyer|lr_law_firm.
	 * @return int Entities scored.
	 */
	public function score_entities( string $post_type ): int {
		$posts   = $this->published( $post_type );
		$version = $this->active_version();
		$now     = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$inputs  = ( new InputBuilder( $this->services ) )->build( $posts, $now, $version );
		$calc    = new ScoreCalculator();
		$run_id  = wp_generate_uuid4();
		$stamp   = $now->format( 'Y-m-d H:i:s' );
		$iso     = $now->format( 'Y-m-d\TH:i:s\Z' );
		$type    = Lawyer::SLUG === $post_type ? $this->services->lawyer : $this->services->law_firm;

		$rows = array();
		foreach ( $inputs as $input ) {
			$context = RankingContext::for_entity( $input );
			$result  = $calc->calculate( $input, $context, $version );
			$rows[]  = $this->row( $run_id, 0, 0, $input, $context, $result, $stamp );
			$this->services->entities->save_fields(
				$input->entity_id,
				$type,
				array(
					'score'               => $result->total,
					'score_version'       => $result->version,
					'score_calculated_at' => $iso,
				),
				true
			);
		}
		if ( array() !== $rows ) {
			$this->snapshots->insert_run( $rows );
		}
		return count( $rows );
	}

	/**
	 * Calculate one ranking and store a snapshot run.
	 *
	 * @param int $ranking_id Ranking post ID.
	 * @return array{run_id: string|null, entries: int}
	 */
	public function run_ranking( int $ranking_id ): array {
		$post = get_post( $ranking_id );
		if ( ! $post instanceof \WP_Post || Ranking::SLUG !== $post->post_type ) {
			return array(
				'run_id'  => null,
				'entries' => 0,
			);
		}
		$record           = $this->services->entities->record( $post, $this->services->ranking );
		$version          = $this->version_for( $record['fields']['score_version'] ?? null );
		$qualifier        = RankingQualifier::for_record( $record );
		$practice         = RankingQualifier::primary_practice( $record['practice_areas'], $qualifier );
		[ $city, $state ] = InputBuilder::location( $record['locations'] );
		$context          = new RankingContext( $practice['slug'] ?? null, $city, $state );

		$candidates     = $this->candidates( $record, $practice );
		$qualifications = array();
		if ( null !== $qualifier ) {
			// Contextual ranking: only entities whose stored facts confirm the context.
			$parent_count = count( $candidates );
			$type         = 'law_firm' === $record['fields']['entity_type'] ? 'law_firm' : 'lawyer';
			$candidates   = array_values(
				array_filter(
					$candidates,
					function ( \WP_Post $candidate ) use ( $qualifier, $type, &$qualifications ): bool {
						$evidence = $qualifier->qualify( $this->services->facts->for_entity( $type, (int) $candidate->ID ) );
						if ( null !== $evidence ) {
							$qualifications[ (int) $candidate->ID ] = $evidence;
						}
						return null !== $evidence;
					}
				)
			);
			update_post_meta(
				$ranking_id,
				self::CONTEXT_STATS_META,
				wp_json_encode(
					array(
						'parent'        => $parent_count,
						'qualified'     => count( $qualifications ),
						'verified'      => count( array_filter( $qualifications, static fn( array $q ): bool => 'verified' === $q['status'] ) ),
						'qualifier'     => $qualifier->to_array(),
						'calculated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
					)
				)
			);
		}//end if
		$now    = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$inputs = ( new InputBuilder( $this->services ) )->build( $candidates, $now, $version );
		$ranked = ( new RankingEngine() )->rank( $inputs, $context, $version );

		$run_id = wp_generate_uuid4();
		$stamp  = $now->format( 'Y-m-d H:i:s' );
		$rows   = array();
		foreach ( $ranked as $entry ) {
			$extra  = null === $qualifier ? array() : array(
				'qualifier'     => $qualifier->to_array(),
				'qualification' => $qualifications[ $entry['input']->entity_id ] ?? null,
			);
			$rows[] = $this->row( $run_id, $ranking_id, $entry['position'], $entry['input'], $context, $entry['result'], $stamp, $extra );
		}
		if ( array() !== $rows ) {
			$this->snapshots->insert_run( $rows );
		}
		AuditLog::log(
			'ranking.calculated',
			Ranking::SLUG,
			$ranking_id,
			array(
				'run_id'  => $run_id,
				'version' => $version->id,
				'entries' => count( $rows ),
			)
		);
		return array(
			'run_id'  => array() === $rows ? null : $run_id,
			'entries' => count( $rows ),
		);
	}

	/**
	 * Recompute a stored run from its stored inputs and compare.
	 *
	 * @param string $run_id Run ID.
	 * @return array{rows: int, mismatches: array<int, int>} Entity IDs whose recomputed score differs.
	 */
	public function verify_run( string $run_id ): array {
		$rows       = $this->snapshots->run_rows( $run_id );
		$calc       = new ScoreCalculator();
		$mismatches = array();
		foreach ( $rows as $row ) {
			$context = new RankingContext( $row['context']['practice_area'] ?? null, $row['context']['city'] ?? null, $row['context']['state'] ?? null );
			$result  = $calc->calculate( EntityInput::from_array( $row['inputs'] ), $context, $this->versions->get( $row['score_version'] ) );
			if ( abs( $result->total - $row['score'] ) > 0.001 ) {
				$mismatches[] = $row['entity_id'];
			}
		}
		return array(
			'rows'       => count( $rows ),
			'mismatches' => $mismatches,
		);
	}

	/**
	 * Version for a ranking (its own field, else active).
	 *
	 * @param string|null $id Version id.
	 */
	private function version_for( ?string $id ): ScoreVersion {
		if ( null !== $id && '' !== $id ) {
			try {
				return $this->versions->get( $id );
			} catch ( \InvalidArgumentException $e ) {
				// Unknown version on the ranking: fall back to the active one.
				return $this->active_version();
			}
		}
		return $this->active_version();
	}

	/**
	 * Published entities matching a ranking's location and practice area.
	 *
	 * @param array<string, mixed>      $record        Ranking record.
	 * @param array<string, mixed>|null $practice_term The ranking's practice area.
	 * @return array<int, \WP_Post>
	 */
	public function candidates( array $record, ?array $practice_term ): array {
		$practice = $practice_term['id'] ?? null;
		if ( array() === $record['locations'] ) {
			return array();
		}
		$specific = null;
		foreach ( $record['locations'] as $term ) {
			if ( null === $specific || 0 !== $term['parent'] ) {
				$specific = $term;
			}
		}
		$tax = array(
			'relation' => 'AND',
			array(
				'taxonomy' => Location::SLUG,
				'field'    => 'term_id',
				'terms'    => array( $specific['id'] ),
			),
		);
		if ( null !== $practice ) {
			$tax[] = array(
				'taxonomy' => PracticeArea::SLUG,
				'field'    => 'term_id',
				'terms'    => array( $practice ),
			);
		}
		return get_posts(
			array(
				'post_type'        => 'law_firm' === $record['fields']['entity_type'] ? LawFirm::SLUG : Lawyer::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => 1000,
				'no_found_rows'    => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'tax_query'        => $tax,
			)
		);
	}

	/**
	 * Published posts of a type.
	 *
	 * @param string $post_type Post type.
	 * @return array<int, \WP_Post>
	 */
	private function published( string $post_type ): array {
		return get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'posts_per_page'   => 5000,
				'no_found_rows'    => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Snapshot row.
	 *
	 * @param string         $run_id     Run.
	 * @param int            $ranking_id Ranking (0 = entity level).
	 * @param int            $position   Position.
	 * @param EntityInput    $input      Input.
	 * @param RankingContext $context    Context.
	 * @param ScoreResult    $result     Result.
	 * @param string         $stamp      MySQL UTC datetime.
	 * @param array          $extra      Extra context (contextual rankings: qualifier and the entity's qualifying evidence).
	 * @return array<string, mixed>
	 */
	private function row( string $run_id, int $ranking_id, int $position, EntityInput $input, RankingContext $context, ScoreResult $result, string $stamp, array $extra = array() ): array {
		return array(
			'run_id'        => $run_id,
			'ranking_id'    => $ranking_id,
			'entity_id'     => $input->entity_id,
			'entity_type'   => $input->entity_type,
			'position'      => $position,
			'score'         => $result->total,
			'score_version' => $result->version,
			'context'       => $context->to_array() + $extra,
			'components'    => $result->components,
			'inputs'        => $input->to_array(),
			'calculated_at' => $stamp,
		);
	}
}
