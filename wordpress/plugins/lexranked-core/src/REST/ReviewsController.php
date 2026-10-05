<?php
/**
 * Client reviews endpoints.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Plugin;
use LexRanked\Core\Reviews\ReviewException;
use LexRanked\Core\Reviews\ReviewStatus;
use LexRanked\Core\Schema\ValidationException;
use LexRanked\Core\Security\Capabilities;
use LexRanked\Core\Services;

/**
 * POST /reviews and /reviews/confirm (the frontend server, like claims);
 * GET /editorial/reviews and POST /editorial/reviews/{id} (editors).
 * Approved reviews are published on the profile detail (clientReviews).
 */
final class ReviewsController extends RestController {

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
			: new \WP_Error( 'lexranked_forbidden', 'Reviews are submitted through the LexRanked website.', array( 'status' => rest_authorization_required_code() ) );
		$editor = static fn(): bool => current_user_can( 'edit_posts' );
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/reviews',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => $submit,
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/reviews/confirm',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'confirm' ),
				'permission_callback' => $submit,
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/editorial/reviews',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'queue' ),
				'permission_callback' => $editor,
				'args'                => array(
					'status' => array(
						'type'    => 'string',
						'enum'    => array_map( static fn( ReviewStatus $s ): string => $s->value, ReviewStatus::cases() ),
						'default' => ReviewStatus::PendingReview->value,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/editorial/reviews/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'moderate' ),
				'permission_callback' => $editor,
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'approve', 'reject' ),
					),
					'note'   => array(
						'type'      => 'string',
						'maxLength' => 500,
						'default'   => '',
					),
				),
			)
		);
	}

	/**
	 * Submit a review.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function submit( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		return $this->run( fn(): array => $this->services->reviews->submit( is_array( $body ) ? $body : array() ), 202 );
	}

	/**
	 * Confirm a reviewer's email.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function confirm( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		return $this->run( fn(): array => $this->services->reviews->confirm( is_array( $body ) ? (string) ( $body['token'] ?? '' ) : '' ) );
	}

	/**
	 * Moderation queue (no email addresses).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function queue( \WP_REST_Request $request ): \WP_REST_Response {
		$items    = array_map(
			static fn( array $r ): array => array(
				'id'          => (int) $r['review_id'],
				'entityType'  => (string) $r['entity_type'],
				'entityId'    => (int) $r['entity_id'],
				'profile'     => get_the_title( (int) $r['entity_id'] ),
				'status'      => (string) $r['status'],
				'rating'      => (int) $r['rating'],
				'title'       => (string) $r['title'],
				'body'        => (string) $r['body'],
				'author'      => (string) $r['display_name'],
				'serviceYear' => (int) $r['service_year'],
				'createdAt'   => (string) $r['created_at'],
			),
			$this->services->reviews->reviews->by_status( (string) $request['status'] )
		);
		$response = new \WP_REST_Response( $items, 200 );
		$this->cache_headers( $response, true );
		return $response;
	}

	/**
	 * Approve or reject a review.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function moderate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ): array {
				$id = (int) $request['id'];
				$this->services->reviews->moderate( $id, 'approve' === $request['action'], (string) $request['note'] );
				return array(
					'id'     => $id,
					'status' => (string) ( $this->services->reviews->reviews->find( $id )['status'] ?? '' ),
				);
			}
		);
	}

	/**
	 * Run and translate errors.
	 *
	 * @param \Closure $operation Operation.
	 * @param int      $status    Success status.
	 */
	private function run( \Closure $operation, int $status = 200 ): \WP_REST_Response|\WP_Error {
		try {
			$response = new \WP_REST_Response( $operation(), $status );
		} catch ( ValidationException $e ) {
			return new \WP_Error(
				'lexranked_invalid_review',
				$e->field_key . ' ' . $e->reason . '.',
				array(
					'status' => 400,
					'field'  => $e->field_key,
				)
			);
		} catch ( ReviewException $e ) {
			return new \WP_Error( $e->error_code, $e->getMessage(), array( 'status' => $e->status ) );
		}
		$this->cache_headers( $response, true );
		return $response;
	}
}
