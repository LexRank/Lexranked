<?php
/**
 * Rules for publishing research results without an editor.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

use LexRanked\Core\Domain\VerificationStatus;

/**
 * Autonomous research (Settings → "Autonomous research") publishes what a
 * research job created only when every check below passes. Anything with a
 * doubt stays a draft, with the reasons recorded for the editor.
 *
 * The rules are deterministic and deliberately strict: a profile is
 * published only when an official (tier 1) source has verified the facts a
 * reader relies on most, nothing conflicts and nothing is missing. No AI
 * output and no payment can satisfy a rule.
 */
final class AutoPublishPolicy {

	/** Verified checks each entity type needs (tier 1 is enforced by VerificationRules). */
	public const REQUIRED_VERIFICATIONS = array(
		'lawyer'   => array( 'license', 'bar_status' ),
		'law_firm' => array( 'business' ),
	);

	/** Least authoritative source tier that is published automatically. */
	public const MAX_SOURCE_TIER = 2;

	/**
	 * Decide whether a research-created entity can be published.
	 *
	 * @param array<string, mixed> $entity {
	 *     entity_type: 'lawyer'|'law_firm', status: string, title: string,
	 *     fields: array<string, mixed>, city: ?string, state: ?string,
	 *     practice_area_count: int, flagged_for_review: bool,
	 *     verifications: array<int, array{type: string, status: string}>
	 * }.
	 * @return array{publish: bool, reasons: array<int, string>} Reasons are empty when publishing.
	 */
	public static function decide_entity( array $entity ): array {
		$type    = (string) ( $entity['entity_type'] ?? '' );
		$fields  = is_array( $entity['fields'] ?? null ) ? $entity['fields'] : array();
		$reasons = array();

		if ( ! array_key_exists( $type, self::REQUIRED_VERIFICATIONS ) ) {
			return array(
				'publish' => false,
				'reasons' => array( 'unknown entity type' ),
			);
		}
		if ( 'draft' !== ( $entity['status'] ?? '' ) ) {
			$reasons[] = 'not a draft (status ' . (string) ( $entity['status'] ?? '' ) . ')';
		}
		if ( ! empty( $fields['is_demo'] ) ) {
			$reasons[] = 'marked as demo data';
		}
		if ( ! empty( $entity['flagged_for_review'] ) ) {
			$reasons[] = 'sources disagree on at least one fact';
		}
		if ( '' === trim( (string) ( $entity['title'] ?? '' ) ) ) {
			$reasons[] = 'no name';
		}
		if ( self::blank( $entity['city'] ?? null ) || self::blank( $entity['state'] ?? null ) ) {
			$reasons[] = 'no city and state';
		}
		if ( (int) ( $entity['practice_area_count'] ?? 0 ) < 1 ) {
			$reasons[] = 'no practice area';
		}

		if ( 'lawyer' === $type ) {
			if ( self::blank( $fields['bar_state'] ?? null ) || self::blank( $fields['bar_number'] ?? null ) ) {
				$reasons[] = 'no bar state and bar number';
			}
			if ( 'active' !== strtolower( (string) ( $fields['bar_status'] ?? '' ) ) ) {
				$reasons[] = 'bar status is not active';
			}
		} elseif ( self::blank( $fields['website'] ?? null ) ) {
			$reasons[] = 'no website';
		}

		$records = is_array( $entity['verifications'] ?? null ) ? $entity['verifications'] : array();
		foreach ( self::REQUIRED_VERIFICATIONS[ $type ] as $required ) {
			$status = self::best_status( $records, $required );
			if ( VerificationStatus::Verified->value !== $status ) {
				$reasons[] = null === $status
					? sprintf( 'no %s check', str_replace( '_', ' ', $required ) )
					: sprintf( '%s check is %s, not verified by an official source', str_replace( '_', ' ', $required ), $status );
			}
		}

		return array(
			'publish' => array() === $reasons,
			'reasons' => $reasons,
		);
	}

	/**
	 * Whether a research-created source can be published: it backs a published
	 * entity and is authoritative enough.
	 *
	 * @param int  $tier               Source tier (1 = most authoritative).
	 * @param bool $backs_published    Used by a record or entity that is being published.
	 */
	public static function publish_source( int $tier, bool $backs_published ): bool {
		return $backs_published && $tier >= 1 && $tier <= self::MAX_SOURCE_TIER;
	}

