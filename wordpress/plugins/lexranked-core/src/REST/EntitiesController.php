<?php
/**
 * Lawyers and law firms endpoints.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\PostType;
use LexRanked\Core\Services;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * GET /lawyers, /lawyers/{id}, /law-firms, /law-firms/{id}
 */
final class EntitiesController extends RestController {

	private const ORDERBY = array( 'score', 'name', 'rating', 'review_count', 'updated' );

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
		foreach ( array(
			'lawyers'   => $this->services->lawyer,
			'law-firms' => $this->services->law_firm,
		) as $base => $type ) {
			$filters = array(
				'state'         => array_merge( $this->slug_arg( 'State slug (e.g. florida) or 2-letter code.' ), array( 'pattern' => '^([a-z0-9]+(-[a-z0-9]+)*|[A-Za-z]{2})$' ) ),
				'city'          => $this->slug_arg( 'City slug.' ),
				'practice_area' => $this->slug_arg( 'Practice area slug.' ),
				'has_score'     => array(
					'type'              => 'boolean',
					'validate_callback' => 'rest_validate_request_arg',
				),
			);
			if ( Lawyer::SLUG === $type->slug() ) {
				$filters['firm'] = array(
					'type'              => 'integer',
					'minimum'           => 1,
					'validate_callback' => 'rest_validate_request_arg',
				);
			}

			register_rest_route(
				Plugin::REST_NAMESPACE,
				'/' . $base,
				array(
					'methods'             => 'GET',
					'callback'            => fn( \WP_REST_Request $r ) => $this->list_items( $r, $type ),
					'permission_callback' => array( $this, 'public_read_permission' ),
					'args'                => $this->collection_args( self::ORDERBY, $filters ),
				)
			);
			register_rest_route(
				Plugin::REST_NAMESPACE,
				'/' . $base . '/' . $this->id_pattern(),
				array(
					'methods'             => 'GET',
					'callback'            => fn( \WP_REST_Request $r ) => $this->get_item( $r, $type ),
					'permission_callback' => array( $this, 'public_read_permission' ),
					'args'                => $this->context_arg(),
				)
			);
		}//end foreach
	}

	/**
	 * List endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param PostType         $type    Post type.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_items( \WP_REST_Request $request, PostType $type ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}

		$args = array(
			'post_type'        => $type->slug(),
			'post_status'      => 'publish',
			'posts_per_page'   => (int) $request['per_page'],
			'paged'            => (int) $request['page'],
			'suppress_filters' => false,
		);

		$tax_query = array();
		foreach ( array( 'state', 'city' ) as $level ) {
			if ( empty( $request[ $level ] ) ) {
				continue;
			}
			$term = $this->find_location( (string) $request[ $level ], $level );
			if ( null === $term ) {
				return $this->collection_response( array(), 0, (int) $request['per_page'] );
			}
			$tax_query[] = array(
				'taxonomy' => Location::SLUG,
				'field'    => 'term_id',
				'terms'    => array( $term->term_id ),
			);
		}
		if ( ! empty( $request['practice_area'] ) ) {
			$tax_query[] = array(
				'taxonomy' => PracticeArea::SLUG,
				'field'    => 'slug',
				'terms'    => array( (string) $request['practice_area'] ),
			);
		}
		if ( array() !== $tax_query ) {
			$args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax_query );
		}

		$meta_query = array();
		if ( ! empty( $request['firm'] ) ) {
			$meta_query[] = array(
				'key'   => $type->field( 'firm_id' )?->meta_key(),
				'value' => (int) $request['firm'],
				'type'  => 'NUMERIC',
			);
		}
		if ( null !== $request['has_score'] ) {
			$meta_query[] = array(
				'key'     => $type->field( 'score' )?->meta_key(),
				'compare' => $request['has_score'] ? 'EXISTS' : 'NOT EXISTS',
			);
		}

		$order = 'asc' === $request['order'] ? 'ASC' : 'DESC';
		switch ( $request['orderby'] ) {
			case 'name':
				$args['orderby'] = array(
					'title' => $order,
					'ID'    => 'ASC',
				);
				break;
			case 'updated':
				$args['orderby'] = array(
					'modified' => $order,
					'ID'       => 'ASC',
				);
				break;
			default:
				// Numeric meta sort that keeps entities without a value (listed last on DESC).
				$field                   = $type->field( 'score' === $request['orderby'] ? 'score' : (string) $request['orderby'] );
				$meta_query['sort_meta'] = array(
					'relation'   => 'OR',
					'sort_value' => array(
						'key'     => $field?->meta_key(),
						'compare' => 'EXISTS',
						'type'    => 'DECIMAL(10,2)',
					),
					array(
						'key'     => $field?->meta_key(),
						'compare' => 'NOT EXISTS',
					),
				);
				$args['orderby']         = array(
					'sort_value' => $order,
					'ID'         => 'ASC',
				);
		}//end switch
		if ( array() !== $meta_query ) {
			$args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta_query );
		}

		$query = new \WP_Query( $args );
		$posts = array_values( array_filter( $query->posts, static fn( $p ): bool => $p instanceof \WP_Post ) );
		$items = Lawyer::SLUG === $type->slug()
			? $this->services->presenter->lawyer_summaries( $posts )
			: $this->services->presenter->firm_summaries( $posts );

		return $this->collection_response( $items, (int) $query->found_posts, (int) $request['per_page'] );
	}

	/**
	 * Single item endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param PostType         $type    Post type.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $request, PostType $type ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$post = $this->services->entities->find_published( $type, (string) $request['id'] );
		if ( null === $post ) {
			return $this->not_found( $type->singular() );
		}
		$private = 'edit' === $request['context'];
		$item    = LawFirm::SLUG === $type->slug()
			? $this->services->presenter->firm_detail( $post )
			: $this->services->presenter->lawyer_detail( $post, $private );
		return $this->item_response( $item, $private );
	}

	/**
	 * Resolve a location filter value.
	 *
	 * @param string $value Slug or (for states) 2-letter code.
	 * @param string $level state|city.
	 */
	private function find_location( string $value, string $level ): ?\WP_Term {
		if ( 'state' === $level && 2 === strlen( $value ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => Location::SLUG,
					'hide_empty' => false,
					'parent'     => 0,
					'number'     => 1,
					'meta_query' => array(
						array(
							'key'   => Location::META_STATE,
							'value' => strtoupper( $value ),
						),
					),
				)
			);
			if ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ) {
				return $terms[0];
			}
		}
		$term = get_term_by( 'slug', strtolower( $value ), Location::SLUG );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		$is_state = 0 === (int) $term->parent;
		return ( 'state' === $level ) === $is_state ? $term : null;
	}
}
