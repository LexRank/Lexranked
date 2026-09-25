<?php
/**
 * Verification types.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Domain;

/**
 * What a verification record verifies.
 */
enum VerificationType: string {
	case Identity     = 'identity';
	case Business     = 'business';
	case Location     = 'location';
	case Website      = 'website';
	case License      = 'license';
	case BarStatus    = 'bar_status';
	case PracticeArea = 'practice_area';
	case ReviewData   = 'review_data';

	/**
	 * All values.
	 *
	 * @return array<int, string>
	 */
	public static function values(): array {
		return array_map( static fn( self $t ): string => $t->value, self::cases() );
	}
}
