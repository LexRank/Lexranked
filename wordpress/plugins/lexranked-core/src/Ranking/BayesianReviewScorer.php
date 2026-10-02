<?php
/**
 * Bayesian review scorer.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

/**
 * Bayesian average: adjusted = (C × m + n × r) / (C + n).
 *
 * Few reviews pull the rating toward the prior mean m; many reviews let the
 * observed rating r dominate. The adjusted rating is mapped linearly from the
 * floor (→ 0) to 5.0 (→ 1).
 */
final class BayesianReviewScorer implements ReviewScorer {

	/**
	 * Constructor.
	 *
	 * @param float $prior_mean   m, the baseline rating.
	 * @param float $prior_weight C, confidence in the baseline (in reviews).
	 * @param float $floor        Adjusted rating that maps to 0.
	 */
	public function __construct(
		private readonly float $prior_mean,
		private readonly float $prior_weight,
		private readonly float $floor
	) {
	}

	/**
	 * Build from a score version.
	 *
	 * @param ScoreVersion $version Version.
	 */
	public static function for_version( ScoreVersion $version ): self {
		return new self( $version->param( 'review_prior_mean' ), $version->param( 'review_prior_weight' ), $version->param( 'review_floor' ) );
	}

	/**
	 * Volume-adjusted rating (0–5), or null when unknown.
	 *
	 * @param float|null $rating       Average star rating.
	 * @param int|null   $review_count Number of reviews.
	 */
	public function adjusted_rating( ?float $rating, ?int $review_count ): ?float {
		if ( null === $rating || null === $review_count || $review_count <= 0 ) {
			return null;
		}
		$r = max( 0.0, min( 5.0, $rating ) );
		return ( $this->prior_weight * $this->prior_mean + $review_count * $r ) / ( $this->prior_weight + $review_count );
	}

	/**
	 * Normalized review strength in [0, 1].
	 *
	 * @param float|null $rating       Average star rating.
	 * @param int|null   $review_count Number of reviews.
	 */
	public function factor( ?float $rating, ?int $review_count ): float {
		$adjusted = $this->adjusted_rating( $rating, $review_count );
		if ( null === $adjusted ) {
			return 0.0;
		}
		return max( 0.0, min( 1.0, ( $adjusted - $this->floor ) / ( 5.0 - $this->floor ) ) );
	}
}
