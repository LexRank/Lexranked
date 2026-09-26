<?php
/**
 * Research job scheduling rules.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

use LexRanked\Core\Domain\ResearchJobStatus;

/**
 * Pure decisions about a job's lifecycle, kept out of JobService so they can
 * be unit-tested without WordPress:
 *
 * - which jobs a worker may claim (fresh, due retry, or abandoned lease),
 * - what a failure turns into (scheduled retry or final failure),
 * - how worker-reported progress is sanitized.
 */
final class JobPolicy {

	public const CLAIM_FRESH  = 'fresh';
	public const CLAIM_RETRY  = 'retry';
	public const CLAIM_RESUME = 'resume';

	/** A running job whose lease expired and has no retries left. */
	public const EXHAUSTED = 'exhausted';

	public const MAX_STATS_KEYS = 30;

	/**
	 * Why a job can be claimed now, EXHAUSTED when an abandoned job must be
	 * failed for good, or null when it is not claimable.
	 *
	 * @param array<string, mixed> $fields  Job fields (status, retry_count, next_retry_at, locked_until).
	 * @param Backoff              $backoff Retry policy.
	 * @param \DateTimeImmutable   $now     Current time.
	 */
	public static function claimability( array $fields, Backoff $backoff, \DateTimeImmutable $now ): ?string {
		$status  = (string) ( $fields['status'] ?? '' );
		$retries = (int) ( $fields['retry_count'] ?? 0 );

		if ( ResearchJobStatus::Pending->value === $status ) {
			return self::CLAIM_FRESH;
		}
		if ( ResearchJobStatus::Failed->value === $status ) {
			$due = self::time( $fields['next_retry_at'] ?? null );
			return ( null !== $due && $due <= $now ) ? self::CLAIM_RETRY : null;
		}
		if ( ResearchJobStatus::Running->value === $status ) {
			$lease = self::time( $fields['locked_until'] ?? null );
			if ( null !== $lease && $lease > $now ) {
				return null;
				// Another worker holds a valid lease.
			}
			return $backoff->can_retry( $retries ) ? self::CLAIM_RESUME : self::EXHAUSTED;
		}
		return null;
	}

	/**
	 * Outcome of a failure: the new retry_count and when to retry (null = final).
	 *
	 * @param int                $retry_count Retries consumed so far.
	 * @param bool               $retryable   Whether the worker considers the error transient.
	 * @param Backoff            $backoff     Retry policy.
	 * @param \DateTimeImmutable $now         Current time.
	 * @return array{retry_count: int, next_retry_at: string|null}
	 */
	public static function after_failure( int $retry_count, bool $retryable, Backoff $backoff, \DateTimeImmutable $now ): array {
		if ( ! $retryable || ! $backoff->can_retry( $retry_count ) ) {
			return array(
				'retry_count'   => $retry_count,
				'next_retry_at' => null,
			);
		}
		$attempt = $retry_count + 1;
		return array(
			'retry_count'   => $attempt,
			'next_retry_at' => $now->modify( '+' . $backoff->delay( $attempt ) . ' seconds' )->format( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	/**
	 * Sanitize worker-reported progress.
	 *
	 * @param array<string, mixed> $progress        Raw {cursor?, processed_count?, stats?}.
	 * @param int                  $processed_count Current processed_count (never decreases).
	 * @return array{cursor?: string|null, processed_count?: int, stats?: array<string, int>}
	 */
	public static function progress( array $progress, int $processed_count ): array {
		$out = array();
		if ( array_key_exists( 'cursor', $progress ) ) {
			$cursor        = null === $progress['cursor'] ? '' : (string) $progress['cursor'];
			$cursor        = substr( (string) preg_replace( '/[^\x21-\x7E]/', '', $cursor ), 0, 255 );
			$out['cursor'] = '' === $cursor ? null : $cursor;
		}
		if ( isset( $progress['processed_count'] ) && is_numeric( $progress['processed_count'] ) ) {
			$out['processed_count'] = max( $processed_count, min( PHP_INT_MAX, max( 0, (int) $progress['processed_count'] ) ) );
		}
		if ( isset( $progress['stats'] ) && is_array( $progress['stats'] ) ) {
			$stats = array();
			foreach ( $progress['stats'] as $key => $value ) {
				if ( count( $stats ) >= self::MAX_STATS_KEYS ) {
					break;
				}
				if ( is_string( $key ) && preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $key ) && is_numeric( $value ) ) {
					$stats[ $key ] = max( 0, (int) $value );
				}
			}
			ksort( $stats );
			$out['stats'] = $stats;
		}
		return $out;
	}

	/**
	 * Decode job params (JSON object text). Null when invalid.
	 *
	 * @param mixed $raw Stored value.
	 * @return array<string, mixed>|null
	 */
	public static function params( mixed $raw ): ?array {
		if ( null === $raw || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) || ( array() !== $decoded && array_is_list( $decoded ) ) ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Parse a stored ISO datetime.
	 *
	 * @param mixed $value Value.
	 */
	private static function time( mixed $value ): ?\DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
