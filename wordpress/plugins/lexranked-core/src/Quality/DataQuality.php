<?php
/**
 * Data Quality Score.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Quality;

use LexRanked\Core\Attribute\Attributes;
use LexRanked\Core\Fact\FactBuilder;
use LexRanked\Core\Verification\Freshness;

/**
 * How well a profile is documented - NOT how good the lawyer is.
 *
 * Five published dimensions, each 0-100, combined with fixed, versioned
 * weights. Pure: same facts, same checks, same time → same score. It is
 * shown next to the LexRank score and never feeds it (the ranking has its
 * own, separately disclosed "data quality" component).
 */
final class DataQuality {

	public const VERSION = 'dq-1.0';

	/** Dimension => [label, weight %, what it measures]. */
	public const DIMENSIONS = array(
		'completeness' => array( 'Completeness', 30, 'Share of the expected facts that are on record with a source (core facts count double).' ),
		'freshness'    => array( 'Freshness', 20, 'Share of those facts checked within their freshness window (e.g. bar status 30 days, reviews 7 days).' ),
		'source'       => array( 'Source quality', 20, 'How authoritative the sources behind the facts are (tier 1 = 100, tier 5 = 20).' ),
		'verification' => array( 'Verification coverage', 20, 'Half verified facts, half the required verification checks (identity, license, bar status for lawyers).' ),
		'consistency'  => array( 'Consistency', 10, 'Share of facts whose best sources agree (no unresolved conflicts).' ),
	);

	/** Expected facts per entity type; weight 2 = core fact. */
	public const EXPECTED = array(
		'lawyer'   => array(
			'name'             => 1,
			'city'             => 2,
			'state'            => 2,
			'practice_areas'   => 2,
			'bar_state'        => 1,
			'bar_number'       => 1,
			'bar_status'       => 2,
			'years_experience' => 1,
			'website'          => 1,
			'phone'            => 1,
			'rating'           => 1,
			'review_count'     => 1,
			'education'        => 1,
			'languages'        => 1,
		),
		'law_firm' => array(
			'name'           => 1,
			'city'           => 2,
			'state'          => 2,
			'practice_areas' => 2,
			'website'        => 2,
			'phone'          => 1,
			'address'        => 1,
			'zip_code'       => 1,
			'rating'         => 1,
			'review_count'   => 1,
		),
	);

	private const TIER_SCORE = array(
		1 => 100,
		2 => 80,
		3 => 60,
		4 => 40,
		5 => 20,
	);

