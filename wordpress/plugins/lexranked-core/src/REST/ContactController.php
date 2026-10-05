<?php
/**
 * Contact form endpoint.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Contact\ContactException;
use LexRanked\Core\Contact\ContactService;
use LexRanked\Core\Plugin;
use LexRanked\Core\Security\Capabilities;

/**
 * POST /contact: the frontend server relays a visitor's message; it is
 * emailed to the editors and not stored.
 */
final class ContactController extends RestController {

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/contact',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send' ),
				'permission_callback' => static fn(): bool|\WP_Error => current_user_can( Capabilities::SUBMIT_CLAIMS ) || current_user_can( Capabilities::MANAGE )
					? true
					: new \WP_Error( 'lexranked_forbidden', 'Messages are sent through the LexRanked website.', array( 'status' => rest_authorization_required_code() ) ),
			)
		);
	}

	/**
	 * Send a message.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function send( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		try {
			$response = new \WP_REST_Response( ( new ContactService() )->send( is_array( $body ) ? $body : array() ), 202 );
		} catch ( ContactException $e ) {
			return new \WP_Error(
				$e->error_code,
				$e->getMessage(),
				array(
					'status' => $e->status,
					'errors' => $e->errors,
				)
			);
		}
		$this->cache_headers( $response, true );
		return $response;
	}
}
