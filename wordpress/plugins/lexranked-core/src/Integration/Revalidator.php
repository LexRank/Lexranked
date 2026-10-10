<?php
/**
 * Frontend revalidation webhook.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Integration;

use LexRanked\Core\Content\TermContent;
use LexRanked\Core\PostTypes\Article;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\PostTypes\Source;
use LexRanked\Core\PostTypes\VerificationRecord;
use LexRanked\Core\Settings\Settings;
use LexRanked\Core\Support\ContentVersion;

/**
 * Tells the Next.js frontend that public data changed, so pages refresh
 * within seconds instead of waiting for the 5-minute ISR window.
 *
 * - Changes during a request are collected and sent once, at shutdown.
 * - The request is signed (HMAC, see Signature) with LEXRANKED_REVALIDATE_SECRET,
 *   defined in wp-config.php or the environment - never stored in the database.
 * - A failed call is retried once a minute (up to 5 times) via WP-cron and
 *   recorded for the health check; ISR still refreshes pages meanwhile.
 * - The same events bump the content version that keys the API response cache.
 */
final class Revalidator {

	public const ENDPOINT      = '/api/revalidate/';
	public const STATUS_OPTION = 'lexranked_revalidation_status';
	public const RETRY_HOOK    = 'lexranked_revalidate_retry';
	public const MAX_ATTEMPTS  = 5;

	/** Post types whose changes are public. */
	public const POST_TYPES = array( Lawyer::SLUG, LawFirm::SLUG, Ranking::SLUG, Source::SLUG, VerificationRecord::SLUG, Article::SLUG );

	/**
	 * Public paths touched in this request (always includes the tag refresh).
	 *
	 * @var array<string, true>
	 */
	private array $pending = array();

