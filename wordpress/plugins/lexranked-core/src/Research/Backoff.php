<?php
/**
 * Retry backoff policy.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

/**
 * Exponential backoff for failed research jobs: base × 2^(attempt − 1),
 * capped. Deterministic (no jitter server-side; workers add their own jitter
 * for HTTP retries).
 */
final class Backoff {

	/**
	 * Constructor.
	 *
	 * @param int $base_seconds First retry delay.
	 * @param int $max_seconds  Upper bound.
	 * @param int $max_retries  Automatic retries before a job stays failed.
	 */
	public function __construct(
		public readonly int $base_seconds = 300,
		public readonly int $max_seconds = 21600,
		public readonly int $max_retries = 3
	) {
	}

	/**
	 * Delay before retry number $attempt (1-based).
	 *
	 * @param int $attempt Attempt number.
	 */
	public function delay( int $attempt ): int {
		$attempt = max( 1, $attempt );
		return (int) min( $this->max_seconds, $this->base_seconds * ( 2 ** min( 20, $attempt - 1 ) ) );
	}

	/**
	 * Whether another automatic retry is allowed after $retry_count retries.
	 *
	 * @param int $retry_count Retries already scheduled.
	 */
	public function can_retry( int $retry_count ): bool {
		return $retry_count < $this->max_retries;
	}
}
