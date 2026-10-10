<?php
/**
 * Review scoring contract.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

/**
 * Isolated review-normalization module (spec §13): it can be replaced without
 * touching the rest of the ranking system.
 */
interface ReviewScorer {

	/**
	 * Volume-adjusted rating (same 0-5 scale), or null when unknown.
	 *
	 * @param float|null $rating       Average star rating.
	 * @param int|null   $review_count Number of reviews.
	 */
	public function adjusted_rating( ?float $rating, ?int $review_count ): ?float;

	/**
	 * Normalized review strength in [0, 1].
	 *
	 * @param float|null $rating       Average star rating.
	 * @param int|null   $review_count Number of reviews.
	 */
	public function factor( ?float $rating, ?int $review_count ): float;
}