	/**
	 * Evaluate.
	 *
	 * @param string                              $entity_type  lawyer|law_firm.
	 * @param array<string, array<string, mixed>> $facts        Fact rows keyed by attribute (value, status, source_tier, observed_at, verified_at).
	 * @param array<int, string>                  $profile_keys Attributes that have a value on the profile (with or without evidence).
	 * @param array<string, string>               $checks       Effective verification status per type.
	 * @param array<int, string>                  $required     Required verification types.
	 * @param Freshness                           $freshness    Freshness rules.
	 * @param \DateTimeImmutable                  $now          Now.
	 * @return array{score: float, version: string, dimensions: array<int, array{key: string, label: string, weight: int, score: float, detail: string}>, missing: array<int, string>, unsourced: array<int, string>, stale: array<int, string>, conflicts: array<int, string>}
	 */
	public static function evaluate( string $entity_type, array $facts, array $profile_keys, array $checks, array $required, Freshness $freshness, \DateTimeImmutable $now ): array {
		$expected = self::EXPECTED[ $entity_type ] ?? array();
		$facts    = array_intersect_key( $facts, Attributes::facts() );

		// Completeness: weighted share of expected facts backed by evidence.
		$total   = array_sum( $expected );
		$have    = 0;
		$missing = array();
		foreach ( $expected as $key => $weight ) {
			if ( isset( $facts[ $key ] ) ) {
				$have += $weight;
			} else {
				$missing[] = $key;
			}
		}
		$completeness = $total > 0 ? 100 * $have / $total : 0.0;
		$unsourced    = array_values( array_intersect( $missing, $profile_keys ) );

		// Freshness, source quality and consistency over the facts on record.
		$n         = count( $facts );
		$fresh     = 0;
		$tier_sum  = 0;
		$verified  = 0;
		$stale     = array();
		$conflicts = array();
		foreach ( $facts as $key => $fact ) {
			$attribute = Attributes::fact( (string) $key );
			$checked   = (string) ( $fact['verified_at'] ?? $fact['observed_at'] ?? '' );
			$state     = $freshness->evaluate( null === $attribute ? 'profile' : $attribute->freshness, '' === $checked ? null : str_replace( ' ', 'T', substr( $checked, 0, 19 ) ) . 'Z', $now );
			if ( $state['isStale'] ) {
				$stale[] = (string) $key;
			} else {
				++$fresh;
			}
			$tier_sum += self::TIER_SCORE[ (int) ( $fact['source_tier'] ?? 5 ) ] ?? 20;
			if ( FactBuilder::VERIFIED === ( $fact['status'] ?? '' ) ) {
				++$verified;
			}
			if ( FactBuilder::CONFLICT === ( $fact['status'] ?? '' ) ) {
				$conflicts[] = (string) $key;
			}
		}
		$freshness_score   = $n > 0 ? 100 * $fresh / $n : 0.0;
		$source_score      = $n > 0 ? $tier_sum / $n : 0.0;
		$consistency_score = $n > 0 ? 100 * ( $n - count( $conflicts ) ) / $n : 0.0;

		// Verification coverage: verified facts and required checks, half each.
		$passed             = count( array_filter( $required, static fn( string $t ): bool => 'verified' === ( $checks[ $t ] ?? null ) ) );
		$check_share        = array() === $required ? 0.0 : $passed / count( $required );
		$fact_share         = $n > 0 ? $verified / $n : 0.0;
		$verification_score = 100 * ( 0.5 * $fact_share + 0.5 * $check_share );

		$scores  = array(
			'completeness' => $completeness,
			'freshness'    => $freshness_score,
			'source'       => $source_score,
			'verification' => $verification_score,
			'consistency'  => $consistency_score,
		);
		$details = array(
			'completeness' => sprintf( '%d of %d expected facts on record with a source.', count( $expected ) - count( $missing ), count( $expected ) ),
			'freshness'    => sprintf( '%d of %d facts within their freshness window.', $fresh, $n ),
			'source'       => $n > 0 ? sprintf( 'Average source tier %.1f.', ( 100 - $source_score ) / 20 + 1 ) : 'No sourced facts yet.',
			'verification' => sprintf( '%d of %d facts verified; %d of %d required checks passed.', $verified, $n, $passed, count( $required ) ),
			'consistency'  => array() === $conflicts ? sprintf( 'No conflicts among %d facts.', $n ) : sprintf( '%d of %d facts have conflicting sources.', count( $conflicts ), $n ),
		);

		$total_score = 0.0;
		$dimensions  = array();
		foreach ( self::DIMENSIONS as $key => [ $label, $weight ] ) {
			$total_score += $weight * $scores[ $key ] / 100;
			$dimensions[] = array(
				'key'    => $key,
				'label'  => $label,
				'weight' => $weight,
				'score'  => round( $scores[ $key ], 1 ),
				'detail' => $details[ $key ],
			);
		}

		return array(
			'score'      => round( $total_score, 1 ),
			'version'    => self::VERSION,
			'dimensions' => $dimensions,
			'missing'    => $missing,
			'unsourced'  => $unsourced,
			'stale'      => $stale,
			'conflicts'  => $conflicts,
		);
	}

	/**
	 * The published model (methodology page).
	 *
	 * @return array{version: string, dimensions: array<int, array{key: string, label: string, weight: int, description: string}>, expected: array<string, array<string, int>>, sourceTierScores: array<int, int>}
	 */
	public static function model(): array {
		$dimensions = array();
		foreach ( self::DIMENSIONS as $key => [ $label, $weight, $description ] ) {
			$dimensions[] = array(
				'key'         => $key,
				'label'       => $label,
				'weight'      => $weight,
				'description' => $description,
			);
		}
		return array(
			'version'          => self::VERSION,
			'dimensions'       => $dimensions,
			'expected'         => self::EXPECTED,
			'sourceTierScores' => self::TIER_SCORE,
		);
	}
}
