<?php
/**
 * Health evaluation.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Monitoring;

/**
 * Turns raw operational facts into named checks with a severity. Pure logic:
 * HealthService gathers the facts from WordPress, this decides what they mean.
 */
final class HealthCheck {

	public const OK       = 'ok';
	public const WARNING  = 'warning';
	public const CRITICAL = 'critical';

	private const RANK = array(
		self::OK       => 0,
		self::WARNING  => 1,
		self::CRITICAL => 2,
	);

	/**
	 * Evaluate.
	 *
	 * @param array<string, mixed> $f   Facts: schema_version, expected_schema, cron_next (int|null seconds),
	 *                                  cron_disabled (bool), last_calculation (ISO|null), published_rankings (int),
	 *                                  jobs_stuck (int), jobs_failed (int), review_claims (int), review_candidates (int),
	 *                                  claims_in_review (int), claims_oldest_review (UTC datetime|null),
	 *                                  revalidation_configured (bool), revalidation (array|null), debug_display (bool).
	 * @param \DateTimeImmutable   $now Current time.
	 * @return array{status: string, checks: array<int, array{key: string, status: string, message: string}>}
	 */
	public static function evaluate( array $f, \DateTimeImmutable $now ): array {
		$checks = array();
		$add    = static function ( string $key, string $status, string $message ) use ( &$checks ): void {
			$checks[] = array(
				'key'     => $key,
				'status'  => $status,
				'message' => $message,
			);
		};

		// Database schema.
		if ( (string) $f['schema_version'] !== (string) $f['expected_schema'] ) {
			$add( 'database', self::CRITICAL, sprintf( 'Schema version %s, expected %s: load wp-admin once or run wp lexranked status to migrate.', (string) $f['schema_version'], (string) $f['expected_schema'] ) );
		} else {
			$add( 'database', self::OK, 'Schema version ' . (string) $f['schema_version'] . '.' );
		}

		// Scheduling.
		if ( null === $f['cron_next'] ) {
			$add( 'scheduler', self::CRITICAL, 'The daily recalculation is not scheduled; reactivate the plugin.' );
		} elseif ( (int) $f['cron_next'] < -3600 ) {
			$add( 'scheduler', self::WARNING, 'Scheduled events are more than an hour overdue: WP-cron is not running' . ( $f['cron_disabled'] ? ' (DISABLE_WP_CRON is set; run wp cron event run --due-now from system cron)' : '' ) . '.' );
		} else {
			$add( 'scheduler', self::OK, 'Recalculation scheduled.' );
		}

		// Freshness of scores.
		$last = null;
		if ( is_string( $f['last_calculation'] ) && '' !== $f['last_calculation'] ) {
			try {
				$last = new \DateTimeImmutable( $f['last_calculation'] );
			} catch ( \Exception $e ) {
				$last = null;
			}
		}
		if ( (int) $f['published_rankings'] > 0 && null === $last ) {
			$add( 'scores', self::WARNING, 'Scores have not been calculated since this check was added; run wp lexranked recalculate.' );
		} elseif ( null !== $last ) {
			$hours  = ( $now->getTimestamp() - $last->getTimestamp() ) / 3600;
			$status = $hours > 72 ? self::CRITICAL : ( $hours > 36 ? self::WARNING : self::OK );
			$add( 'scores', $status, sprintf( 'Last full calculation %s UTC (%d h ago).', $last->format( 'Y-m-d H:i' ), (int) floor( $hours ) ) );
		} else {
			$add( 'scores', self::OK, 'No published rankings yet.' );
		}

		// Research jobs.
		$stuck  = (int) $f['jobs_stuck'];
		$failed = (int) $f['jobs_failed'];
		if ( $stuck + $failed > 0 ) {
			$add( 'research', self::WARNING, sprintf( '%d job(s) with an expired lease, %d job(s) failed without retries left.', $stuck, $failed ) );
		} else {
			$add( 'research', self::OK, 'No stuck or failed research jobs.' );
		}

		// Editorial queue (informational).
		$add( 'review_queue', self::OK, sprintf( '%d evidence item(s) and %d candidate(s) awaiting review.', (int) $f['review_claims'], (int) $f['review_candidates'] ) );

		// Profile claims: claimants wait for a person; do not let them wait long.
		$in_review = (int) ( $f['claims_in_review'] ?? 0 );
		$oldest    = is_string( $f['claims_oldest_review'] ?? null ) ? strtotime( (string) $f['claims_oldest_review'] . ' UTC' ) : false;
		$days      = false === $oldest ? 0 : (int) floor( ( $now->getTimestamp() - $oldest ) / 86400 );
		if ( $in_review > 0 && $days >= 7 ) {
			$add( 'claims', self::WARNING, sprintf( '%d profile claim(s) awaiting review; the oldest has waited %d days.', $in_review, $days ) );
		} else {
			$add( 'claims', self::OK, sprintf( '%d profile claim(s) awaiting review.', $in_review ) );
		}

		// Frontend revalidation.
		$rev = is_array( $f['revalidation'] ) ? $f['revalidation'] : null;
		if ( ! $f['revalidation_configured'] ) {
			$add( 'revalidation', self::WARNING, 'Instant page refresh is off (frontend URL or LEXRANKED_REVALIDATE_SECRET missing); pages update within 5 minutes.' );
		} elseif ( null !== $rev && 'error' === ( $rev['state'] ?? '' ) ) {
			$add( 'revalidation', self::WARNING, 'Last page refresh failed: ' . (string) ( $rev['message'] ?? '' ) . ' (' . (string) ( $rev['at'] ?? '' ) . ').' );
		} else {
			$add( 'revalidation', self::OK, null === $rev ? 'Configured; nothing sent yet.' : 'Last page refresh ' . (string) ( $rev['at'] ?? '' ) . '.' );
		}

		// Configuration.
		if ( $f['debug_display'] ) {
			$add( 'configuration', self::WARNING, 'WP_DEBUG_DISPLAY is on: PHP errors may be shown to visitors and API clients.' );
		} else {
			$add( 'configuration', self::OK, 'Error display is off.' );
		}

		$worst = self::OK;
		foreach ( $checks as $check ) {
			if ( self::RANK[ $check['status'] ] > self::RANK[ $worst ] ) {
				$worst = $check['status'];
			}
		}
		return array(
			'status' => $worst,
			'checks' => $checks,
		);
	}
}
