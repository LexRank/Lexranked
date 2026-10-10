<?php
/**
 * Deterministic score calculator.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

/**
 * Pure function of (EntityInput, RankingContext, ScoreVersion) → ScoreResult.
 *
 * No clock, randomness, database or LLM is involved, so the same inputs and
 * version always produce the same score. A missing input scores 0 for its
 * component and is listed under `missing` - it is never estimated.
 */
final class ScoreCalculator {

	/** Key fields used for data-quality completeness. */
	public const KEY_FIELDS = array( 'rating', 'review_count', 'years_experience', 'bar_status', 'website', 'practice_areas', 'location' );

	/**
	 * Calculate a score.
	 *
	 * @param EntityInput    $input   Input.
	 * @param RankingContext $context Context.
	 * @param ScoreVersion   $version Version.
	 */
	public function calculate( EntityInput $input, RankingContext $context, ScoreVersion $version ): ScoreResult {
		$reviews = BayesianReviewScorer::for_version( $version );
		$factors = array(
			'reputation'         => $this->reputation( $input, $version ),
			'review_strength'    => $this->review_strength( $input, $reviews ),
			'experience'         => $this->experience( $input, $version ),
			'practice_relevance' => $this->practice_relevance( $input, $context ),
			'credentials'        => $this->credentials( $input ),
			'local_relevance'    => $this->local_relevance( $input, $context ),
			'data_quality'       => $this->data_quality( $input, $version ),
		);

		$components = array();
		$total      = 0.0;
		foreach ( $version->weights as $key => $weight ) {
			// A component weighted 0 is not part of this version (e.g. reviews in v1.2).
			if ( 0.0 === (float) $weight ) {
				continue;
			}
			$f            = $factors[ $key ];
			$factor       = max( 0.0, min( 1.0, $f['factor'] ) );
			$points       = round( $factor * (float) $weight, 2 );
			$total       += $points;
			$components[] = array(
				'key'         => $key,
				'label'       => ScoreVersion::COMPONENTS[ $key ],
				'weight'      => (float) $weight,
				'factor'      => round( $factor, 4 ),
				'points'      => $points,
				'explanation' => $f['explanation'],
				'missing'     => $f['missing'],
			);
		}
		return new ScoreResult( round( $total, 2 ), $version->id, $components );
	}

	/**
	 * Reputation: recorded awards (half) and review volume on a log scale (half);
	 * awards only in versions that do not score reviews.
	 *
	 * @param EntityInput  $in Input.
	 * @param ScoreVersion $v  Version.
	 * @return array{factor: float, explanation: string, missing: array<int, string>}
	 */
	private function reputation( EntityInput $in, ScoreVersion $v ): array {
		$awards = min( $in->awards_count, (int) $v->param( 'awards_cap' ) ) / $v->param( 'awards_cap' );
		$label  = sprintf( '%d recorded award%s (counted up to %d)', $in->awards_count, 1 === $in->awards_count ? '' : 's', (int) $v->param( 'awards_cap' ) );
		if ( ! $v->reviews_scored() ) {
			return array(
				'factor'      => $awards,
				'explanation' => $label . '.',
				'missing'     => array(),
			);
		}
		$cap     = $v->param( 'review_volume_cap' );
		$volume  = null === $in->review_count ? 0.0 : min( 1.0, log( 1 + max( 0, $in->review_count ) ) / log( 1 + $cap ) );
		$missing = null === $in->review_count ? array( 'review_count' ) : array();
		return array(
			'factor'      => 0.5 * $awards + 0.5 * $volume,
			'explanation' => sprintf(
				'%d recorded award%s (counted up to %d) and %s.',
				$in->awards_count,
				1 === $in->awards_count ? '' : 's',
				(int) $v->param( 'awards_cap' ),
				null === $in->review_count ? 'no review volume on record' : sprintf( '%d reviews of public review volume', $in->review_count )
			),
			'missing'     => $missing,
		);
	}

	/**
	 * Review strength: Bayesian-adjusted rating.
	 *
	 * @param EntityInput  $in      Input.
	 * @param ReviewScorer $reviews Review scorer.
	 * @return array{factor: float, explanation: string, missing: array<int, string>}
	 */
	private function review_strength( EntityInput $in, ReviewScorer $reviews ): array {
		$adjusted = $reviews->adjusted_rating( $in->rating, $in->review_count );
		if ( null === $adjusted ) {
			return array(
				'factor'      => 0.0,
				'explanation' => 'No rating with a review count on record.',
				'missing'     => array_values( array_filter( array( null === $in->rating ? 'rating' : null, null === $in->review_count ? 'review_count' : null ) ) ),
			);
		}
		return array(
			'factor'      => $reviews->factor( $in->rating, $in->review_count ),
			'explanation' => sprintf( '%.1f stars from %d reviews, adjusted for volume to %.2f.', (float) $in->rating, (int) $in->review_count, $adjusted ),
			'missing'     => array(),
		);
	}

	/**
	 * Experience: years in practice up to a cap.
	 *
	 * @param EntityInput  $in Input.
	 * @param ScoreVersion $v  Version.
	 * @return array{factor: float, explanation: string, missing: array<int, string>}
	 */
	private function experience( EntityInput $in, ScoreVersion $v ): array {
		$cap = $v->param( 'experience_cap_years' );
		if ( null === $in->years_experience ) {
			return array(
				'factor'      => 0.0,
				'explanation' => 'Years of experience not on record.',
				'missing'     => array( 'years_experience' ),
			);
		}
		return array(
			'factor'      => min( max( 0, $in->years_experience ), $cap ) / $cap,
			'explanation' => sprintf( '%d years in practice (full credit at %d).', $in->years_experience, (int) $cap ),
			'missing'     => array(),
		);
	}

