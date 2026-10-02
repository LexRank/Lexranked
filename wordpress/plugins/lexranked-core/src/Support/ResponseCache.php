<?php
/**
 * Cache for expensive public API responses.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Support;

/**
 * Caches the body of expensive public collection responses (taxonomy
 * counts, ranking and article lists) in transients / the object cache.
 * Keys embed the content version, so any content change invalidates them;
 * the TTL only bounds staleness from changes made outside WordPress hooks.
 * Private (authenticated, edit-context) responses are never cached.
 */
final class ResponseCache {

	public const TTL = 3600;

	/**
	 * Deterministic cache key for a route and its parameters.
	 *
	 * @param string               $route   Route (e.g. "/cities").
	 * @param array<string, mixed> $params  Request parameters.
	 * @param int                  $version Content version.
	 */
	public static function key( string $route, array $params, int $version ): string {
		ksort( $params );
		return 'lr_rc_' . md5( $version . '|' . $route . '|' . (string) json_encode( $params ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-independent by design.
	}

	/**
	 * Return the cached body or compute and store it.
	 *
	 * @param string               $route   Route.
	 * @param array<string, mixed> $params  Parameters that change the response.
	 * @param callable             $compute Returns the response body (array).
	 * @return array{0: mixed, 1: bool} Body and whether it came from the cache.
	 */
	public static function remember( string $route, array $params, callable $compute ): array {
		$key    = self::key( $route, $params, ContentVersion::get() );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && array_key_exists( 'body', $cached ) ) {
			return array( $cached['body'], true );
		}
		$body = $compute();
		set_transient( $key, array( 'body' => $body ), self::TTL );
		return array( $body, false );
	}
}
