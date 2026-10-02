<?php
/**
 * Profile claim lifecycle.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Commercial;

/**
 * Lifecycle: pending_email → pending_review → approved | rejected. Unconfirmed claims
 * expire. Approved claims can be revoked (→ rejected) by an editor.
 */
enum ClaimStatus: string {
	case PendingEmail  = 'pending_email';
	case PendingReview = 'pending_review';
	case Approved      = 'approved';
	case Rejected      = 'rejected';
	case Expired       = 'expired';

	/**
	 * Allowed transitions.
	 *
	 * @param self $to Target status.
	 */
	public function can_become( self $to ): bool {
		return match ( $this ) {
			self::PendingEmail  => in_array( $to, array( self::PendingReview, self::Expired, self::Rejected ), true ),
			self::PendingReview => in_array( $to, array( self::Approved, self::Rejected ), true ),
			self::Approved      => self::Rejected === $to,
			self::Rejected, self::Expired => false,
		};
	}

	/**
	 * Whether the claim is finished (its personal data can be purged after retention).
	 */
	public function is_closed(): bool {
		return self::Rejected === $this || self::Expired === $this;
	}

	/**
	 * All values.
	 *
	 * @return array<int, string>
	 */
	public static function values(): array {
		return array_map( static fn( self $s ): string => $s->value, self::cases() );
	}
}
