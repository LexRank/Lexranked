<?php
/**
 * Rankings endpoints.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Support\ResponseCache;
use LexRanked\Core\Sources\SourceTiers;
use LexRanked\Core\Ranking\RankingRunner;
use LexRanked\Core\Quality\DataQuality;
use LexRanked\Core\Eligibility\PageEligibility;
use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\REST\DTO\RankingMapper;
use LexRanked\Core\Services;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * GET /rankings, /rankings/{id}
 */
final class RankingsController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/rankings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_items' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => $this->collection_args(
					array( 'updated', 'title' ),
					array(
						'location'      => $this->slug_arg( 'State or city slug.' ),
						'practice_area' => $this->slug_arg( 'Practice area slug.' ),
						'indexable'     => array(
							'type'              => 'boolean',
							'description'       => 'Only rankings with enough data to be published and indexed.',
							'validate_callback' => 'rest_validate_request_arg',
						),
					)
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/rankings/' . $this->id_pattern() . '/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'history' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 50,
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/methodology',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'methodology' ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/score-versions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'score_versions' ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/rankings/' . $this->id_pattern(),
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => $this->context_arg(),
			)
		);
	}

	/**
	 * List rankings (without entries).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$order     = 'asc' === $request['order'] ? 'ASC' : 'DESC';
		$tax_query = array();
		if ( ! empty( $request['location'] ) ) {
			$tax_query[] = array(
				'taxonomy'         => Location::SLUG,
				'field'            => 'slug',
				'terms'            => array( (string) $request['location'] ),
				'include_children' => false,
			);
		}
		if ( ! empty( $request['practice_area'] ) ) {
			$tax_query[] = array(
				'taxonomy' => PracticeArea::SLUG,
				'field'    => 'slug',
				'terms'    => array( (string) $request['practice_area'] ),
			);
		}

		// Rankings are few; evaluate thinness for all, then paginate in PHP so
		// `indexable` filtering and totals stay consistent. Building every DTO
		// loads each ranking's entries, so the full list is cached per filter
		// (invalidated by any content change or recalculation).
		$params    = array(
			'location'      => (string) $request['location'],
			'practice_area' => (string) $request['practice_area'],
			'order'         => $order,
			'orderby'       => 'title' === $request['orderby'] ? 'title' : 'modified',
			'indexable'     => null === $request['indexable'] ? null : (bool) $request['indexable'],
		);
		[ $items ] = ResponseCache::remember( '/rankings', $params, fn(): array => $this->all_items( $request, $order, $tax_query ) );

		$per_page = (int) $request['per_page'];
		return $this->collection_response( array_slice( $items, ( (int) $request['page'] - 1 ) * $per_page, $per_page ), count( $items ), $per_page );
	}

	/**
	 * Every published ranking matching the filters, as list DTOs.
	 *
	 * @param \WP_REST_Request                 $request   Request.
	 * @param string                           $order     ASC or DESC.
	 * @param array<int, array<string, mixed>> $tax_query Taxonomy filters.
	 * @return array<int, array<string, mixed>>
	 */
	private function all_items( \WP_REST_Request $request, string $order, array $tax_query ): array {
		$posts = get_posts(
			array(
				'post_type'        => Ranking::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => 500,
				'no_found_rows'    => true,
				'orderby'          => array(
					'title' === $request['orderby'] ? 'title' : 'modified' => $order,
					'ID' => 'ASC',
				),
				'suppress_filters' => false,
				'tax_query'        => array() === $tax_query ? array() : array_merge( array( 'relation' => 'AND' ), $tax_query ),
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$record                    = $this->services->entities->record( $post, $this->services->ranking );
			$run                       = $this->services->presenter->ranking_entries( $record );
			[ $context, $eligibility ] = $this->decide( $record, $run['entries'] );
			$dto                       = RankingMapper::ranking( $record, $run['entries'], (int) $this->services->settings->get( 'min_ranking_entities' ), '', false, $run['calculated_at'], $context, $eligibility );
			if ( null !== $request['indexable'] && (bool) $request['indexable'] !== $dto['indexable'] ) {
				continue;
			}
			$items[] = $dto;
		}
		return $items;
	}

	/**
	 * Ranking history: recent snapshot runs with positions (spec §34).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function history( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$post = $this->services->entities->find_published( $this->services->ranking, (string) $request['id'] );
		if ( null === $post ) {
			return $this->not_found( 'Ranking' );
		}
		return $this->item_response(
			array(
				'rankingId' => (int) $post->ID,
				'runs'      => $this->services->presenter->ranking_history( (int) $post->ID, (int) $request['limit'] ),
			)
		);
	}

	/**
	 * The live methodology (Etap H): active version, last calculation,
	 * schedule, source tiers and freshness windows.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function methodology( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$s     = $this->services;
		$tiers = $s->settings->source_tiers();
		$types = array();
		foreach ( $tiers->types() as $type ) {
			$tier    = $tiers->tier_for( $type );
			$types[] = array(
				'type'      => $type,
				'tier'      => $tier,
				'tierLabel' => SourceTiers::label( $tier ),
			);
		}
		usort( $types, static fn( array $a, array $b ): int => array( $a['tier'], $a['type'] ) <=> array( $b['tier'], $b['type'] ) );
		$freshness = array();
		foreach ( $s->settings->freshness()->rules() as $category => $days ) {
			$freshness[] = array(
				'category'   => $category,
				'maxAgeDays' => $days,
			);
		}
		$last = (string) get_option( RankingRunner::LAST_RUN_OPTION, '' );
		return $this->item_response(
			array(
				'active'          => $s->runner->active_version()->to_array(),
				'versions'        => array_values( array_map( static fn( $v ): array => $v->to_array(), $s->versions->all() ) ),
				'updatedAt'       => '' === $last ? null : $last,
				'schedule'        => array(
					'recalculation' => 'Daily, and within minutes of any change to profiles or evidence.',
					'dataQuality'   => 'Daily, and whenever an entity\'s facts or verifications change.',
					'snapshots'     => 'Every calculation is stored as an immutable snapshot with its inputs and methodology version.',
				),
				'sourceTiers'     => $types,
				'freshness'       => $freshness,
				'dataQuality'     => DataQuality::VERSION,
				'pageEligibility' => PageEligibility::VERSION,
			)
		);
	}

	/**
	 * Methodology versions and weights (single source for the frontend).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function score_versions( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		return $this->item_response(
			array(
				'active'   => $this->services->runner->active_version()->id,
				'versions' => array_values( array_map( static fn( $v ): array => $v->to_array(), $this->services->versions->all() ) ),
			)
		);
	}

	/**
	 * Ranking with entries.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$post = $this->services->entities->find_published( $this->services->ranking, (string) $request['id'] );
		if ( null === $post ) {
			return $this->not_found( 'Ranking' );
		}
		$record                    = $this->services->entities->record( $post, $this->services->ranking );
		$run                       = $this->services->presenter->ranking_entries( $record );
		[ $context, $eligibility ] = $this->decide( $record, $run['entries'] );
		$dto                       = RankingMapper::ranking(
			$record,
			$run['entries'],
			(int) $this->services->settings->get( 'min_ranking_entities' ),
			$this->html( $post->post_content ),
			true,
			$run['calculated_at'],
			$context,
			$eligibility
		);
		$dto['sources']            = $dto['isThin'] ? array() : $this->services->presenter->ranking_sources( 'law_firm' === $record['fields']['entity_type'] ? 'law_firm' : 'lawyer', $dto['entries'] );
		return $this->item_response( $dto );
	}

	/**
	 * Context and page eligibility of a ranking (Etap F/G).
	 *
	 * @param array<string, mixed>             $record  Ranking record.
	 * @param array<int, array<string, mixed>> $entries Entries.
	 * @return array{0: array<string, mixed>|null, 1: array<string, mixed>}
	 */
	private function decide( array $record, array $entries ): array {
		$context = $this->services->presenter->ranking_context( $record );
		$min     = (int) ( $record['fields']['min_entities'] ?? $this->services->settings->get( 'min_ranking_entities' ) );
		return array( $context, $this->services->eligibility->ranking( $record, $entries, $min, $context ) );
	}
}
