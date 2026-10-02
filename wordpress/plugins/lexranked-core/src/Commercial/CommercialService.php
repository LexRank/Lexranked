<?php
/**
 * Claims and placements.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Commercial;

use LexRanked\Core\Domain\CommercialStatus;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\Schema\ValidationException;
use LexRanked\Core\Security\AuditLog;
use LexRanked\Core\Services;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * The commercial side of LexRanked (spec §54): profile claims, identity
 * checks, premium profiles, featured profiles and sponsored listings.
 *
 * Kept apart from the organic ranking by construction: it lives in its own
 * tables, writes only the display-only `commercial_status` meta, and the
 * ranking engine (src/Ranking) never reads either. A unit test enforces it.
 */
final class CommercialService {

	public const CRON_HOOK          = 'lexranked_commercial_hourly';
	public const FINGERPRINT_OPTION = 'lexranked_placements_fingerprint';
	public const TOKEN_TTL          = 172800;
	public const PURGE_AFTER_DAYS   = 30;
	public const MAX_PER_EMAIL_DAY  = 3;
	public const MAX_PER_ENTITY_DAY = 10;

	public const DISCLOSURE_LISTING = 'Paid placement. Not part of the LexRanked ranking; it does not affect any score or position.';
	public const DISCLOSURE_PREMIUM = 'Provided by the profile owner as part of a paid premium profile. Not reviewed as evidence and not used in the score.';

