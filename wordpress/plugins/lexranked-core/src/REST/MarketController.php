<?php
/**
 * Market statistics endpoint (Etap I).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Market\MarketStatistics;
use LexRanked\Core\Plugin;
use LexRanked\Core\Repository\EntityRepository;
use LexRanked\Core\Services;
use LexRanked\Core\Support\ResponseCache;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * GET /market?location=miami&practice_area=personal-injury — statistics for a
 * market, computed from stored data (counts, verified counts, average rating,
 * median review count, most common practice area, data verification date),
 * each with its sample size, plus a template summary. Unknown slugs are 404.
 */
final class MarketController extends RestController {

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
			'/market',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'market' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => array(
					'location'      => $this->slug_arg( 'State or city slug.' ),
					'practice_area' => $this->slug_arg( 'Practice area slug.' ),
				),
			)
		);
	}

	/**
	 * Market statistics.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function market( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$location = null;
		$practice = null;
		if ( ! empty( $request['location'] ) ) {
			$location = get_term_by( 'slug', (string) $request['location'], Location::SLUG );
			if ( ! $location instanceof \WP_Term ) {
				return $this->not_found( 'Location' );
			}
		}
		if ( ! empty( $request['practice_area'] ) ) {
			$practice = get_term_by( 'slug', (string) $request['practice_area'], PracticeArea::SLUG );
			if ( ! $practice instanceof \WP_Term ) {
				return $this->not_found( 'Practice area' );
			}
		}
		[ $body ] = ResponseCache::remember(
			'/market',
			$request->get_params(),
			function () use ( $location, $practice ): array {
				$stats = $this->services->market->for_scope( $location, $practice );
				$place = $this->place( $location );
				$noun  = null === $practice ? '' : strtolower( $practice->name ) . ' ';
				$scope = null === $practice && '' === $place ? '' : trim( ( '' === $noun ? '' : 'in ' . $noun . 'law' ) . ( '' === $place ? '' : ' in ' . $place ) );
				return array(
					'scope'   => array(
						'location'     => null === $location ? null : array(
							'slug' => $location->slug,
							'name' => $place,
							'type' => 0 === (int) $location->parent ? 'state' : 'city',
						),
						'practiceArea' => null === $practice ? null : array(
							'slug' => $practice->slug,
							'name' => $practice->name,
						),
					),
					'stats'   => $stats,
					'summary' => MarketStatistics::summary( $stats, $scope, $place, null === $practice ),
				);
			}
		);
		return $this->item_response( $body );
	}

	/**
	 * "Miami, Florida" or "Florida".
	 *
	 * @param \WP_Term|null $term Location term.
	 */
	private function place( ?\WP_Term $term ): string {
		if ( null === $term ) {
			return '';
		}
		if ( 0 === (int) $term->parent ) {
			return (string) ( EntityRepository::location_term( $term )['name'] ?? $term->name );
		}
		$state = get_term( (int) $term->parent, Location::SLUG );
		return $term->name . ( $state instanceof \WP_Term ? ', ' . $state->name : '' );
	}
}
