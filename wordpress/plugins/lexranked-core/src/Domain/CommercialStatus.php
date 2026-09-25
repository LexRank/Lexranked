<?php
/**
 * Commercial statuses.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Domain;

/**
 * Commercial relationship of a profile.
 *
 * This is deliberately a separate concept from the organic score: nothing in
 * the ranking code path may read it (see ADR-006).
 */
enum CommercialStatus: string {
	case Free      = 'free';
	case Claimed   = 'claimed';
	case Verified  = 'verified';
	case Featured  = 'featured';
	case Sponsored = 'sponsored';
	case Premium   = 'premium';

	/**
	 * All values.
	 *
	 * @return array<int, string>
	 */
	public static function values(): array {
		return array_map( static fn( self $s ): string => $s->value, self::cases() );
	}

	/**
	 * Whether placements for this status must carry a paid-placement label.
	 */
	public function is_paid_placement(): bool {
		return self::Featured === $this || self::Sponsored === $this;
	}
}
