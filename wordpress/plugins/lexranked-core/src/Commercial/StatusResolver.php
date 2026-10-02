<?php
/**
 * Entity-level commercial status.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Commercial;

use LexRanked\Core\Domain\CommercialStatus;

/**
 * Derives the status shown on a profile from its approved claim and live
 * premium placement. Featured and sponsored are placements on other pages,
 * never a profile status. The ranking engine never reads the result.
 *
 * `verified` is not used for claims: an approved claim always means the
 * editor checked the claimant's identity, and "verified" on LexRanked means
 * the organic verification of licence and records (VerificationBadge).
 */
final class StatusResolver {

	/**
	 * Resolve.
	 *
	 * @param bool $claimed      The profile has an approved claim.
	 * @param bool $premium_live A premium placement is live.
	 */
	public static function resolve( bool $claimed, bool $premium_live ): CommercialStatus {
		if ( ! $claimed ) {
			return CommercialStatus::Free;
		}
		return $premium_live ? CommercialStatus::Premium : CommercialStatus::Claimed;
	}
}