	/**
	 * Whether shutdown is hooked.
	 *
	 * @var bool
	 */
	private bool $scheduled = false;

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
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_save' ), 30, 2 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );
		foreach ( TermContent::TAXONOMIES as $taxonomy ) {
			add_action( 'created_' . $taxonomy, array( $this, 'on_term' ) );
			add_action( 'edited_' . $taxonomy, array( $this, 'on_term' ) );
			add_action( 'delete_' . $taxonomy, array( $this, 'on_term' ) );
		}
		add_action( 'lexranked_scores_updated', array( $this, 'on_scores' ) );
		add_action( self::RETRY_HOOK, array( $this, 'retry' ) );
	}

	/**
	 * Secret from wp-config.php or the environment.
	 */
	public static function secret(): string {
		if ( defined( 'LEXRANKED_REVALIDATE_SECRET' ) ) {
			return (string) constant( 'LEXRANKED_REVALIDATE_SECRET' );
		}
		$env = getenv( 'LEXRANKED_REVALIDATE_SECRET' );
		return false === $env ? '' : $env;
	}

	/**
	 * Whether revalidation can be sent.
	 */
	public function configured(): bool {
		return '' !== (string) $this->settings->get( 'frontend_url' ) && Signature::usable( self::secret() );
	}

	/**
	 * Publish / unpublish of a public post type.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( in_array( $post->post_type, self::POST_TYPES, true ) && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			$this->queue( $post );
		}
	}

	/**
	 * Edits of published posts.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save( int $post_id, \WP_Post $post ): void {
		if ( 'publish' === $post->post_status && in_array( $post->post_type, self::POST_TYPES, true ) && ! wp_is_post_revision( $post_id ) ) {
			$this->queue( $post );
		}
	}

	/**
	 * Deletion.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_delete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status && in_array( $post->post_type, self::POST_TYPES, true ) ) {
			$this->queue( $post );
		}
	}

	/**
	 * Location / practice-area changes.
	 */
	public function on_term(): void {
		$this->mark( '/' );
	}

	/**
	 * Recalculated scores and rankings.
	 */
	public function on_scores(): void {
		$this->mark( '/' );
	}

	/**
	 * Commercial changes (claims, placements) and other non-post events.
	 *
	 * @param string $path Public path ('/' refreshes everything tagged).
	 */
	public function touch( string $path = '/' ): void {
		$this->mark( $path );
	}

	/**
	 * Queue the paths of a post.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function queue( \WP_Post $post ): void {
		$base = match ( $post->post_type ) {
			Lawyer::SLUG  => '/lawyers/',
			LawFirm::SLUG => '/law-firms/',
			Article::SLUG => '/articles/',
			default       => null,
		};
		$this->mark( null === $base ? '/' : $base . $post->post_name . '/' );
	}

	/**
	 * Record a changed path and send at shutdown.
	 *
	 * @param string $path Public path.
	 */
	private function mark( string $path ): void {
		$this->pending[ $path ] = true;
		// Every change invalidates cached API responses (later saves in the same request included).
		ContentVersion::bump();
		if ( ! $this->scheduled ) {
			$this->scheduled = true;
			add_action( 'shutdown', array( $this, 'flush' ) );
		}
	}

	/**
	 * Send the collected change notification.
	 */
	public function flush(): void {
		if ( array() === $this->pending ) {
			return;
		}
		$paths         = array_keys( $this->pending );
		$this->pending = array();
		$this->send( $paths, 1 );
	}

	/**
	 * Cron retry.
	 *
	 * @param mixed $paths   Paths.
	 * @param mixed $attempt Attempt number.
	 */
	public function retry( $paths = array(), $attempt = 2 ): void {
		$this->send( is_array( $paths ) ? array_map( 'strval', $paths ) : array( '/' ), (int) $attempt );
	}

	/**
	 * POST the signed payload; record the outcome.
	 *
	 * @param array<int, string> $paths   Changed paths.
	 * @param int                $attempt Attempt number.
	 * @return bool Success.
	 */
	public function send( array $paths, int $attempt ): bool {
		if ( ! $this->configured() ) {
			$this->status( 'not_configured', 'Set the frontend URL in Settings and LEXRANKED_REVALIDATE_SECRET in wp-config.php.', $attempt );
			return false;
		}
		$body     = (string) wp_json_encode(
			array(
				'tags'  => array( 'lexranked' ),
				'paths' => array_values( array_slice( $paths, 0, 50 ) ),
				'at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			)
		);
		$url      = rtrim( (string) $this->settings->get( 'frontend_url' ), '/' ) . self::ENDPOINT;
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'    => 'application/json',
					Signature::HEADER => Signature::header( self::secret(), time(), $body ),
				),
				'body'        => $body,
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			$this->status( 'ok', 'Frontend refreshed.', $attempt );
			return true;
		}
		$reason = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
		$this->status( 'error', mb_substr( $reason, 0, 200 ), $attempt );
		if ( $attempt < self::MAX_ATTEMPTS && ! wp_next_scheduled( self::RETRY_HOOK, array( $paths, $attempt + 1 ) ) ) {
			wp_schedule_single_event( time() + 60 * $attempt, self::RETRY_HOOK, array( $paths, $attempt + 1 ) );
		}
		return false;
	}

	/**
	 * Store the last outcome (for Settings and the health check).
	 *
	 * @param string $state   ok|error|not_configured.
	 * @param string $message Message (no secrets).
	 * @param int    $attempt Attempt.
	 */
	private function status( string $state, string $message, int $attempt ): void {
		update_option(
			self::STATUS_OPTION,
			array(
				'state'   => $state,
				'message' => $message,
				'attempt' => $attempt,
				'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			),
			false
		);
	}

	/**
	 * Last outcome.
	 *
	 * @return array{state: string, message: string, attempt: int, at: string}|null
	 */
	public static function last_status(): ?array {
		$s = get_option( self::STATUS_OPTION );
		return is_array( $s ) ? $s : null;
	}
}
