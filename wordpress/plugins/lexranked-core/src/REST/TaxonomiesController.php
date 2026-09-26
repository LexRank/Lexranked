<?php
/**
 * States, cities and practice areas endpoints.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Content\TermContent;
use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\REST\DTO\LocationMapper;
use LexRanked\Core\Repository\EntityRepository;
use LexRanked\Core\Support\ResponseCache;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * GET /states, /cities, /practice-areas
 *
 * Counts only published lawyers/firms, so the frontend can decide whether a
 * page has enough data to exist.
 */
final class TaxonomiesController extends RestController {

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$common = array(
			'hide_empty' => array(
				'type'              => 'boolean',
				'default'           => true,
				'description'       => 'Hide terms without published lawyers or firms.',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/states',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'states' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => array_merge( $common, $this->context_arg() ),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/cities',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'cities' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => array_merge(
					$common,
					$this->context_arg(),
					array( 'state' => array_merge( $this->slug_arg( 'State slug or 2-letter code.' ), array( 'pattern' => '^([a-z0-9]+(-[a-z0-9]+)*|[A-Za-z]{2})$' ) ) )
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/practice-areas',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'practice_areas' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => array_merge( $common, $this->context_arg() ),
			)
		);
	}

	/**
	 * States.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function states( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		[ $items ] = ResponseCache::remember(
			'/states',
			$request->get_params(),
			function () use ( $request ): array {
				$items = array();
				foreach ( $this->terms( Location::SLUG, array( 'parent' => 0 ) ) as $term ) {
					$counts = $this->counts( Location::SLUG, $term->term_id );
					if ( $request['hide_empty'] && 0 === $counts['lawyerCount'] + $counts['lawFirmCount'] ) {
						continue;
					}
					$location = LocationMapper::build( null, EntityRepository::location_term( $term ) );
					$items[]  = array(
						'id'        => (int) $term->term_id,
						'slug'      => $term->slug,
						'name'      => $location['state'],
						'code'      => $location['stateCode'],
						'path'      => '/states/' . $term->slug . '/',
						'cityCount' => count( $this->terms( Location::SLUG, array( 'parent' => $term->term_id ) ) ),
						'content'   => TermContent::dto( (int) $term->term_id ),
					) + $counts;
				}
				return $items;
			}
		);
		return $this->collection_response( $items, count( $items ), max( 1, count( $items ) ) );
	}

	/**
	 * Cities.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cities( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$states = array();
		foreach ( $this->terms( Location::SLUG, array( 'parent' => 0 ) ) as $state ) {
			$states[ $state->term_id ] = EntityRepository::location_term( $state );
		}
		if ( ! empty( $request['state'] ) ) {
			$wanted = (string) $request['state'];
			$states = array_filter( $states, static fn( array $s ): bool => $s['slug'] === $wanted || strtoupper( $wanted ) === $s['state_code'] );
		}

		[ $items ] = ResponseCache::remember(
			'/cities',
			$request->get_params(),
			function () use ( $request, $states ): array {
				$items = array();
				foreach ( $states as $state_id => $state ) {
					foreach ( $this->terms( Location::SLUG, array( 'parent' => $state_id ) ) as $city ) {
						$counts = $this->counts( Location::SLUG, $city->term_id );
						if ( $request['hide_empty'] && 0 === $counts['lawyerCount'] + $counts['lawFirmCount'] ) {
							continue;
						}
						$location = LocationMapper::build( EntityRepository::location_term( $city ), $state );
						$items[]  = array(
							'id'      => (int) $city->term_id,
							'slug'    => $city->slug,
							'name'    => $city->name,
							'path'    => '/cities/' . $city->slug . '/',
							'state'   => array(
								'slug' => $location['stateSlug'],
								'name' => $location['state'],
								'code' => $location['stateCode'],
							),
							'content' => TermContent::dto( (int) $city->term_id ),
						) + $counts;
					}
				}//end foreach
				return $items;
			}
		);
		return $this->collection_response( $items, count( $items ), max( 1, count( $items ) ) );
	}

	/**
	 * Practice areas.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function practice_areas( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		[ $items ] = ResponseCache::remember(
			'/practice-areas',
			$request->get_params(),
			function () use ( $request ): array {
				$items = array();
				foreach ( $this->terms( PracticeArea::SLUG ) as $term ) {
					$counts = $this->counts( PracticeArea::SLUG, $term->term_id );
					if ( $request['hide_empty'] && 0 === $counts['lawyerCount'] + $counts['lawFirmCount'] ) {
						continue;
					}
					$items[] = array(
						'id'          => (int) $term->term_id,
						'slug'        => $term->slug,
						'name'        => $term->name,
						'description' => $term->description,
						'path'        => '/practice-areas/' . $term->slug . '/',
						'content'     => TermContent::dto( (int) $term->term_id ),
					) + $counts;
				}
				return $items;
			}
		);
		return $this->collection_response( $items, count( $items ), max( 1, count( $items ) ) );
	}

	/**
	 * Terms sorted by name.
	 *
	 * @param string               $taxonomy Taxonomy.
	 * @param array<string, mixed> $args     Extra args.
	 * @return array<int, \WP_Term>
	 */
	private function terms( string $taxonomy, array $args = array() ): array {
		$terms = get_terms(
			array_merge(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
				),
				$args
			)
		);
		return is_array( $terms ) ? array_values( array_filter( $terms, static fn( $t ): bool => $t instanceof \WP_Term ) ) : array();
	}

	/**
	 * Published lawyer / firm counts for a term (including child terms).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 * @return array{lawyerCount: int, lawFirmCount: int}
	 */
	private function counts( string $taxonomy, int $term_id ): array {
		$count = static function ( string $post_type ) use ( $taxonomy, $term_id ): int {
			$query = new \WP_Query(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => $taxonomy,
							'field'    => 'term_id',
							'terms'    => array( $term_id ),
						),
					),
				)
			);
			return (int) $query->found_posts;
		};
		return array(
			'lawyerCount'  => $count( Lawyer::SLUG ),
			'lawFirmCount' => $count( LawFirm::SLUG ),
		);
	}
}
