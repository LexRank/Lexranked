<?php
/**
 * Health facts from WordPress.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Monitoring;

use LexRanked\Core\Database\Installer;
use LexRanked\Core\Database\Schema;
use LexRanked\Core\Domain\ResearchJobStatus;
use LexRanked\Core\Integration\Revalidator;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\PostTypes\ResearchJob;
use LexRanked\Core\Ranking\RankingRunner;
use LexRanked\Core\Research\CandidateRepository;
use LexRanked\Core\Services;

/**
 * Gathers the facts HealthCheck evaluates. Contains no secrets and no
 * personal data, only counts, timestamps and states.
 */
final class HealthService {

	/**
	 * Constructor.
	 *
	 * @param Services    $services    Services.
	 * @param Revalidator $revalidator Revalidator.
	 */
	public function __construct( private readonly Services $services, private readonly Revalidator $revalidator ) {
	}

	/**
	 * Evaluate now.
	 *
	 * @return array{status: string, checks: array<int, array{key: string, status: string, message: string}>}
	 */
	public function report(): array {
		return HealthCheck::evaluate( $this->facts(), new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Raw facts.
	 *
	 * @return array<string, mixed>
	 */
	public function facts(): array {
		$next     = wp_next_scheduled( RankingRunner::CRON_HOOK );
		$rankings = wp_count_posts( Ranking::SLUG );
		$counts   = $this->services->candidates->counts();
		return array(
			'schema_version'          => (string) get_option( Installer::VERSION_OPTION ),
			'expected_schema'         => Schema::VERSION,
			'cron_next'               => false === $next ? null : (int) $next - time(),
			'cron_disabled'           => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'last_calculation'        => (string) get_option( RankingRunner::LAST_RUN_OPTION, '' ),
			'published_rankings'      => isset( $rankings->publish ) ? (int) $rankings->publish : 0,
			'jobs_stuck'              => $this->count_jobs( ResearchJobStatus::Running->value, 'locked_until' ),
			'jobs_failed'             => $this->count_jobs( ResearchJobStatus::Failed->value, 'next_retry_at' ),
			'review_claims'           => count( $this->services->claims->pending_review( 1000 ) ),
			'review_candidates'       => (int) ( $counts[ CandidateRepository::STATUS_NEEDS_REVIEW ] ?? 0 ),
			'revalidation_configured' => $this->revalidator->configured(),
			'revalidation'            => Revalidator::last_status(),
			'debug_display'           => defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ),
		);
	}

	/**
	 * Running jobs with an expired lease, or failed jobs with no retry scheduled.
	 *
	 * @param string $status Job status.
	 * @param string $field  locked_until (running) or next_retry_at (failed).
	 */
	private function count_jobs( string $status, string $field ): int {
		$type  = $this->services->research_job;
		$posts = get_posts(
			array(
				'post_type'        => ResearchJob::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => 200,
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small admin table.
				'meta_query'       => array(
					array(
						'key'   => $type->field( 'status' )?->meta_key(),
						'value' => $status,
					),
				),
			)
		);
		$n     = 0;
		foreach ( $posts as $post ) {
			$value = $this->services->entities->record( $post, $type )['fields'][ $field ] ?? null;
			if ( 'locked_until' === $field ) {
				$n += ( null !== $value && strtotime( (string) $value ) < time() ) ? 1 : 0;
			} else {
				$n += null === $value ? 1 : 0;
			}
		}
		return $n;
	}
}