	/**
	 * Constructor.
	 *
	 * @param Services               $services   Services.
	 * @param ProfileClaimRepository $claims     Claims.
	 * @param PlacementRepository    $placements Placements.
	 */
	public function __construct(
		private readonly Services $services,
		public readonly ProfileClaimRepository $claims,
		public readonly PlacementRepository $placements
	) {
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'hourly' ) );
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
				}
			}
		);
	}

	/**
	 * Current UTC time.
	 */
	private function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Post type slug for an entity type.
	 *
	 * @param string $entity_type lawyer|law_firm.
	 */
	public static function post_type( string $entity_type ): string {
		return 'law_firm' === $entity_type ? LawFirm::SLUG : Lawyer::SLUG;
	}

	/**
	 * Entity type for a post type slug.
	 *
	 * @param string $post_type Post type.
	 */
	public static function entity_type( string $post_type ): ?string {
		return match ( $post_type ) {
			Lawyer::SLUG  => 'lawyer',
			LawFirm::SLUG => 'law_firm',
			default       => null,
		};
	}

	// ---------------------------------------------------------------------
	// Claims.
	// ---------------------------------------------------------------------

	/**
	 * Submit a claim (from the public form, via the frontend server).
	 *
	 * @param array<string, mixed> $input Request body.
	 * Invalid input raises ValidationException (from ClaimRequest).
	 *
	 * @return array{status: string}
	 * @throws CommercialException When the profile is unknown or limits are reached.
	 */
	public function submit_claim( array $input ): array {
		$data = ClaimRequest::validate( $input );
		$post = get_post( $data['entity_id'] );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || self::post_type( $data['entity_type'] ) !== $post->post_type ) {
			throw new CommercialException( 'lexranked_claim_profile', 'Profile not found.', 404 );
		}
		$now   = $this->now();
		$since = $now->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
		if ( $this->claims->count_since( 'claimant_email', $data['claimant_email'], $since ) >= self::MAX_PER_EMAIL_DAY
			|| $this->claims->count_since( 'entity_id', $data['entity_id'], $since ) >= self::MAX_PER_ENTITY_DAY ) {
			throw new CommercialException( 'lexranked_claim_limit', 'Too many claim requests. Please try again tomorrow.', 429 );
		}

		// A repeat submission never creates a duplicate or reveals the existing claim's state.
		$open = $this->claims->open_for( $data['entity_id'], $data['claimant_email'] );
		if ( null !== $open ) {
			if ( ClaimStatus::PendingEmail->value === $open['status'] ) {
				$token = $this->new_token();
				$this->claims->update(
					(int) $open['claim_id'],
					array(
						'email_token_hash'    => hash( 'sha256', $token ),
						'email_token_expires' => $now->modify( '+' . self::TOKEN_TTL . ' seconds' )->format( 'Y-m-d H:i:s' ),
					)
				);
				$this->mail_confirmation( $data['claimant_email'], $data['claimant_name'], $post, $token );
			}
			return array( 'status' => 'pending_email' );
		}

		$token = $this->new_token();
		$id    = $this->claims->insert(
			$data + array(
				'status'              => ClaimStatus::PendingEmail->value,
				'email_token_hash'    => hash( 'sha256', $token ),
				'email_token_expires' => $now->modify( '+' . self::TOKEN_TTL . ' seconds' )->format( 'Y-m-d H:i:s' ),
			)
		);
		AuditLog::log( 'claim.submitted', 'profile_claim', $id, array( 'entity_id' => $data['entity_id'] ) );
		$this->mail_confirmation( $data['claimant_email'], $data['claimant_name'], $post, $token );
		return array( 'status' => 'pending_email' );
	}

	/**
	 * Confirm the claimant's email address (the link in the email).
	 *
	 * @param string $token Token from the link.
	 * @return array{status: string}
	 * @throws CommercialException When the token is unknown or expired.
	 */
	public function confirm_email( string $token ): array {
		$token = trim( $token );
		$row   = preg_match( '/^[A-Za-z0-9_-]{40,64}$/', $token ) ? $this->claims->find_by_token_hash( hash( 'sha256', $token ) ) : null;
		$now   = $this->now()->format( 'Y-m-d H:i:s' );
		if ( null === $row || ClaimStatus::PendingEmail->value !== $row['status'] || (string) $row['email_token_expires'] < $now ) {
			throw new CommercialException( 'lexranked_claim_token', 'This confirmation link is invalid or has expired. Please submit the claim again.', 400 );
		}
		$this->claims->update(
			(int) $row['claim_id'],
			array(
				'status'              => ClaimStatus::PendingReview->value,
				'email_token_hash'    => null,
				'email_token_expires' => null,
				'email_verified_at'   => $now,
			)
		);
		AuditLog::log( 'claim.email_confirmed', 'profile_claim', (int) $row['claim_id'] );
		$this->mail(
			(string) get_option( 'admin_email' ),
			'New profile claim to review',
			"A profile claim has been confirmed by email and is waiting for review.\n\nReview it in WordPress: " . admin_url( 'admin.php?page=lexranked-claims&claim=' . (int) $row['claim_id'] ) . "\n"
		);
		return array( 'status' => 'pending_review' );
	}

	/**
	 * Approve a claim after checking the claimant's identity.
	 *
	 * @param int    $claim_id        Claim ID.
	 * @param string $identity_method Key of ClaimSignals::IDENTITY_METHODS.
	 * @param string $note            Private reviewer note.
	 * @throws CommercialException When the claim cannot be approved.
	 */
	public function approve( int $claim_id, string $identity_method, string $note = '' ): void {
		$row = $this->claims->find( $claim_id );
		if ( null === $row ) {
			throw new CommercialException( 'lexranked_claim_missing', 'Claim not found.', 404 );
		}
		if ( ! array_key_exists( $identity_method, ClaimSignals::IDENTITY_METHODS ) ) {
			throw new CommercialException( 'lexranked_claim_identity', 'Record how the claimant\'s identity was checked before approving.', 400 );
		}
		$this->transition( $row, ClaimStatus::Approved );
		$existing = $this->claims->approved_for( (int) $row['entity_id'] );
		if ( null !== $existing ) {
			throw new CommercialException( 'lexranked_claim_exists', 'This profile already has an approved claim (#' . (int) $existing['claim_id'] . '). Revoke it first.', 409 );
		}
		$this->claims->update(
			$claim_id,
			array(
				'status'          => ClaimStatus::Approved->value,
				'identity_method' => $identity_method,
				'review_note'     => mb_substr( sanitize_textarea_field( $note ), 0, 1000 ),
				'reviewed_by'     => get_current_user_id(),
				'reviewed_at'     => $this->now()->format( 'Y-m-d H:i:s' ),
			)
		);
		AuditLog::log(
			'claim.approved',
			'profile_claim',
			$claim_id,
			array(
				'entity_id'       => (int) $row['entity_id'],
				'identity_method' => $identity_method,
			)
		);
		$this->sync_status( (int) $row['entity_id'] );
		$post = get_post( (int) $row['entity_id'] );
		if ( $post instanceof \WP_Post ) {
			$this->mail(
				(string) $row['claimant_email'],
				'Your LexRanked profile claim was approved',
				sprintf( "Hello %s,\n\nYour claim of the profile \"%s\" has been approved. The profile now shows that it is claimed.\n\nTo correct information on the profile, reply to this email with the change and a public source for it. Claiming a profile never changes its ranking position.\n\nLexRanked\n", (string) $row['claimant_name'], get_the_title( $post ) )
			);
		}
	}

	/**
	 * Reject a claim, or revoke an approved one.
	 *
	 * @param int    $claim_id Claim ID.
	 * @param string $note     Private reviewer note.
	 * @param bool   $notify   Email the claimant.
	 * @throws CommercialException When the claim cannot be rejected.
	 */
	public function reject( int $claim_id, string $note = '', bool $notify = true ): void {
		$row = $this->claims->find( $claim_id );
		if ( null === $row ) {
			throw new CommercialException( 'lexranked_claim_missing', 'Claim not found.', 404 );
		}
		$this->transition( $row, ClaimStatus::Rejected );
		$this->claims->update(
			$claim_id,
			array(
				'status'           => ClaimStatus::Rejected->value,
				'email_token_hash' => null,
				'review_note'      => mb_substr( sanitize_textarea_field( $note ), 0, 1000 ),
				'reviewed_by'      => get_current_user_id(),
				'reviewed_at'      => $this->now()->format( 'Y-m-d H:i:s' ),
			)
		);
		AuditLog::log( ClaimStatus::Approved->value === $row['status'] ? 'claim.revoked' : 'claim.rejected', 'profile_claim', $claim_id, array( 'entity_id' => (int) $row['entity_id'] ) );
		$this->sync_status( (int) $row['entity_id'] );
		if ( $notify && null !== $row['email_verified_at'] ) {
			$this->mail(
				(string) $row['claimant_email'],
				'Your LexRanked profile claim',
				sprintf( "Hello %s,\n\nWe could not confirm your claim of this profile. If you believe this is a mistake, reply to this email with a way to verify your identity (for example your state bar number or a firm email address).\n\nLexRanked\n", (string) $row['claimant_name'] )
			);
		}
	}

	/**
	 * Reviewer view of a claim: the row plus signals.
	 *
	 * @param array<string, mixed> $row Claim row.
	 * @return array<string, mixed>
	 */
	public function signals( array $row ): array {
		$post    = get_post( (int) $row['entity_id'] );
		$fields  = $post instanceof \WP_Post ? $this->record( $post )['fields'] : array();
		$website = (string) ( $fields['website'] ?? '' );
		if ( '' === $website && ! empty( $fields['firm_id'] ) ) {
			$website = (string) get_post_meta( (int) $fields['firm_id'], '_lr_website', true );
		}
		$approved = $this->claims->approved_for( (int) $row['entity_id'] );
		return array(
			'bar'             => 'lawyer' === $row['entity_type'] ? ClaimSignals::bar( (string) $row['bar_state'], (string) $row['bar_number'], (string) ( $fields['bar_state'] ?? '' ), (string) ( $fields['bar_number'] ?? '' ) ) : 'unknown',
			'email_domain'    => ClaimSignals::email_domain( (string) $row['claimant_email'], $website ),
			'already_claimed' => null !== $approved && (int) $approved['claim_id'] !== (int) $row['claim_id'],
			'profile_bar'     => trim( (string) ( $fields['bar_state'] ?? '' ) . ' ' . (string) ( $fields['bar_number'] ?? '' ) ),
			'website'         => $website,
		);
	}

	/**
	 * Enforce the claim state machine.
	 *
	 * @param array<string, mixed> $row Claim row.
	 * @param ClaimStatus          $to  Target.
	 * @throws CommercialException When not allowed.
	 */
	private function transition( array $row, ClaimStatus $to ): void {
		$from = ClaimStatus::tryFrom( (string) $row['status'] );
		if ( null === $from || ! $from->can_become( $to ) ) {
			throw new CommercialException( 'lexranked_claim_state', sprintf( 'A %s claim cannot become %s.', str_replace( '_', ' ', (string) $row['status'] ), $to->value ), 409 );
		}
	}

	/**
	 * URL-safe random token (the email link carries it; only its hash is stored).
	 */
	private function new_token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token encoding.
	}

	/**
	 * Send the confirmation email.
	 *
	 * @param string   $email Claimant email.
	 * @param string   $name  Claimant name.
	 * @param \WP_Post $post  Profile.
	 * @param string   $token Token.
	 */
	private function mail_confirmation( string $email, string $name, \WP_Post $post, string $token ): void {
		$base = (string) $this->services->settings->get( 'frontend_url' );
		$base = '' === $base ? home_url() : $base;
		$link = rtrim( $base, '/' ) . '/claim/confirm/?token=' . rawurlencode( $token );
		$this->mail(
			$email,
			'Confirm your LexRanked profile claim',
			sprintf(
				"Hello %s,\n\nYou asked to claim the profile \"%s\" on LexRanked. Confirm your email address within 48 hours:\n\n%s\n\nAfter you confirm, an editor checks your identity (for example against the state bar record) before the claim is approved. Claiming a profile is free and never changes its ranking position.\n\nIf you did not make this request, ignore this email.\n\nLexRanked\n",
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
		$sent = wp_mail( $to, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		if ( ! $sent ) {
			AuditLog::log( 'mail.failed', 'profile_claim', 0, array( 'subject' => $subject ) );
		}
	}

	// ---------------------------------------------------------------------
	// Placements.
	// ---------------------------------------------------------------------

	/**
	 * Create or update a placement after validation and eligibility checks.
	 *
	 * @param array<string, mixed> $input Admin input.
	 * @param int                  $id    Placement ID to update, or 0.
	 * @return int Placement ID.
	 * @throws ValidationException When the input is invalid.
	 * @throws CommercialException When the profile is not eligible.
	 */
	public function save_placement( array $input, int $id = 0 ): int {
		$row  = PlacementPolicy::validate( $input );
		$post = get_post( $row['entity_id'] );
		if ( ! $post instanceof \WP_Post || self::post_type( $row['entity_type'] ) !== $post->post_type ) {
			throw new ValidationException( 'entity_id', 'must be a ' . str_replace( '_', ' ', $row['entity_type'] ) . ' ID' );
		}
		if ( 'active' === $row['status'] ) {
			$reasons = PlacementPolicy::ineligibility( $this->eligibility_facts( $post, $row ) );
			if ( array() !== $reasons ) {
				throw new CommercialException( 'lexranked_placement_ineligible', 'Not eligible: ' . implode( '; ', $reasons ) . '.', 400 );
			}
		}
		if ( $id > 0 ) {
			if ( null === $this->placements->find( $id ) ) {
				throw new CommercialException( 'lexranked_placement_missing', 'Placement not found.', 404 );
			}
			$this->placements->update( $id, $row );
		} else {
			$id = $this->placements->insert( $row, get_current_user_id() );
		}
		AuditLog::log(
			'placement.saved',
			'placement',
			$id,
			array(
				'entity_id' => $row['entity_id'],
				'product'   => $row['product'],
				'status'    => $row['status'],
			)
		);
		$this->sync_status( $row['entity_id'] );
		$this->refresh();
		return $id;
	}

	/**
	 * Cancel a placement.
	 *
	 * @param int $id Placement ID.
	 * @throws CommercialException When missing.
	 */
	public function cancel_placement( int $id ): void {
		$row = $this->placements->find( $id );
		if ( null === $row ) {
			throw new CommercialException( 'lexranked_placement_missing', 'Placement not found.', 404 );
		}
		$this->placements->update( $id, array( 'status' => 'cancelled' ) );
		AuditLog::log( 'placement.cancelled', 'placement', $id, array( 'entity_id' => (int) $row['entity_id'] ) );
		$this->sync_status( (int) $row['entity_id'] );
		$this->refresh();
	}

	/**
	 * Facts for PlacementPolicy::ineligibility().
	 *
	 * @param \WP_Post             $post Profile.
	 * @param array<string, mixed> $row  Placement row.
	 * @return array{published: bool, claimed: bool, bar_status: string|null, in_scope: bool}
	 */
	private function eligibility_facts( \WP_Post $post, array $row ): array {
		$bar = Lawyer::SLUG === $post->post_type ? get_post_meta( $post->ID, '_lr_bar_status', true ) : '';
		return array(
			'published'  => 'publish' === $post->post_status,
			'claimed'    => null !== $this->claims->approved_for( $post->ID ),
			'bar_status' => '' === $bar ? null : (string) $bar,
			'in_scope'   => $this->in_scope( $post, $row ),
		);
	}

	/**
	 * Whether a profile belongs on the placement's page.
	 *
	 * @param \WP_Post             $post Profile.
	 * @param array<string, mixed> $row  Placement row.
	 */
	private function in_scope( \WP_Post $post, array $row ): bool {
		$locations = array_map( static fn( array $t ): int => (int) $t['id'], $this->services->entities->location_terms( $post->ID ) );
		$practices = array_map( static fn( array $t ): int => (int) $t['id'], $this->services->entities->practice_terms( $post->ID ) );
		$product   = Product::from( (string) $row['product'] );
		if ( Product::Sponsored === $product ) {
			$ranking = get_post( (int) $row['ranking_id'] );
			if ( ! $ranking instanceof \WP_Post || Ranking::SLUG !== $ranking->post_type ) {
				return false;
			}
			$type = (string) get_post_meta( $ranking->ID, '_lr_entity_type', true );
			if ( self::entity_type( $post->post_type ) !== ( '' === $type ? 'lawyer' : $type ) ) {
				return false;
			}
			$loc = wp_get_post_terms( $ranking->ID, Location::SLUG, array( 'fields' => 'ids' ) );
			$pra = wp_get_post_terms( $ranking->ID, PracticeArea::SLUG, array( 'fields' => 'ids' ) );
			return PlacementPolicy::in_scope( $locations, $practices, is_array( $loc ) ? (int) ( $loc[0] ?? 0 ) : 0, is_array( $pra ) ? (int) ( $pra[0] ?? 0 ) : 0 );
		}
		if ( Product::Featured === $product ) {
			return PlacementPolicy::in_scope( $locations, $practices, (int) $row['location_term_id'], (int) $row['practice_area_term_id'] );
		}
		return true;
	}

	/**
	 * Placements shown on a page, as public DTOs.
	 *
	 * @param string $product  featured|sponsored.
	 * @param int    $ranking  Ranking ID (sponsored).
	 * @param int    $location Location term ID (featured).
	 * @param int    $practice Practice-area term ID (featured).
	 * @return array<int, array<string, mixed>>
	 */
	public function for_page( string $product, int $ranking = 0, int $location = 0, int $practice = 0 ): array {
		$now      = $this->now();
		$rows     = array_filter(
			$this->placements->current( $product, $now->format( 'Y-m-d H:i:s' ) ),
			static fn( array $r ): bool => 'sponsored' === $product
				? (int) $r['ranking_id'] === $ranking && $ranking > 0
				: (int) $r['location_term_id'] === $location && (int) $r['practice_area_term_id'] === $practice
		);
		$eligible = array();
		foreach ( $rows as $row ) {
			$post = get_post( (int) $row['entity_id'] );
			if ( $post instanceof \WP_Post && array() === PlacementPolicy::ineligibility( $this->eligibility_facts( $post, $row ) ) ) {
				$eligible[] = $row;
			}
		}
		$limit    = (int) $this->services->settings->get( 'sponsored' === $product ? 'max_sponsored_per_ranking' : 'max_featured_per_page' );
		$selected = PlacementPolicy::select( $eligible, $now, $limit );
		$out      = array();
		foreach ( $selected as $row ) {
			$post    = get_post( (int) $row['entity_id'] );
			$summary = Lawyer::SLUG === $post->post_type
				? $this->services->presenter->lawyer_summaries( array( $post ) )[0]
				: $this->services->presenter->firm_summaries( array( $post ) )[0];
			$out[]   = array(
				'id'              => (int) $row['placement_id'],
				'product'         => $product,
				'label'           => Product::from( $product )->label(),
				'isPaidPlacement' => true,
				'disclosure'      => self::DISCLOSURE_LISTING,
				'entity'          => $summary,
			);
		}
		return $out;
	}

	/**
	 * Premium content of a profile (only while claimed and the placement is live).
	 *
	 * @param int $entity_id Entity ID.
	 * @return array{label: string, message: string|null, ctaUrl: string|null, disclosure: string}|null
	 */
	public function premium_content( int $entity_id ): ?array {
		$live = $this->live_premium( $entity_id );
		if ( null === $live || null === $this->claims->approved_for( $entity_id ) ) {
			return null;
		}
		return array(
			'label'      => Product::Premium->label(),
			'message'    => '' === (string) $live['premium_message'] ? null : (string) $live['premium_message'],
			'ctaUrl'     => '' === (string) $live['cta_url'] ? null : (string) $live['cta_url'],
			'disclosure' => self::DISCLOSURE_PREMIUM,
		);
	}

	/**
	 * The live premium placement of an entity.
	 *
	 * @param int $entity_id Entity ID.
	 * @return array<string, mixed>|null
	 */
	private function live_premium( int $entity_id ): ?array {
		$now = $this->now();
		foreach ( $this->placements->for_entity( $entity_id ) as $row ) {
			if ( Product::Premium->value === $row['product'] && PlacementPolicy::is_live( $row, $now ) ) {
				return $row;
			}
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Status and maintenance.
	// ---------------------------------------------------------------------

	/**
	 * Recompute the display-only commercial status of an entity.
	 *
	 * @param int $entity_id Entity ID.
	 * @return bool Changed.
	 */
	public function sync_status( int $entity_id ): bool {
		$post = get_post( $entity_id );
		if ( ! $post instanceof \WP_Post || null === self::entity_type( $post->post_type ) ) {
			return false;
		}
		$status = StatusResolver::resolve( null !== $this->claims->approved_for( $entity_id ), null !== $this->live_premium( $entity_id ) );
		$old    = (string) get_post_meta( $entity_id, '_lr_commercial_status', true );
		if ( ( '' === $old ? CommercialStatus::Free->value : $old ) === $status->value ) {
			return false;
		}
		if ( CommercialStatus::Free === $status ) {
			delete_post_meta( $entity_id, '_lr_commercial_status' );
		} else {
			update_post_meta( $entity_id, '_lr_commercial_status', $status->value );
		}
		$this->refresh();
		return true;
	}

	/**
	 * Recompute every entity that has, or had, a commercial status.
	 *
	 * @return int Entities changed.
	 */
	public function sync_all(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off maintenance query.
		$with_meta = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_lr_commercial_status' ) ) );
		$ids       = array_unique( array_merge( $with_meta, $this->placements->entity_ids(), array_map( static fn( array $r ): int => (int) $r['entity_id'], $this->claims->list( ClaimStatus::Approved->value, 10000 ) ) ) );
		$changed   = 0;
		foreach ( $ids as $id ) {
			$changed += $this->sync_status( (int) $id ) ? 1 : 0;
		}
		return $changed;
	}

	/**
	 * Hourly: expire links, purge personal data, apply placement start/end.
	 */
	public function hourly(): void {
		$now = $this->now();
		$this->claims->expire_unconfirmed( $now->format( 'Y-m-d H:i:s' ) );
		$this->claims->purge_closed( $now->modify( '-' . self::PURGE_AFTER_DAYS . ' days' )->format( 'Y-m-d H:i:s' ) );
		$this->sync_all();
		// Placements that started or ended since the last run change pages.
		$live = array();
		foreach ( Product::cases() as $product ) {
			foreach ( $this->placements->current( $product->value, $now->format( 'Y-m-d H:i:s' ) ) as $row ) {
				if ( PlacementPolicy::is_live( $row, $now ) ) {
					$live[] = (int) $row['placement_id'];
				}
			}
		}
		sort( $live );
		$fingerprint = md5( implode( ',', $live ) );
		if ( get_option( self::FINGERPRINT_OPTION ) !== $fingerprint ) {
			update_option( self::FINGERPRINT_OPTION, $fingerprint, false );
			$this->refresh();
		}
	}

	/**
	 * Tell the frontend that commercial content changed.
	 */
	private function refresh(): void {
		$this->services->revalidator->touch( '/' );
	}

	/**
	 * Entity record.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private function record( \WP_Post $post ): array {
		return $this->services->entities->record( $post, LawFirm::SLUG === $post->post_type ? $this->services->law_firm : $this->services->lawyer );
	}
}
