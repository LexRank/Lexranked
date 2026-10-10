<?php
/**
 * Client review lifecycle.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Reviews;

/**
 * Lifecycle: pending_email → pending_review → approved | rejected. Only
 * approved reviews are public and count towards the rating of a profile.
 */
enum ReviewStatus: string {
	case PendingEmail  = 'pending_email';
	case PendingReview = 'pending_review';
	case Approved      = 'approved';
	case Rejected      = 'rejected';

	/**
	 * Allowed transitions.
	 *
	 * @param self $to Target status.
	 */
	public function can_become( self $to ): bool {
		return match ( $this ) {
			self::PendingEmail  => in_array( $to, array( self::PendingReview, self::Rejected ), true ),
			self::PendingReview => in_array( $to, array( self::Approved, self::Rejected ), true ),
			self::Approved      => self::Rejected === $to,
			self::Rejected      => self::Approved === $to,
		};
	}
}
