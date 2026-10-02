<?php
/**
 * Scoring input.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

/**
 * Everything the calculator may look at, and nothing else.
 *
 * There is deliberately no commercial/payment field here: payment status
 * cannot influence a score because the calculator never receives it
 * (ADR-006). Inputs are stored with every snapshot so each calculation can be
 * reproduced exactly.
 */
final class EntityInput {

	public const FIELDS = array(
		'entity_type',
		'entity_id',
		'rating',
		'review_count',
		'years_experience',
		'awards_count',
		'education_count',
		'bar_status',
		'practice_areas',
		'city',
		'state',
		'verification_status',
		'verification_checks',
		'present_fields',
		'sourced_fields',
		'sourced_facts',
		'lawyer_count',
	);

	/**
	 * Constructor.
	 *
	 * @param string                $entity_type         lawyer|law_firm.
	 * @param int                   $entity_id           Entity ID.
	 * @param float|null            $rating              Star rating 0–5.
	 * @param int|null              $review_count        Review count.
	 * @param int|null              $years_experience    Years in practice (firms: max of their lawyers).
	 * @param int                   $awards_count        Recorded awards.
	 * @param int                   $education_count     Recorded education entries.
	 * @param string|null           $bar_status          Bar status (lawyers).
	 * @param array<int, string>    $practice_areas      Practice-area slugs, primary first.
	 * @param string|null           $city                City slug.
	 * @param string|null           $state               State slug.
	 * @param string                $verification_status Profile verification status at calculation time.
	 * @param array<string, string> $verification_checks Verification type => status.
	 * @param array<int, string>    $present_fields      Key fields with a value.
	 * @param array<int, string>    $sourced_fields      Key fields backed by at least one evidence claim.
	 * @param int                   $sourced_facts       Number of evidence claims (tie-breaker).
	 * @param int                   $lawyer_count        Published lawyers (firms only).
	 */
	public function __construct(
		public readonly string $entity_type,
		public readonly int $entity_id,
		public readonly ?float $rating = null,
		public readonly ?int $review_count = null,
		public readonly ?int $years_experience = null,
		public readonly int $awards_count = 0,
		public readonly int $education_count = 0,
		public readonly ?string $bar_status = null,
		public readonly array $practice_areas = array(),
		public readonly ?string $city = null,
		public readonly ?string $state = null,
		public readonly string $verification_status = 'unverified',
		public readonly array $verification_checks = array(),
		public readonly array $present_fields = array(),
		public readonly array $sourced_fields = array(),
		public readonly int $sourced_facts = 0,
		public readonly int $lawyer_count = 0,
	) {
	}

	/**
	 * Serialize (for snapshots).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$out = array();
		foreach ( self::FIELDS as $field ) {
			$out[ $field ] = $this->{$field};
		}
		return $out;
	}

	/**
	 * Rebuild from a stored snapshot.
	 *
	 * @param array<string, mixed> $data Stored inputs.
	 */
	public static function from_array( array $data ): self {
		return new self(
			entity_type: (string) ( $data['entity_type'] ?? 'lawyer' ),
			entity_id: (int) ( $data['entity_id'] ?? 0 ),
			rating: isset( $data['rating'] ) ? (float) $data['rating'] : null,
			review_count: isset( $data['review_count'] ) ? (int) $data['review_count'] : null,
			years_experience: isset( $data['years_experience'] ) ? (int) $data['years_experience'] : null,
			awards_count: (int) ( $data['awards_count'] ?? 0 ),
			education_count: (int) ( $data['education_count'] ?? 0 ),
			bar_status: isset( $data['bar_status'] ) ? (string) $data['bar_status'] : null,
			practice_areas: array_values( array_map( 'strval', (array) ( $data['practice_areas'] ?? array() ) ) ),
			city: isset( $data['city'] ) ? (string) $data['city'] : null,
			state: isset( $data['state'] ) ? (string) $data['state'] : null,
			verification_status: (string) ( $data['verification_status'] ?? 'unverified' ),
			verification_checks: array_map( 'strval', (array) ( $data['verification_checks'] ?? array() ) ),
			present_fields: array_values( array_map( 'strval', (array) ( $data['present_fields'] ?? array() ) ) ),
			sourced_fields: array_values( array_map( 'strval', (array) ( $data['sourced_fields'] ?? array() ) ) ),
			sourced_facts: (int) ( $data['sourced_facts'] ?? 0 ),
			lawyer_count: (int) ( $data['lawyer_count'] ?? 0 ),
		);
	}
}
