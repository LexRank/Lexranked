<?php
/**
 * Fixed-window rate limiter.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Security;

/**
 * Counts hits per key in fixed windows. Storage is injected (WordPress
 * transients in production, an array in tests).
 */
final class RateLimiter {

	/**
	 * Constructor.
	 *
	 * @param \Closure $get Read a counter: fn( string $key ): int (0 when absent).
	 * @param \Closure $set Write a counter: fn( string $key, int $value, int $ttl ): void.
	 */
	public function __construct(
		private readonly \Closure $get,
		private readonly \Closure $set
	) {
	}

	/**
	 * Register a hit and report whether it is allowed.
	 *
	 * @param string $key    Client key (already anonymised).
	 * @param int    $limit  Max hits per window.
	 * @param int    $window Window length in seconds.
	 * @param int    $now    Current UNIX time.
	 * @return array{allowed: bool, limit: int, remaining: int, reset: int}
	 */
	public function hit( string $key, int $limit, int $window, int $now ): array {
		$window_start = $now - ( $now % $window );
		$bucket       = 'lr_rl_' . md5( $key . '|' . $window_start );
		$count        = (int) ( $this->get )( $bucket ) + 1;
		( $this->set )( $bucket, $count, $window );

		return array(
			'allowed'   => $count <= $limit,
			'limit'     => $limit,
			'remaining' => max( 0, $limit - $count ),
			'reset'     => $window_start + $window,
		);
	}

	/**
	 * Anonymised client key: hashes the IP so raw addresses are never stored.
	 *
	 * @param array<string, mixed> $server        $_SERVER-like array.
	 * @param bool                 $trust_proxy   Trust CF-Connecting-IP (only behind Cloudflare).
	 * @param string               $salt          Site-specific salt.
	 */
	public static function client_key( array $server, bool $trust_proxy, string $salt ): string {
		$ip = '';
		if ( $trust_proxy && ! empty( $server['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = (string) $server['HTTP_CF_CONNECTING_IP'];
		} elseif ( ! empty( $server['REMOTE_ADDR'] ) ) {
			$ip = (string) $server['REMOTE_ADDR'];
		}
		$ip = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
		return hash_hmac( 'sha256', $ip, $salt );
	}
}
