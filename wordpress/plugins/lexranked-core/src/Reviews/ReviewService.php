<?php
/**
 * Client reviews: submission, email confirmation, moderation and the rating
 * facts they produce.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Reviews;

use LexRanked\Core\Commercial\CommercialService;
use LexRanked\Core\PostTypes\Source;
use LexRanked\Core\Repository\ClaimRepository;
use LexRanked\Core\Security\AuditLog;
use LexRanked\Core\Services;

/**
 * Reviews written on LexRanked by clients of a lawyer or firm.
 *
 * - A review is public only after the reviewer confirmed their email and an
 *   editor approved it (no automatic publication).
 * - Approved reviews become two facts on the profile, rating (average) and
 *   review_count, from the "LexRanked client reviews" source, so the ranking
 *   engine scores them like any other review data. Its own source type sits
 *   in the lowest tier: rating data from an external review platform, where
 *   recorded, takes precedence instead of conflicting with it.
 * - Payment or a claimed profile never affects whether a review is approved.
 */
final class ReviewService {

	public const TOKEN_TTL          = 172800;
	public const MAX_PER_EMAIL_DAY  = 5;
	public const MAX_PER_ENTITY_DAY = 20;
	public const SOURCE_OPTION      = 'lexranked_review_source_id';
	public const SOURCE_TYPE        = 'lexranked_reviews';
	public const CRON_HOOK          = 'lexranked_reviews_daily';
	public const FIELDS             = array( 'rating', 'review_count' );

	/**
	 * Constructor.
	 *
	 * @param Services         $services Services.
	 * @param ReviewRepository $reviews  Storage.
	 */
	public function __construct( private readonly Services $services, public readonly ReviewRepository $reviews ) {
	}

	/**
	 * Hooks: a daily refresh keeps review facts fresh (review data counts as
	 * fresh for 7 days).
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'refresh_all' ) );
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
				}
			}
		);
	}

	/**
	 * Submit a review (from the public form, via the frontend server).
	 *
	 * @param array<string, mixed> $input Request body.
	 * @return array{status: string}
	 * @throws ReviewException When the profile is unknown or limits are reached.
	 */
	public function submit( array $input ): array {
		$data = ReviewRequest::validate( $input );
		$post = get_post( $data['entity_id'] );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || CommercialService::post_type( $data['entity_type'] ) !== $post->post_type ) {
			throw new ReviewException( 'lexranked_review_profile', 'Profile not found.', 404 );
		}
		$hash  = hash( 'sha256', $data['reviewer_email'] );
		$now   = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$since = $now->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
		if ( $this->reviews->count_since( 'email_hash', $hash, $since ) >= self::MAX_PER_EMAIL_DAY
			|| $this->reviews->count_since( 'entity_id', $data['entity_id'], $since ) >= self::MAX_PER_ENTITY_DAY ) {
			throw new ReviewException( 'lexranked_review_limit', 'Too many reviews submitted. Please try again tomorrow.', 429 );
		}

		// One review per person and profile; a repeat never reveals the earlier one's state.
		$existing = $this->reviews->existing( $data['entity_type'], $data['entity_id'], $hash );
		if ( null !== $existing ) {
			if ( ReviewStatus::PendingEmail->value === $existing['status'] ) {
				$token = self::new_token();
				$this->reviews->update(
					(int) $existing['review_id'],
					array(
						'email_token_hash'    => hash( 'sha256', $token ),
						'email_token_expires' => $now->modify( '+' . self::TOKEN_TTL . ' seconds' )->format( 'Y-m-d H:i:s' ),
					)
				);
				$this->mail_confirmation( $data['reviewer_email'], $data['reviewer_name'], $post, $token );
			}
			return array( 'status' => ReviewStatus::PendingEmail->value );
		}

