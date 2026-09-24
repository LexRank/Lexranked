<?php
/**
 * Public API status endpoint.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Plugin;

/**
 * GET /wp-json/lexranked/v1/status
 *
 * Used by deploy checks and the admin "API status" screen. Returns only
 * non-sensitive version information; never configuration or credentials.
 */
final class StatusController {

	/**
	 * Plugin version reported by the endpoint.
	 *
	 * @var string
	 */
	private string $plugin_version;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_version Plugin version string.
	 */
	public function __construct( string $plugin_version ) {
		$this->plugin_version = $plugin_version;
	}

	/**
	 * Register the route. Hooked to rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				// Public by design: the payload contains no private data.
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
	}

	/**
	 * Route callback.
	 */
	public function handle(): \WP_REST_Response {
		$response = new \WP_REST_Response( $this->payload(), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Build the response body (pure; unit-testable without WordPress).
	 *
	 * @return array{status: string, service: string, pluginVersion: string, apiVersion: string, namespace: string}
	 */
	public function payload(): array {
		return array(
			'status'        => 'ok',
			'service'       => 'lexranked-core',
			'pluginVersion' => $this->plugin_version,
			'apiVersion'    => Plugin::API_VERSION,
			'namespace'     => Plugin::REST_NAMESPACE,
		);
	}
}
