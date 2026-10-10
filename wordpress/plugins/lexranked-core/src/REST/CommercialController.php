<?php
/**
 * Claims and placements endpoints.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Commercial\CommercialException;
use LexRanked\Core\Commercial\Product;
use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\Schema\ValidationException;
use LexRanked\Core\Security\Capabilities;
use LexRanked\Core\Services;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * POST /claims, POST /claims/confirm - called by the frontend server on
 *   behalf of visitors (lexranked_api role). Responses never reveal whether a
 *   profile is already claimed or whether an email was used before.
 * GET  /placements - public: the labelled featured / sponsored block of one
 *   page. Kept out of ranking entries by design.
 */
final class CommercialController extends RestController {

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
		$submit = static fn(): bool|\WP_Error => current_user_can( Capabilities::SUBMIT_CLAIMS ) || current_user_can( Capabilities::MANAGE )
			? true
			: new \WP_Error( 'lexranked_forbidden', 'Claims are submitted through the LexRanked website.', array( 'status' => rest_authorization_required_code() ) );
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/claims',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => $submit,
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/claims/confirm',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'confirm' ),
				'permission_callback' => $submit,
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/placements',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'placements' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => array(
					'product'       => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( Product::Featured->value, Product::Sponsored->value ),
						'validate_callback' => 'rest_validate_request_arg',
					),
					'ranking'       => array(
						'type'              => 'integer',
						'minimum'           => 1,
						'validate_callback' => 'rest_validate_request_arg',
					),
					'location'      => $this->slug_arg( 'State or city slug (featured).' ),
					'practice_area' => $this->slug_arg( 'Practice area slug (featured).' ),
				),
			)
		);
	}

	/**
	 * Submit a claim.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function submit( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->services->settings->get( 'claims_enabled' ) ) {
			return new \WP_Error( 'lexranked_claims_closed', 'Profile claims are temporarily closed.', array( 'status' => 503 ) );
		}
		$body = $request->get_json_params();
		return $this->run( fn(): array => $this->services->commercial->submit_claim( is_array( $body ) ? $body : array() ), 202 );
	}

	/**
	 * Confirm a claimant's email.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function confirm( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		return $this->run( fn(): array => $this->services->commercial->confirm_email( is_array( $body ) ? (string) ( $body['token'] ?? '' ) : '' ) );
	}

	/**
	 * Featured or sponsored placements of one page.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function placements( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$product = (string) $request['product'];
		if ( Product::Sponsored->value === $product ) {
			$ranking = get_post( (int) $request['ranking'] );
			if ( ! $ranking instanceof \WP_Post || Ranking::SLUG !== $ranking->post_type || 'publish' !== $ranking->post_status ) {
				return new \WP_Error( 'lexranked_invalid_param', 'Sponsored placements need a published ranking ID.', array( 'status' => 400 ) );
			}
			$items = $this->services->commercial->for_page( $product, $ranking->ID );
		} else {
			$location = empty( $request['location'] ) ? null : get_term_by( 'slug', (string) $request['location'], Location::SLUG );
			$practice = empty( $request['practice_area'] ) ? null : get_term_by( 'slug', (string) $request['practice_area'], PracticeArea::SLUG );
			if ( ( null === $location ) === ( null === $practice ) || false === $location || false === $practice ) {
				return new \WP_Error( 'lexranked_invalid_param', 'Featured placements need exactly one existing location or practice area.', array( 'status' => 400 ) );
			}
			$items = $this->services->commercial->for_page( $product, 0, $location instanceof \WP_Term ? $location->term_id : 0, $practice instanceof \WP_Term ? $practice->term_id : 0 );
		}
		return $this->collection_response( $items, count( $items ), max( 1, count( $items ) ) );
	}

	/**
	 * Run an operation and map domain errors to REST errors.
	 *
	 * @param \Closure $operation Returns the response body.
	 * @param int      $status    Success status.
	 */
	private function run( \Closure $operation, int $status = 200 ): \WP_REST_Response|\WP_Error {
		try {
			$response = new \WP_REST_Response( $operation(), $status );
		} catch ( ValidationException $e ) {
			return new \WP_Error(
				'lexranked_invalid_claim',
				$e->field_key . ' ' . $e->reason . '.',
				array(
					'status' => 400,
					'field'  => $e->field_key,
				)
			);
		} catch ( CommercialException $e ) {
			return new \WP_Error( $e->error_code, $e->getMessage(), array( 'status' => $e->status ) );
		}
		$this->cache_headers( $response, true );
		return $response;
	}
}
