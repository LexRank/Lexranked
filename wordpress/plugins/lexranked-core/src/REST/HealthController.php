<?php
/**
 * Private health endpoint.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Monitoring\HealthCheck;
use LexRanked\Core\Plugin;
use LexRanked\Core\Security\Capabilities;
use LexRanked\Core\Services;

/**
 * GET /health - operational checks for monitoring (frontend /api/health,
 * uptime services). Requires the API role or an administrator; returns 503
 * when a check is critical so simple monitors can alert on the status code.
 */
final class HealthController extends RestController {

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
			'/health',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => static fn(): bool|\WP_Error => current_user_can( Capabilities::API_READ ) || current_user_can( Capabilities::MANAGE )
					? true
					: new \WP_Error( 'lexranked_forbidden', 'Health details require the LexRanked API role.', array( 'status' => rest_authorization_required_code() ) ),
			)
		);
	}

	/**
	 * Report.
	 */
	public function show(): \WP_REST_Response {
		$report   = $this->services->health->report();
		$response = $this->item_response(
			$report + array(
				'version'    => LEXRANKED_CORE_VERSION,
				'apiVersion' => Plugin::API_VERSION,
				'time'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
			),
			true
		);
		$response->set_status( HealthCheck::CRITICAL === $report['status'] ? 503 : 200 );
		return $response;
	}
}
