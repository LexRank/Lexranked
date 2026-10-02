<?php
/**
 * WordPress hardening for a headless CMS.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Security;

use LexRanked\Core\Plugin;
use LexRanked\Core\Settings\Settings;

/**
 * - XML-RPC off (headless: the CMS is only used through wp-admin and REST),
 *   including the X-Pingback header. Toggle in Settings for tools that need it.
 * - No WordPress version in page/feed generator output.
 * - Security headers on every LexRanked REST response.
 */
final class Hardening {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		if ( $this->settings->get( 'disable_xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', static fn(): array => array() );
			add_filter(
				'wp_headers',
				static function ( array $headers ): array {
					unset( $headers['X-Pingback'] );
					return $headers;
				}
			);
		}
		add_filter( 'the_generator', '__return_empty_string' );
		add_filter( 'rest_post_dispatch', array( $this, 'rest_headers' ), 10, 3 );
	}

	/**
	 * Security headers on lexranked/v1 responses.
	 *
	 * @param mixed            $response Response.
	 * @param \WP_REST_Server  $server   Server.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function rest_headers( mixed $response, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		unset( $server );
		if ( $response instanceof \WP_REST_Response && str_starts_with( $request->get_route(), '/' . Plugin::REST_NAMESPACE . '/' ) ) {
			$response->header( 'X-Content-Type-Options', 'nosniff' );
			$response->header( 'X-Frame-Options', 'DENY' );
			$response->header( 'Referrer-Policy', 'no-referrer' );
			$response->header( 'Cross-Origin-Resource-Policy', 'same-site' );
		}
		return $response;
	}
}
