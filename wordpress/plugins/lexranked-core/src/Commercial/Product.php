<?php
/**
 * Commercial products.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Commercial;

/**
 * What a placement buys. None of them touches the organic score or position:
 *
 * - premium:   extra, labelled content on the profile page (message, call to action).
 * - featured:  a labelled card in the "Featured" block of a state, city or practice-area page.
 * - sponsored: a labelled card in the "Sponsored" block of one ranking page, outside the ranked list.
 */
enum Product: string {
	case Premium   = 'premium';
	case Featured  = 'featured';
	case Sponsored = 'sponsored';

	/**
	 * Placements shown as separate cards must carry the paid label.
	 */
	public function is_listing(): bool {
		return self::Premium !== $this;
	}

	/**
	 * Public label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Premium   => 'Premium profile',
			self::Featured  => 'Featured',
			self::Sponsored => 'Sponsored',
		};
	}

	/**
	 * All values.
	 *
	 * @return array<int, string>
	 */
	public static function values(): array {
		return array_map( static fn( self $p ): string => $p->value, self::cases() );
	}
}
