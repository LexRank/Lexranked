<?php
/**
 * Search endpoint.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\REST\DTO\LocationMapper;
use LexRanked\Core\Services;
use LexRanked\Core\Support\Text;

/**
 * GET /search?q=… — name search across lawyers and law firms.
 * Search results are never indexable pages; stricter rate limit applies.
 */
final class SearchController extends RestController {

	public const ROUTE = '/search';

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
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'        => array(
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 2,
						'maxLength'         => 100,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'type'     => array(
						'type'              => 'string',
						'default'           => 'all',
						'enum'              => array( 'all', 'lawyer', 'law_firm' ),
						'validate_callback' => 'rest_validate_request_arg',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 20,
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	/**
	 * Run a search.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$types = match ( $request['type'] ) {
			'lawyer' => array( Lawyer::SLUG ),
			'law_firm' => array( LawFirm::SLUG ),
			default => array( Lawyer::SLUG, LawFirm::SLUG ),
		};
		$query = new \WP_Query(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				's'                => (string) $request['q'],
				'search_columns'   => array( 'post_title' ),
				'posts_per_page'   => (int) $request['per_page'],
				'orderby'          => array(
					'relevance' => 'DESC',
					'ID'        => 'ASC',
				),
				'suppress_filters' => false,
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$is_lawyer = Lawyer::SLUG === $post->post_type;
			$items[]   = array(
				'type'     => $is_lawyer ? 'lawyer' : 'law_firm',
				'id'       => (int) $post->ID,
				'slug'     => $post->post_name,
				'name'     => Text::title( $post ),
				'path'     => ( $is_lawyer ? '/lawyers/' : '/law-firms/' ) . $post->post_name . '/',
				'location' => LocationMapper::from_terms( $this->services->entities->location_terms( $post->ID ) ),
			);
		}
		$response = $this->collection_response( $items, (int) $query->found_posts, (int) $request['per_page'] );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}
}
