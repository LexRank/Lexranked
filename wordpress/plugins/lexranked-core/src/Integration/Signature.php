<?php
/**
 * Webhook signatures.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Integration;

/**
 * HMAC-SHA256 signatures for webhooks sent to the frontend:
 * header `X-LexRanked-Signature: t=<unix>,v1=<hex>` over "<t>.<body>".
 * The timestamp bounds replay; the frontend verifies with the same secret
 * (frontend/lib/revalidate/signature.ts). Pure logic.
 */
final class Signature {

	public const HEADER = 'X-LexRanked-Signature';

	/** Minimum secret length (bytes of entropy expected from a random value). */
	public const MIN_SECRET_LENGTH = 32;

	/**
	 * Header value for a body.
	 *
	 * @param string $secret    Shared secret.
	 * @param int    $timestamp Unix time.
	 * @param string $body      Raw request body.
	 */
	public static function header( string $secret, int $timestamp, string $body ): string {
		return 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Verify a header value (used in tests and by any PHP receiver).
	 *
	 * @param string $secret  Shared secret.
	 * @param string $header  Header value.
	 * @param string $body    Raw body.
	 * @param int    $now     Current unix time.
	 * @param int    $max_age Seconds a signature stays valid.
	 */
	public static function verify( string $secret, string $header, string $body, int $now, int $max_age = 300 ): bool {
		if ( ! preg_match( '/^t=(\d{1,12}),v1=([0-9a-f]{64})$/', $header, $m ) ) {
			return false;
		}
		$t = (int) $m[1];
		if ( abs( $now - $t ) > $max_age ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $t . '.' . $body, $secret ), $m[2] );
	}

	/**
	 * Whether a secret is long enough to use.
	 *
	 * @param string $secret Secret.
	 */
	public static function usable( string $secret ): bool {
		return strlen( $secret ) >= self::MIN_SECRET_LENGTH;
	}
}