		$token = self::new_token();
		$id    = $this->reviews->insert(
			array(
				'entity_id'           => $data['entity_id'],
				'entity_type'         => $data['entity_type'],
				'status'              => ReviewStatus::PendingEmail->value,
				'rating'              => $data['rating'],
				'title'               => $data['title'],
				'body'                => $data['body'],
				'display_name'        => ReviewRequest::display_name( $data['reviewer_name'] ),
				'reviewer_email'      => $data['reviewer_email'],
				'email_hash'          => $hash,
				'service_year'        => $data['service_year'],
				'email_token_hash'    => hash( 'sha256', $token ),
				'email_token_expires' => $now->modify( '+' . self::TOKEN_TTL . ' seconds' )->format( 'Y-m-d H:i:s' ),
			)
		);
		AuditLog::log( 'review.submitted', 'client_review', $id, array( 'entity_id' => $data['entity_id'] ) );
		$this->mail_confirmation( $data['reviewer_email'], $data['reviewer_name'], $post, $token );
		return array( 'status' => ReviewStatus::PendingEmail->value );
	}

	/**
	 * Confirm the reviewer's email (the link in the email).
	 *
	 * @param string $token Token.
	 * @return array{status: string}
	 * @throws ReviewException When the token is unknown or expired.
	 */
	public function confirm( string $token ): array {
		$token = trim( $token );
		$row   = preg_match( '/^[A-Za-z0-9_-]{40,64}$/', $token ) ? $this->reviews->find_by_token_hash( hash( 'sha256', $token ) ) : null;
		$now   = gmdate( 'Y-m-d H:i:s' );
		if ( null === $row || ReviewStatus::PendingEmail->value !== $row['status'] || (string) $row['email_token_expires'] < $now ) {
			throw new ReviewException( 'lexranked_review_token', 'This confirmation link is invalid or has expired. Please submit the review again.', 400 );
		}
		$this->reviews->update(
			(int) $row['review_id'],
			array(
				'status'              => ReviewStatus::PendingReview->value,
				'email_token_hash'    => null,
				'email_token_expires' => null,
				'email_verified_at'   => $now,
			)
		);
		AuditLog::log( 'review.email_confirmed', 'client_review', (int) $row['review_id'] );
		$this->mail(
			(string) get_option( 'admin_email' ),
			'New client review to moderate',
			"A client review has been confirmed by email and is waiting for moderation.\n\nModerate it in WordPress: " . admin_url( 'admin.php?page=' . \LexRanked\Core\Admin\ReviewsAdmin::PAGE ) . "\n"
		);
		return array( 'status' => ReviewStatus::PendingReview->value );
	}

	/**
	 * Approve or reject a review (editor).
	 *
	 * @param int    $review_id Review ID.
	 * @param bool   $approve   Approve (true) or reject (false).
	 * @param string $note      Private note.
	 * @throws ReviewException When the transition is not allowed.
	 */
	public function moderate( int $review_id, bool $approve, string $note = '' ): void {
		$row = $this->reviews->find( $review_id );
		if ( null === $row ) {
			throw new ReviewException( 'lexranked_review_missing', 'Review not found.', 404 );
		}
		$from = ReviewStatus::tryFrom( (string) $row['status'] );
		$to   = $approve ? ReviewStatus::Approved : ReviewStatus::Rejected;
		if ( null === $from || ! $from->can_become( $to ) || ( ReviewStatus::PendingEmail === $from && $approve ) ) {
			throw new ReviewException( 'lexranked_review_state', sprintf( 'A %s review cannot become %s.', str_replace( '_', ' ', (string) $row['status'] ), $to->value ), 409 );
		}
		$this->reviews->update(
			$review_id,
			array(
				'status'          => $to->value,
				'moderation_note' => mb_substr( trim( $note ), 0, 500 ),
				'moderated_by'    => get_current_user_id(),
				'approved_at'     => $approve ? gmdate( 'Y-m-d H:i:s' ) : null,
				// The address was only needed for confirmation and limits (the hash stays).
				'reviewer_email'  => '',
			)
		);
		AuditLog::log( $approve ? 'review.approved' : 'review.rejected', 'client_review', $review_id, array( 'entity_id' => (int) $row['entity_id'] ) );
		$this->sync_facts( (string) $row['entity_type'], (int) $row['entity_id'] );
		\LexRanked\Core\Support\ContentVersion::bump();
	}

	/**
	 * Public review block for a profile: summary and the latest approved reviews.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Profile post ID.
	 * @param int    $limit       Reviews to include.
	 * @return array{count: int, average: float|null, items: array<int, array<string, mixed>>}
	 */
	public function public_block( string $entity_type, int $entity_id, int $limit = 20 ): array {
		$rows    = $this->reviews->for_entity( $entity_type, $entity_id, ReviewStatus::Approved->value );
		$summary = ReviewRequest::aggregate( array_map( static fn( array $r ): int => (int) $r['rating'], $rows ) );
		$items   = array();
		foreach ( array_slice( $rows, 0, $limit ) as $r ) {
			$items[] = array(
				'id'          => (int) $r['review_id'],
				'rating'      => (int) $r['rating'],
				'title'       => (string) $r['title'],
				'body'        => (string) $r['body'],
				'author'      => (string) $r['display_name'],
				'serviceYear' => (int) $r['service_year'] > 0 ? (int) $r['service_year'] : null,
				'publishedAt' => empty( $r['approved_at'] ) ? null : str_replace( ' ', 'T', (string) $r['approved_at'] ) . 'Z',
			);
		}
		return $summary + array( 'items' => $items );
	}

	/**
	 * Write the profile's rating facts from its approved reviews (or retire
	 * them when none are left).
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Profile post ID.
	 */
	public function sync_facts( string $entity_type, int $entity_id ): void {
		$source = $this->source_id();
		if ( 0 === $source ) {
			return;
		}
		$rows    = $this->reviews->for_entity( $entity_type, $entity_id, ReviewStatus::Approved->value );
		$summary = ReviewRequest::aggregate( array_map( static fn( array $r ): int => (int) $r['rating'], $rows ) );
		$keep    = array();
		if ( $summary['count'] > 0 ) {
			$base = array(
				'entity_id'           => $entity_id,
				'entity_type'         => $entity_type,
				'source_id'           => $source,
				'source_url'          => $this->profile_url( $entity_id ),
				'source_type'         => self::SOURCE_TYPE,
				'retrieved_at'        => gmdate( 'Y-m-d H:i:s' ),
				'confidence'          => 0.9,
				'verification_status' => 'pending',
			);
			foreach ( array(
				'rating'       => $summary['average'],
				'review_count' => $summary['count'],
			) as $field => $value ) {
				$result = $this->services->claims->insert_unique(
					$base + array(
						'field_name' => $field,
						'value'      => $value,
					)
				);
				$keep[] = ClaimRepository::hash( $result['row'] );
			}
		}//end if
		$this->services->claims->retire_source_claims( $entity_type, $entity_id, $source, self::FIELDS, $keep );
	}

	/**
	 * Daily: refresh the facts of every reviewed profile (keeps them fresh).
	 */
	public function refresh_all(): void {
		foreach ( $this->reviews->reviewed_entities() as $e ) {
			$this->sync_facts( $e['entity_type'], $e['entity_id'] );
		}
	}

	/**
	 * The "LexRanked client reviews" source (created once, published).
	 */
	public function source_id(): int {
		$id   = (int) get_option( self::SOURCE_OPTION, 0 );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( $post instanceof \WP_Post && Source::SLUG === $post->post_type && 'trash' !== $post->post_status ) {
			return $id;
		}
		$id = wp_insert_post(
			array(
				'post_type'   => Source::SLUG,
				'post_status' => 'publish',
				'post_title'  => 'LexRanked client reviews',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		$this->services->entities->save_fields(
			(int) $id,
			$this->services->source,
			array(
				'url'             => rtrim( $this->frontend_base(), '/' ) . '/methodology/',
				'source_type'     => self::SOURCE_TYPE,
				'publisher'       => 'LexRanked',
				'retrieved_at'    => $now,
				'last_checked_at' => $now,
				'status'          => 'active',
				'notes'           => 'Reviews written on LexRanked by clients: email confirmed, approved by an editor.',
			)
		);
		update_option( self::SOURCE_OPTION, (int) $id, false );
		return (int) $id;
	}

	/**
	 * Frontend base URL.
	 */
	private function frontend_base(): string {
		$base = (string) $this->services->settings->get( 'frontend_url' );
		return '' === $base ? home_url() : $base;
	}

	/**
	 * Public profile URL with the reviews anchor.
	 *
	 * @param int $entity_id Profile post ID.
	 */
	private function profile_url( int $entity_id ): string {
		$post = get_post( $entity_id );
		$base = rtrim( $this->frontend_base(), '/' );
		if ( ! $post instanceof \WP_Post ) {
			return $base . '/';
		}
		$path = \LexRanked\Core\PostTypes\LawFirm::SLUG === $post->post_type ? '/law-firms/' : '/lawyers/';
		return $base . $path . $post->post_name . '/#reviews';
	}

	/**
	 * URL-safe random token (only its hash is stored).
	 */
	private static function new_token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token encoding.
	}

	/**
	 * Send the confirmation email.
	 *
	 * @param string   $email Reviewer email.
	 * @param string   $name  Reviewer name.
	 * @param \WP_Post $post  Profile.
	 * @param string   $token Token.
	 */
	private function mail_confirmation( string $email, string $name, \WP_Post $post, string $token ): void {
		$link = rtrim( $this->frontend_base(), '/' ) . '/reviews/confirm/?token=' . rawurlencode( $token );
		$this->mail(
			$email,
			'Confirm your LexRanked review',
			sprintf(
				"Hello %s,\n\nThank you for reviewing \"%s\" on LexRanked. Confirm your email address within 48 hours to send your review for moderation:\n\n%s\n\nAn editor reads every review before it is published. Your email address is never shown and is deleted after moderation; only your first name and last initial appear with the review.\n\nIf you did not write this review, ignore this email.\n\nLexRanked\n",
				$name,
				get_the_title( $post ),
				$link
			)
		);
	}

	/**
	 * Send a plain-text email. Failures are logged without the address.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 */
	private function mail( string $to, string $subject, string $body ): void {
		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}
		if ( ! wp_mail( $to, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) ) ) {
			AuditLog::log( 'mail.failed', 'client_review', 0, array( 'subject' => $subject ) );
		}
	}
}