	/**
	 * Practice-area relevance: listed in the ranked area; more focused scores higher.
	 *
	 * @param EntityInput    $in  Input.
	 * @param RankingContext $ctx Context.
	 * @return array{factor: float, explanation: string, missing: array<int, string>}
	 */
	private function practice_relevance( EntityInput $in, RankingContext $ctx ): array {
		$count = count( $in->practice_areas );
		if ( 0 === $count ) {
			return array(
				'factor'      => 0.0,
				'explanation' => 'No practice areas on record.',
				'missing'     => array( 'practice_areas' ),
			);
		}
		if ( null !== $ctx->practice_area && ! in_array( $ctx->practice_area, $in->practice_areas, true ) ) {
			return array(
				'factor'      => 0.0,
				'explanation' => 'Does not list the ranked practice area.',
				'missing'     => array(),
			);
		}
		return array(
			'factor'      => 0.5 + 0.5 / $count,
			'explanation' => sprintf( 'Practices in the ranked area; %d practice area%s listed in total.', $count, 1 === $count ? '' : 's' ),
			'missing'     => array(),
		);
	}

	/**
	 * Credentials. Lawyers: active bar status (0.5), verified license (0.25),
	 * education on record (0.25). Firms: verified business (0.5), verified
	 * website (0.25), at least one profiled lawyer (0.25).
	 *
	 * @param EntityInput $in Input.
	 * @return array{factor: float, explanation: string, missing: array<int, string>}
	 */
	private function credentials( EntityInput $in ): array {
		$checks = $in->verification_checks;
		if ( 'law_firm' === $in->entity_type ) {
			$parts   = array(
				'business registration verified' => 'verified' === ( $checks['business'] ?? null ) ? 0.5 : 0.0,
				'website verified'               => 'verified' === ( $checks['website'] ?? null ) ? 0.25 : 0.0,
				'profiled lawyers'               => $in->lawyer_count > 0 ? 0.25 : 0.0,
			);
			$missing = array();
		} else {
			$parts   = array(
				'active bar status'   => 'active' === $in->bar_status ? 0.5 : 0.0,
				'license verified'    => 'verified' === ( $checks['license'] ?? null ) ? 0.25 : 0.0,
				'education on record' => $in->education_count > 0 ? 0.25 : 0.0,
			);
			$missing = null === $in->bar_status ? array( 'bar_status' ) : array();
		}
		$met = array_keys( array_filter( $parts ) );
		return array(
			'factor'      => array_sum( $parts ),
			'explanation' => array() === $met ? 'No qualifying credentials verified yet.' : 'Credit for: ' . implode( ', ', $met ) . '.',
			'missing'     => $missing,
		);
	}

	/**
	 * Local relevance: based in the ranked city (1), same state (0.5).
	 *
	 * @param EntityInput    $in  Input.
	 * @param RankingContext $ctx Context.
	 * @return array{factor: float, explanation: string, missing: array<int, string>}
	 */
	private function local_relevance( EntityInput $in, RankingContext $ctx ): array {
		if ( null === $in->city && null === $in->state ) {
			return array(
				'factor'      => 0.0,
				'explanation' => 'Location not on record.',
				'missing'     => array( 'location' ),
			);
		}
		if ( null !== $ctx->city && $in->city === $ctx->city ) {
			return array(
				'factor'      => 1.0,
				'explanation' => 'Based in the ranked city.',
				'missing'     => array(),
			);
		}
		if ( null === $ctx->city && null !== $ctx->state && $in->state === $ctx->state ) {
			return array(
				'factor'      => 1.0,
				'explanation' => 'Based in the ranked state.',
				'missing'     => array(),
			);
		}
		if ( null !== $ctx->state && $in->state === $ctx->state ) {
			return array(
				'factor'      => 0.5,
				'explanation' => 'Based elsewhere in the same state.',
				'missing'     => array(),
			);
		}
		return array(
			'factor'      => null === $ctx->city && null === $ctx->state ? 1.0 : 0.0,
			'explanation' => null === $ctx->city && null === $ctx->state ? 'No location restriction.' : 'Based outside the ranked location.',
			'missing'     => array(),
		);
	}

	/**
	 * Data quality: completeness (0.4), sourced facts (0.3), verified profile (0.3; pending 0.15).
	 *
	 * @param EntityInput  $in Input.
	 * @param ScoreVersion $v  Version.
	 * @return array{factor: float, explanation: string, missing: array<int, string>}
	 */
	private function data_quality( EntityInput $in, ScoreVersion $v ): array {
		$keys     = $v->reviews_scored() ? self::KEY_FIELDS : array_values( array_diff( self::KEY_FIELDS, array( 'rating', 'review_count' ) ) );
		$total    = count( $keys );
		$present  = count( array_intersect( $keys, $in->present_fields ) );
		$sourced  = count( array_intersect( $keys, $in->sourced_fields ) );
		$verified = match ( $in->verification_status ) {
			'verified' => 1.0,
			'pending' => 0.5,
			default => 0.0,
		};
		return array(
			'factor'      => 0.4 * $present / $total + 0.3 * $sourced / $total + 0.3 * $verified,
			'explanation' => sprintf( '%d of %d key facts on record, %d backed by sources; profile %s.', $present, $total, $sourced, $in->verification_status ),
			'missing'     => array_values( array_diff( $keys, $in->present_fields ) ),
		);
	}
}
