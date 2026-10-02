<?php
/**
 * Verification statuses.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Domain;

/**
 * Status of a single verification record. Profiles additionally use
 * "unverified" when no applicable record exists (see VerificationPolicy).
 */
enum VerificationStatus: string {
	case Pending  = 'pending';
	case Verified = 'verified';
	case Failed   = 'failed';
	case Expired  = 'expired';

	/**
	 * All values.
	 *
	 * @return array<int, string>
	 */
	public static function values(): array {
		return array_map( static fn( self $s ): string => $s->value, self::cases() );
	}
}
