<?php
/**
 * Research job queue.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

use LexRanked\Core\Domain\ResearchJobStatus;
use LexRanked\Core\Domain\VerificationStatus;
use LexRanked\Core\PostTypes\ResearchJob;
use LexRanked\Core\PostTypes\VerificationRecord;
use LexRanked\Core\Security\AuditLog;
use LexRanked\Core\Services;

/**
 * Durable job queue on top of research-job posts.
 *
 * - claim(): hands a job to one worker with a lease token (MySQL GET_LOCK
 *   serializes concurrent claims). Pending jobs, failed jobs whose backoff
 *   elapsed, and running jobs whose lease expired (crashed worker) are
 *   claimable; the last resume from their stored cursor.
 * - heartbeat(): extends the lease and stores progress + logs.
 * - complete()/fail(): end the attempt; failures retry with exponential
 *   backoff up to the configured limit.
 *
 * Internal job types (verification expiry, ranking recalculation) run
 * inside WordPress via WP-cron; the others are for external workers.
 */
final class JobService {

	public const TOKEN_META     = '_lr_lock_token';
	public const LOCK_NAME      = 'lexranked_research_claim';
	public const WORKER_TYPES   = array( 'candidate_discovery', 'source_refresh', 'ai_candidate_review', 'content_generation' );
	public const INTERNAL_TYPES = array( 'verification', 'ranking_recalculation' );
	public const INTERNAL_HOOK  = 'lexranked_research_internal';

	/**
	 * Constructor.
	 *
	 * @param Services    $services Services.
	 * @param ResearchLog $log      Job log.
	 */
	public function __construct( private readonly Services $services, private readonly ResearchLog $log ) {
	}

	/**
	 * Hooks: internal jobs run hourly and shortly after one is created.
	 */
	public function register(): void {
		add_action( self::INTERNAL_HOOK, array( $this, 'cron' ) );
		add_action( self::INTERNAL_HOOK . '_soon', array( $this, 'cron' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ) );
		add_action( 'save_post_' . ResearchJob::SLUG, array( $this, 'on_save' ), 20, 2 );
	}

	/**
	 * Ensure the hourly internal-job event exists.
	 */
	public function ensure_schedule(): void {
		if ( ! wp_next_scheduled( self::INTERNAL_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::INTERNAL_HOOK );
		}
	}