	/**
	 * Whether a new check about an already-published profile can be published:
	 * it is verified (tier 1 is enforced by VerificationRules) and no published
	 * record of the same check says otherwise.
	 *
	 * @param string             $record_status      Status of the new record.
	 * @param array<int, string> $published_statuses Statuses of published records of the same check.
	 */
	public static function publish_record_for_published( string $record_status, array $published_statuses ): bool {
		if ( VerificationStatus::Verified->value !== $record_status ) {
			return false;
		}
		return array() === array_intersect( $published_statuses, array( VerificationStatus::Failed->value, VerificationStatus::Expired->value ) );
	}

	/**
	 * Whether new evidence about an already-published profile can be approved
	 * and applied without an editor: it comes from an official (tier 1)
	 * source, was not extracted by AI, targets a field stored on the profile
	 * and does not change a value already shown (the field is empty or
	 * already has this value). Anything else waits in the review queue.
	 *
	 * @param int    $tier           Source tier.
	 * @param string $method         Claim method (seed, structured_data, ai, …).
	 * @param bool   $profile_field  The field is stored on the profile (not name / location / practice areas).
	 * @param bool   $empty_or_equal The profile's current value is empty or equal to the claim.
	 */
	public static function approve_claim_for_published( int $tier, string $method, bool $profile_field, bool $empty_or_equal ): bool {
		return 1 === $tier && 'ai' !== $method && $profile_field && $empty_or_equal;
	}

	/**
	 * Decide whether to create a ranking for a city and practice area.
	 *
	 * @param int  $published_entities Published entities of the type in both terms.
	 * @param int  $min_entities       Minimum entities for a ranking (Settings).
	 * @param bool $ranking_exists     A ranking for the pair already exists (any status but trash).
	 * @return array{create: bool, reason: string}
	 */
	public static function decide_ranking( int $published_entities, int $min_entities, bool $ranking_exists ): array {
		if ( $ranking_exists ) {
			return array(
				'create' => false,
				'reason' => 'a ranking already exists',
			);
		}
		if ( $published_entities < max( 1, $min_entities ) ) {
			return array(
				'create' => false,
				'reason' => sprintf( '%d published profiles (needs %d)', $published_entities, max( 1, $min_entities ) ),
			);
		}
		return array(
			'create' => true,
			'reason' => sprintf( '%d published profiles', $published_entities ),
		);
	}

	/**
	 * Ranking title, e.g. "Best Personal Injury Lawyers in Miami, Florida".
	 *
	 * @param string $entity_type   lawyer|law_firm.
	 * @param string $practice_area Practice-area name.
	 * @param string $city          City name.
	 * @param string $state         State name.
	 */
	public static function ranking_title( string $entity_type, string $practice_area, string $city, string $state ): string {
		$noun = 'law_firm' === $entity_type ? 'Law Firms' : 'Lawyers';
		return sprintf( 'Best %s %s in %s, %s', trim( $practice_area ), $noun, trim( $city ), trim( $state ) );
	}

	/**
	 * The deciding status recorded for a check. A failed or expired record wins
	 * over a verified one (contradicting evidence is a doubt); verified wins
	 * over pending.
	 *
	 * @param array<int, array<string, mixed>> $records Records.
	 * @param string                           $type    Verification type.
	 */
	private static function best_status( array $records, string $type ): ?string {
		$statuses = array();
		foreach ( $records as $record ) {
			if ( is_array( $record ) && ( $record['type'] ?? null ) === $type ) {
				$statuses[] = (string) ( $record['status'] ?? '' );
			}
		}
		if ( array() === $statuses ) {
			return null;
		}
		foreach ( array( VerificationStatus::Failed->value, VerificationStatus::Expired->value, VerificationStatus::Verified->value ) as $status ) {
			if ( in_array( $status, $statuses, true ) ) {
				return $status;
			}
		}
		return VerificationStatus::Pending->value;
	}

	/**
	 * Empty value.
	 *
	 * @param mixed $value Value.
	 */
	private static function blank( mixed $value ): bool {
		return null === $value || ( is_string( $value ) && '' === trim( $value ) ) || ( is_array( $value ) && array() === $value );
	}
}
