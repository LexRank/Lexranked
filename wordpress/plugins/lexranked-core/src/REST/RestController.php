<?php
/**
 * Shared REST controller behaviour.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Plugin;

/**
 * Pagination, validation, permissions and response headers shared by all
 * lexranked/v1 controllers.
 */
abstract class RestController {

	public const MAX_PER_PAGE     = 100;
	public const DEFAULT_PER_PAGE = 20;

	/** Query params WordPress itself understands; always allowed. */
	private const GLOBAL_PARAMS = array( '_fields', '_envelope', '_embed', '_locale', '_method', 'rest_route' );

	/**
	 * Register routes. Hooked to rest_api_init.
	 */
	abstract public function register_routes(): void;

	/**
	 * Permission callback for public reads. `context=edit` (private fields)
	 * requires the edit_posts capability.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function public_read_permission( \WP_REST_Request $request ): bool|\WP_Error {
		if ( 'edit' === $request->get_param( 'context' ) && ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'lexranked_forbidden_context', 'You are not allowed to request the edit context.', array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Common collection args.
	 *
	 * @param array<int, string>   $orderby Allowed orderby values (first is default).
	 * @param array<string, mixed> $extra   Endpoint-specific args.
	 * @return array<string, mixed>
	 */
	protected function collection_args( array $orderby, array $extra = array() ): array {
		return array_merge(
			array(
				'page'     => array(
					'type'              => 'integer',
					'default'           => 1,
					'minimum'           => 1,
					'maximum'           => 10000,
					'sanitize_callback' => 'absint',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'per_page' => array(
					'type'              => 'integer',
					'default'           => self::DEFAULT_PER_PAGE,
					'minimum'           => 1,
					'maximum'           => self::MAX_PER_PAGE,
					'sanitize_callback' => 'absint',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'orderby'  => array(
					'type'              => 'string',
					'default'           => $orderby[0],
					'enum'              => $orderby,
					'validate_callback' => 'rest_validate_request_arg',
				),
				'order'    => array(
					'type'              => 'string',
					'default'           => 'desc',
					'enum'              => array( 'asc', 'desc' ),
					'validate_callback' => 'rest_validate_request_arg',
				),
			),
			$this->context_arg(),
			$extra
		);
	}

	/**
	 * The `context` arg (view|edit).
	 *
	 * @return array<string, mixed>
	 */
	protected function context_arg(): array {
		return array(
			'context' => array(
				'type'              => 'string',
				'default'           => 'view',
				'enum'              => array( 'view', 'edit' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Slug/ID filter arg.
	 *
	 * @param string $description Description.
	 * @return array<string, mixed>
	 */
	protected function slug_arg( string $description ): array {
		return array(
			'type'              => 'string',
			'description'       => $description,
			'pattern'           => '^[a-z0-9]+(-[a-z0-9]+)*$',
			'maxLength'         => 96,
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Route pattern for {id}: numeric ID or slug.
	 */
	protected function id_pattern(): string {
		return '(?P<id>[a-z0-9]+(?:-[a-z0-9]+)*)';
	}

	/**
	 * Reject query params that are not registered for the route.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	protected function reject_unknown_params( \WP_REST_Request $request ): ?\WP_Error {
		$attributes = $request->get_attributes();
		$allowed    = array_merge( array_keys( $attributes['args'] ?? array() ), self::GLOBAL_PARAMS, array( 'id' ) );
		$unknown    = array_diff( array_keys( $request->get_query_params() ), $allowed );
		if ( array() !== $unknown ) {
			return new \WP_Error(
				'lexranked_invalid_param',
				'Unknown parameter(s): ' . implode( ', ', array_map( 'sanitize_key', $unknown ) ),
				array( 'status' => 400 )
			);
		}
		return null;
	}

	/**
	 * Build a paginated collection response.
	 *
	 * @param array<int, mixed> $items    Items for this page.
	 * @param int               $total    Total items.
	 * @param int               $per_page Page size.
	 * @param bool              $is_private Whether the response contains private data.
	 */
	protected function collection_response( array $items, int $total, int $per_page, bool $is_private = false ): \WP_REST_Response {
		$response = new \WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( $per_page > 0 ? (int) ceil( $total / $per_page ) : 0 ) );
		$this->cache_headers( $response, $is_private );
		return $response;
	}

	/**
	 * Build a single-item response.
	 *
	 * @param array<string, mixed> $item       Item.
	 * @param bool                 $is_private Whether the response contains private data.
	 */
	protected function item_response( array $item, bool $is_private = false ): \WP_REST_Response {
		$response = new \WP_REST_Response( $item, 200 );
		$this->cache_headers( $response, $is_private );
		return $response;
	}

	/**
	 * Cache headers: public data may be cached briefly by CDNs; private never.
	 *
	 * @param \WP_REST_Response $response   Response.
	 * @param bool              $is_private Private.
	 */
	protected function cache_headers( \WP_REST_Response $response, bool $is_private ): void {
		$response->header( 'Cache-Control', $is_private ? 'private, no-store' : 'public, max-age=60, s-maxage=300' );
		$response->header( 'X-LexRanked-API', Plugin::API_VERSION );
	}

	/**
	 * Standard 404.
	 *
	 * @param string $what Entity label.
	 */
	protected function not_found( string $what ): \WP_Error {
		return new \WP_Error( 'lexranked_not_found', $what . ' not found.', array( 'status' => 404 ) );
	}

	/**
	 * Sanitize rendered HTML from post content.
	 *
	 * @param string $content Raw post content.
	 */
	protected function html( string $content ): string {
		return '' === trim( $content ) ? '' : wp_kses_post( wpautop( $content ) );
	}
}
