<?php
/**
 * REST API hardening.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Security;

use LexRanked\Core\Plugin;
use LexRanked\Core\REST\SearchController;
use LexRanked\Core\Settings\Settings;

/**
 * - Rate limits anonymous requests to lexranked/v1 (stricter for /search).
 * - Hides the core users endpoint from anonymous visitors (user enumeration).
 */
final class ApiGuard {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'rate_limit' ), 10, 3 );
		add_filter( 'rest_endpoints', array( $this, 'restrict_user_endpoints' ) );
	}

	/**
	 * Apply the rate limit before dispatch.
	 *
	 * @param mixed            $result  Pre-dispatch result.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function rate_limit( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		$route  = $request->get_route();
		$prefix = '/' . Plugin::REST_NAMESPACE;
		if ( null !== $result || ! str_starts_with( $route, $prefix ) || Capabilities::is_trusted_client() ) {
			return $result;
		}

		$is_search = $prefix . SearchController::ROUTE === $route;
		$limit     = (int) $this->settings->get( $is_search ? 'search_rate_per_minute' : 'rate_limit_per_minute' );
		$limiter   = new RateLimiter(
			static fn( string $key ): int => (int) get_transient( $key ),
			static function ( string $key, int $value, int $ttl ): void {
				set_transient( $key, $value, $ttl );
			}
		);
		$client    = RateLimiter::client_key( $_SERVER, (bool) $this->settings->get( 'trust_proxy_header' ), wp_salt( 'nonce' ) );
		$state     = $limiter->hit( ( $is_search ? 'search|' : 'api|' ) . $client, $limit, 60, time() );

		add_filter(
			'rest_post_dispatch',
			static function ( $response ) use ( $state ) {
				if ( $response instanceof \WP_REST_Response ) {
					$response->header( 'X-RateLimit-Limit', (string) $state['limit'] );
					$response->header( 'X-RateLimit-Remaining', (string) $state['remaining'] );
				}
				return $response;
			}
		);

		if ( ! $state['allowed'] ) {
			return new \WP_Error(
				'lexranked_rate_limited',
				'Too many requests. Please retry later.',
				array(
					'status'  => 429,
					'headers' => array( 'Retry-After' => (string) max( 1, $state['reset'] - time() ) ),
				)
			);
		}
		return $result;
	}

	/**
	 * Remove /wp/v2/users routes for anonymous requests.
	 *
	 * @param array<string, mixed> $endpoints Endpoints.
	 * @return array<string, mixed>
	 */
	public function restrict_user_endpoints( array $endpoints ): array {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( str_starts_with( (string) $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}
}
