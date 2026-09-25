<?php
/**
 * Profile verification policy.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Verification;

use LexRanked\Core\Domain\VerificationStatus;

/**
 * Derives a profile's verification status from its verification records.
 *
 * A profile is "verified" only when EVERY required verification type has a
 * record whose status is verified and which has not expired. Pure logic:
 * time is passed in, so results are deterministic and testable.
 */
final class VerificationPolicy {

	public const UNVERIFIED = 'unverified';

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $required_types Verification types required for "verified".
	 */
	public function __construct( private readonly array $required_types ) {
	}

	/**
	 * Effective status of one record at $now (verified records past expires_at are expired).
	 *
	 * @param array{status: string, expires_at?: string|null} $record Record.
	 * @param \DateTimeImmutable                              $now    Current time.
	 */
	public static function effective_status( array $record, \DateTimeImmutable $now ): string {
		$status = $record['status'];
		if ( VerificationStatus::Verified->value === $status && ! empty( $record['expires_at'] ) ) {
			try {
				if ( new \DateTimeImmutable( $record['expires_at'] ) <= $now ) {
					return VerificationStatus::Expired->value;
				}
			} catch ( \Exception $e ) {
				// An unparseable expiry cannot be trusted as valid.
				return VerificationStatus::Expired->value;
			}
		}
		return $status;
	}

	/**
	 * Evaluate a profile.
	 *
	 * @param array<int, array{type: string, status: string, verified_at?: string|null, expires_at?: string|null}> $records Verification records for one entity.
	 * @param \DateTimeImmutable                                                                                   $now     Current time.
	 * @return array{status: string, verified_at: string|null, types: array<string, string>}
	 */
	public function evaluate( array $records, \DateTimeImmutable $now ): array {
		// Best status per type: verified > pending > expired > failed; newest wins within a status.
		$rank    = array(
			VerificationStatus::Verified->value => 4,
			VerificationStatus::Pending->value  => 3,
			VerificationStatus::Expired->value  => 2,
			VerificationStatus::Failed->value   => 1,
		);
		$by_type = array();
		foreach ( $records as $record ) {
			$status   = self::effective_status( $record, $now );
			$type     = $record['type'];
			$current  = $by_type[ $type ] ?? null;
			$verified = $record['verified_at'] ?? null;
			if ( null === $current
				|| ( $rank[ $status ] ?? 0 ) > ( $rank[ $current['status'] ] ?? 0 )
				|| ( $status === $current['status'] && (string) $verified > (string) $current['verified_at'] )
			) {
				$by_type[ $type ] = array(
					'status'      => $status,
					'verified_at' => $verified,
				);
			}
		}

		$types = array_map( static fn( array $r ): string => $r['status'], $by_type );
		ksort( $types );

		if ( array() === $this->required_types ) {
			return array(
				'status'      => self::UNVERIFIED,
				'verified_at' => null,
				'types'       => $types,
			);
		}

		$required = array();
		foreach ( $this->required_types as $type ) {
			$required[ $type ] = $by_type[ $type ] ?? null;
		}
		$statuses = array_map( static fn( ?array $r ): ?string => $r['status'] ?? null, $required );

		if ( in_array( VerificationStatus::Failed->value, $statuses, true ) ) {
			$status = VerificationStatus::Failed->value;
		} elseif ( in_array( VerificationStatus::Expired->value, $statuses, true ) ) {
			$status = VerificationStatus::Expired->value;
		} elseif ( array( VerificationStatus::Verified->value ) === array_values( array_unique( $statuses ) ) ) {
			$status = VerificationStatus::Verified->value;
		} elseif ( in_array( VerificationStatus::Pending->value, $statuses, true ) ) {
			$status = VerificationStatus::Pending->value;
		} else {
			$status = self::UNVERIFIED;
		}

		$verified_at = null;
		if ( VerificationStatus::Verified->value === $status ) {
			// The profile is only as fresh as its oldest required verification.
			$dates       = array_filter( array_map( static fn( array $r ): ?string => $r['verified_at'], $required ) );
			$verified_at = array() === $dates ? null : min( $dates );
		}

		return array(
			'status'      => $status,
			'verified_at' => $verified_at,
			'types'       => $types,
		);
	}
}
