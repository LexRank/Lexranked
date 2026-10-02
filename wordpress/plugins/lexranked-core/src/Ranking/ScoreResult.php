<?php
/**
 * Score result.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

/**
 * Total score plus an explainable per-component breakdown.
 */
final class ScoreResult {

	/**
	 * Constructor.
	 *
	 * @param float                            $total      Total 0–100 (sum of component points).
	 * @param string                           $version    Score version id.
	 * @param array<int, array<string, mixed>> $components Components: key, label, weight, factor, points, explanation, missing.
	 */
	public function __construct(
		public readonly float $total,
		public readonly string $version,
		public readonly array $components
	) {
	}
}
