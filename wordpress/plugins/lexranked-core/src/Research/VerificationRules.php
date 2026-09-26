<?php
/**
 * Rules for automated verification results.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

use LexRanked\Core\Domain\VerificationStatus;
use LexRanked\Core\Domain\VerificationType;
use LexRanked\Core\Schema\ValidationException;

/**
 * An automated check may only be recorded as "verified" when the evidence
 * comes from a source authoritative enough for that check. Otherwise it is
 * stored as "pending" for human review. This keeps "verified" meaning
 * something (spec §15).
 */
final class VerificationRules {

	/** Maximum (least authoritative) tier that can verify each type automatically. */
	public const MAX_TIER = array(
		'license'       => 1,
		'bar_status'    => 1,
		'identity'      => 1,
		'business'      => 1,
		'location'      => 2,
		'website'       => 2,
		'practice_area' => 2,
		'review_data'   => 4,
	);

	/**
	 * Decide the status to store.
	 *
	 * @param string   $type             Verification type.
	 * @param string   $requested_status Status the worker requests.
	 * @param int|null $source_tier      Tier of the evidence source (null = no source).
	 * @return string Status to store.
	 * @throws ValidationException When the input is invalid.
	 */
	public static function decide( string $type, string $requested_status, ?int $source_tier ): string {
		if ( ! in_array( $type, VerificationType::values(), true ) ) {
			throw new ValidationException( 'verification_type', 'is not a valid verification type' );
		}
		if ( ! in_array( $requested_status, array( VerificationStatus::Verified->value, VerificationStatus::Failed->value, VerificationStatus::Pending->value ), true ) ) {
			throw new ValidationException( 'status', 'must be verified, failed or pending' );
		}
		if ( null === $source_tier ) {
			throw new ValidationException( 'source', 'is required for automated verification' );
		}
		if ( VerificationStatus::Verified->value === $requested_status && $source_tier > self::MAX_TIER[ $type ] ) {
			return VerificationStatus::Pending->value;
			// Not authoritative enough: a human must confirm.
		}
		return $requested_status;
	}
}