	/**
	 * New or re-queued internal job: run it soon rather than within the hour.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save( int $post_id, \WP_Post $post ): void {
		if ( 'publish' !== $post->post_status || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$fields = $this->fields( $post );
		if ( in_array( $fields['job_type'], self::INTERNAL_TYPES, true ) && ResearchJobStatus::Pending->value === $fields['status'] && ! wp_next_scheduled( self::INTERNAL_HOOK . '_soon' ) ) {
			wp_schedule_single_event( time() + 30, self::INTERNAL_HOOK . '_soon' );
		}
	}

	/**
	 * Create a job.
	 *
	 * @param string               $job_type          One of ResearchJob::JOB_TYPES.
	 * @param array<string, mixed> $params            Parameters.
	 * @param string               $title             Title.
	 * @param array<int, int>      $location_ids      Location term IDs (scope).
	 * @param array<int, int>      $practice_area_ids Practice-area term IDs (scope).
	 * @return int Job ID.
	 * @throws \InvalidArgumentException When the type is unknown.
	 * @throws \RuntimeException When the post cannot be created.
	 */
	public function create( string $job_type, array $params = array(), string $title = '', array $location_ids = array(), array $practice_area_ids = array() ): int {
		if ( ! in_array( $job_type, ResearchJob::JOB_TYPES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown job type.' );
		}
		$id = wp_insert_post(
			array(
				'post_type'   => ResearchJob::SLUG,
				'post_status' => 'draft',
				'post_title'  => '' === $title ? $job_type . ' ' . gmdate( 'Y-m-d H:i' ) : $title,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( 'Could not create research job.' );
		}
		$this->save(
			(int) $id,
			array(
				'job_type'        => $job_type,
				'status'          => ResearchJobStatus::Pending->value,
				'params'          => array() === $params ? null : (string) wp_json_encode( $params ),
				'retry_count'     => 0,
				'processed_count' => 0,
			)
		);
		if ( array() !== $location_ids ) {
			wp_set_object_terms( (int) $id, array_map( 'intval', $location_ids ), \LexRanked\Core\Taxonomies\Location::SLUG );
		}
		if ( array() !== $practice_area_ids ) {
			wp_set_object_terms( (int) $id, array_map( 'intval', $practice_area_ids ), \LexRanked\Core\Taxonomies\PracticeArea::SLUG );
		}
		// Publish last so save hooks see the complete record.
		wp_update_post(
			array(
				'ID'          => (int) $id,
				'post_status' => 'publish',
			)
		);
		AuditLog::log( 'research_job.created', ResearchJob::SLUG, (int) $id, array( 'job_type' => $job_type ) );
		return (int) $id;
	}

	/**
	 * Claim the next available job.
	 *
	 * @param string             $worker Worker identifier (for display).
	 * @param array<int, string> $types  Job types the worker handles.
	 * @return array<string, mixed>|null Job view including the lease token, or null when nothing is due.
	 * @throws JobException When the claim lock cannot be acquired.
	 */
	public function claim( string $worker, array $types ): ?array {
		global $wpdb;
		$types = array_values( array_intersect( ResearchJob::JOB_TYPES, $types ) );
		if ( ! $this->services->settings->get( 'ai_enabled' ) ) {
			// AI jobs wait (pending) until an administrator enables AI assistance.
			$types = array_values( array_diff( $types, ResearchJob::AI_JOB_TYPES ) );
		}
		if ( array() === $types ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock, not data.
		$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $this->lock_name() ) );
		if ( 1 !== $locked ) {
			throw new JobException( 'lexranked_claim_busy', 'Another worker is claiming a job; retry shortly.', 503 );
		}
		try {
			return $this->claim_locked( $worker, $types );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock, not data.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name() ) );
		}
	}

	/**
	 * Claim while holding the lock.
	 *
	 * @param string             $worker Worker.
	 * @param array<int, string> $types  Types.
	 * @return array<string, mixed>|null
	 */
	private function claim_locked( string $worker, array $types ): ?array {
		$type    = $this->services->research_job;
		$backoff = $this->services->settings->research_backoff();
		$now     = $this->now();
		$posts   = get_posts(
			array(
				'post_type'        => ResearchJob::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => 50,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small admin-only table of jobs.
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'     => $type->field( 'status' )?->meta_key(),
						'value'   => array( ResearchJobStatus::Pending->value, ResearchJobStatus::Running->value, ResearchJobStatus::Failed->value ),
						'compare' => 'IN',
					),
					array(
						'key'     => $type->field( 'job_type' )?->meta_key(),
						'value'   => $types,
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( $posts as $post ) {
			$fields = $this->fields( $post );
			$why    = JobPolicy::claimability( $fields, $backoff, $now );
			if ( null === $why ) {
				continue;
			}
			if ( JobPolicy::EXHAUSTED === $why ) {
				$this->save(
					$post->ID,
					array(
						'status'        => ResearchJobStatus::Failed->value,
						'error_message' => 'Lease expired after the last allowed retry; the worker stopped responding.',
						'locked_until'  => null,
						'next_retry_at' => null,
					)
				);
				delete_post_meta( $post->ID, self::TOKEN_META );
				$this->log->add( $post->ID, 'error', 'lease', 'Lease expired with no retries left; job failed.' );
				continue;
			}
			if ( null === JobPolicy::params( $fields['params'] ) ) {
				$this->save(
					$post->ID,
					array(
						'status'        => ResearchJobStatus::Failed->value,
						'error_message' => 'Parameters are not a valid JSON object.',
						'next_retry_at' => null,
					)
				);
				$this->log->add( $post->ID, 'error', 'claim', 'Invalid parameters JSON; fix the job and set it back to pending.' );
				continue;
			}

			$token   = bin2hex( random_bytes( 16 ) );
			$changes = array(
				'status'        => ResearchJobStatus::Running->value,
				'locked_until'  => $this->lease_end( $now ),
				'next_retry_at' => null,
				'worker'        => substr( $worker, 0, 100 ),
			);
			if ( null === $fields['started_at'] ) {
				$changes['started_at'] = $now->format( 'Y-m-d\TH:i:s\Z' );
			}
			if ( JobPolicy::CLAIM_RESUME === $why ) {
				$changes['retry_count'] = (int) $fields['retry_count'] + 1;
				$this->log->add( $post->ID, 'warning', 'lease', 'Previous lease expired; resuming from the stored cursor.', array( 'cursor' => $fields['cursor'] ) );
			} elseif ( JobPolicy::CLAIM_RETRY === $why ) {
				$this->log->add( $post->ID, 'info', 'retry', sprintf( 'Retry %d after backoff.', (int) $fields['retry_count'] ) );
			}
			$this->save( $post->ID, $changes );
			update_post_meta( $post->ID, self::TOKEN_META, $token );
			$this->log->add( $post->ID, 'info', 'claim', 'Claimed by ' . substr( $worker, 0, 100 ) . '.' );

			$view          = $this->view( $post->ID );
			$view['token'] = $token;
			return $view;
		}//end foreach
		return null;
	}

	/**
	 * Extend the lease and record progress.
	 *
	 * @param int                  $job_id   Job ID.
	 * @param string               $token    Lease token.
	 * @param array<string, mixed> $progress {cursor?, processed_count?, stats?, logs?}.
	 * @return array<string, mixed> Job view.
	 */
	public function heartbeat( int $job_id, string $token, array $progress ): array {
		$fields  = $this->assert_lease( $job_id, $token );
		$changes = $this->progress_changes( $fields, $progress );

		$changes['locked_until'] = $this->lease_end( $this->now() );
		$this->save( $job_id, $changes );
		$this->log->add_many( $job_id, is_array( $progress['logs'] ?? null ) ? $progress['logs'] : array() );
		return $this->view( $job_id );
	}

	/**
	 * Complete the job.
	 *
	 * @param int                  $job_id   Job ID.
	 * @param string               $token    Lease token.
	 * @param array<string, mixed> $progress Final progress.
	 * @return array<string, mixed> Job view.
	 */
	public function complete( int $job_id, string $token, array $progress ): array {
		$fields  = $this->assert_lease( $job_id, $token );
		$changes = $this->progress_changes( $fields, $progress );

		$changes += array(
			'status'        => ResearchJobStatus::Completed->value,
			'completed_at'  => $this->now()->format( 'Y-m-d\TH:i:s\Z' ),
			'locked_until'  => null,
			'next_retry_at' => null,
			'error_message' => null,
		);
		$this->save( $job_id, $changes );
		delete_post_meta( $job_id, self::TOKEN_META );
		$this->log->add_many( $job_id, is_array( $progress['logs'] ?? null ) ? $progress['logs'] : array() );
		$this->log->add( $job_id, 'info', 'complete', 'Job completed.' );
		AuditLog::log( 'research_job.completed', ResearchJob::SLUG, $job_id );

		// Autonomous research: publish what passes every check, keep doubts as drafts.
		$publisher = new AutoPublisher( $this->services, $this->log );
		if ( $publisher->enabled_for( $this->view( $job_id )['params'] ) ) {
			try {
				$publisher->run( $job_id );
			} catch ( \Throwable $e ) {
				// The job's data is stored; publication is retried with `wp lexranked research-auto-publish`.
				$this->log->add( $job_id, 'error', 'auto_publish', 'Automatic publication stopped: ' . $e->getMessage() );
			}
		}

		// New or changed evidence can move scores; the runner debounces this.
		$this->services->runner->schedule_soon();
		return $this->view( $job_id );
	}

	/**
	 * Fail the current attempt.
	 *
	 * @param int                  $job_id    Job ID.
	 * @param string               $token     Lease token.
	 * @param string               $error     Error message (no secrets).
	 * @param bool                 $retryable Whether to retry with backoff.
	 * @param array<string, mixed> $progress  Progress so far (cursor is kept for the retry).
	 * @return array<string, mixed> Job view.
	 */
	public function fail( int $job_id, string $token, string $error, bool $retryable, array $progress = array() ): array {
		$fields  = $this->assert_lease( $job_id, $token );
		$changes = $this->progress_changes( $fields, $progress );
		$outcome = JobPolicy::after_failure( (int) $fields['retry_count'], $retryable, $this->services->settings->research_backoff(), $this->now() );
		$error   = mb_substr( trim( (string) preg_replace( '/\s+/', ' ', $error ) ), 0, 1000 );

		$changes += array(
			'status'        => ResearchJobStatus::Failed->value,
			'retry_count'   => $outcome['retry_count'],
			'next_retry_at' => $outcome['next_retry_at'],
			'locked_until'  => null,
			'error_message' => '' === $error ? 'Unknown error.' : $error,
		);
		$this->save( $job_id, $changes );
		delete_post_meta( $job_id, self::TOKEN_META );
		$this->log->add_many( $job_id, is_array( $progress['logs'] ?? null ) ? $progress['logs'] : array() );
		$this->log->add(
			$job_id,
			'error',
			'fail',
			null === $outcome['next_retry_at'] ? 'Attempt failed; no retries left.' : 'Attempt failed; retry scheduled.',
			array(
				'error'         => $changes['error_message'],
				'next_retry_at' => $outcome['next_retry_at'],
			)
		);
		return $this->view( $job_id );
	}

	/**
	 * WP-cron callback (ignores the hook's arguments).
	 */
	public function cron(): void {
		$this->run_internal();
	}

	/**
	 * Run due internal jobs (WP-cron / CLI).
	 *
	 * @param int $max Max jobs per run.
	 * @return int Jobs processed.
	 */
	public function run_internal( int $max = 5 ): int {
		$done = 0;
		while ( $done < $max ) {
			try {
				$job = $this->claim( 'wp-cron', self::INTERNAL_TYPES );
			} catch ( JobException $e ) {
				break;
			}
			if ( null === $job ) {
				break;
			}
			++$done;
			try {
				$stats = 'verification' === $job['jobType'] ? $this->expire_verifications() : $this->services->runner->run_all();
				$this->complete(
					(int) $job['id'],
					(string) $job['token'],
					array(
						'processed_count' => array_sum( $stats ),
						'stats'           => $stats,
					)
				);
			} catch ( \Throwable $e ) {
				$this->fail( (int) $job['id'], (string) $job['token'], $e->getMessage(), true );
			}
		}//end while
		return $done;
	}

	/**
	 * Mark published verification records past expires_at as expired.
	 *
	 * @return array{checked: int, expired: int}
	 */
	public function expire_verifications(): array {
		$type    = $this->services->verification;
		$now     = $this->now();
		$checked = 0;
		$expired = 0;
		$posts   = get_posts(
			array(
				'post_type'        => VerificationRecord::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => 2000,
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Batch maintenance job.
				'meta_query'       => array(
					array(
						'key'   => $type->field( 'status' )?->meta_key(),
						'value' => VerificationStatus::Verified->value,
					),
				),
			)
		);
		foreach ( $posts as $post ) {
			++$checked;
			$fields = $this->services->entities->record( $post, $type )['fields'];
			if ( null === $fields['expires_at'] || new \DateTimeImmutable( (string) $fields['expires_at'] ) > $now ) {
				continue;
			}
			$this->services->entities->save_fields( $post->ID, $type, array( 'status' => VerificationStatus::Expired->value ) );
			++$expired;
		}
		return array(
			'checked' => $checked,
			'expired' => $expired,
		);
	}

	/**
	 * Admin/API view of a job (never includes the lease token).
	 *
	 * @param int $job_id Job ID.
	 * @return array<string, mixed>
	 * @throws JobException When the job does not exist.
	 */
	public function view( int $job_id ): array {
		$post = $this->post( $job_id );
		$rec  = $this->services->entities->record( $post, $this->services->research_job );
		$f    = $rec['fields'];
		return array(
			'id'             => $job_id,
			'title'          => $rec['title'],
			'jobType'        => $f['job_type'],
			'status'         => $f['status'],
			'params'         => JobPolicy::params( $f['params'] ) ?? array(),
			'scope'          => array(
				'locations'     => $rec['locations'],
				'practiceAreas' => $rec['practice_areas'],
			),
			'cursor'         => $f['cursor'],
			'processedCount' => (int) $f['processed_count'],
			'retryCount'     => (int) $f['retry_count'],
			'stats'          => is_string( $f['stats'] ) ? json_decode( $f['stats'], true ) : null,
			'startedAt'      => $f['started_at'],
			'completedAt'    => $f['completed_at'],
			'lockedUntil'    => $f['locked_until'],
			'nextRetryAt'    => $f['next_retry_at'],
			'worker'         => $f['worker'],
			'error'          => $f['error_message'],
		);
	}

	/**
	 * Job view after verifying the caller holds the current lease (data writes).
	 *
	 * @param int    $job_id Job ID.
	 * @param string $token  Lease token.
	 * @return array<string, mixed>
	 */
	public function assert_active( int $job_id, string $token ): array {
		$this->assert_lease( $job_id, $token );
		return $this->view( $job_id );
	}

	/**
	 * Verify the caller holds the current lease; returns the job fields.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $token  Token.
	 * @return array<string, mixed>
	 * @throws JobException When the lease is not held.
	 */
	private function assert_lease( int $job_id, string $token ): array {
		$post   = $this->post( $job_id );
		$fields = $this->fields( $post );
		if ( ResearchJobStatus::Cancelled->value === $fields['status'] ) {
			throw new JobException( 'lexranked_job_cancelled', 'The job was cancelled by an administrator.' );
		}
		$stored = (string) get_post_meta( $job_id, self::TOKEN_META, true );
		if ( ResearchJobStatus::Running->value !== $fields['status'] || '' === $stored || '' === $token || ! hash_equals( $stored, $token ) ) {
			throw new JobException( 'lexranked_lease_lost', 'This worker no longer holds the job lease.' );
		}
		return $fields;
	}

	/**
	 * Field changes for reported progress.
	 *
	 * @param array<string, mixed> $fields   Current fields.
	 * @param array<string, mixed> $progress Raw progress.
	 * @return array<string, mixed>
	 */
	private function progress_changes( array $fields, array $progress ): array {
		$clean   = JobPolicy::progress( $progress, (int) $fields['processed_count'] );
		$changes = array();
		if ( array_key_exists( 'cursor', $clean ) ) {
			$changes['cursor'] = $clean['cursor'];
		}
		if ( isset( $clean['processed_count'] ) ) {
			$changes['processed_count'] = $clean['processed_count'];
		}
		if ( isset( $clean['stats'] ) ) {
			$changes['stats'] = (string) wp_json_encode( $clean['stats'] );
		}
		return $changes;
	}

	/**
	 * Load a job post.
	 *
	 * @param int $job_id Job ID.
	 * @throws JobException When missing.
	 */
	private function post( int $job_id ): \WP_Post {
		$post = get_post( $job_id );
		if ( ! $post instanceof \WP_Post || ResearchJob::SLUG !== $post->post_type || 'trash' === $post->post_status ) {
			throw new JobException( 'lexranked_not_found', 'Research job not found.', 404 );
		}
		return $post;
	}

	/**
	 * Job fields.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private function fields( \WP_Post $post ): array {
		return $this->services->entities->record( $post, $this->services->research_job )['fields'];
	}

	/**
	 * Persist system-managed fields.
	 *
	 * @param int                  $job_id  Job ID.
	 * @param array<string, mixed> $changes Changes.
	 */
	private function save( int $job_id, array $changes ): void {
		$this->services->entities->save_fields( $job_id, $this->services->research_job, $changes, true );
	}

	/**
	 * Lease end from now.
	 *
	 * @param \DateTimeImmutable $now Now.
	 */
	private function lease_end( \DateTimeImmutable $now ): string {
		return $now->modify( '+' . (int) $this->services->settings->get( 'research_lease_minutes' ) . ' minutes' )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Current UTC time.
	 */
	private function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Per-site advisory lock name.
	 */
	private function lock_name(): string {
		global $wpdb;
		return self::LOCK_NAME . '_' . $wpdb->prefix;
	}
}
