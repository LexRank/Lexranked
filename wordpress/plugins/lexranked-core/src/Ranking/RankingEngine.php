<?php
/**
 * Ranking engine (ordering).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

/**
 * Scores a set of entities for one context and orders them deterministically:
 * score DESC, then number of sourced facts DESC, then entity ID ASC.
 * An LLM never decides positions.
 */
final class RankingEngine {

	/**
	 * Constructor.
	 *
	 * @param ScoreCalculator $calculator Calculator.
	 */
	public function __construct( private readonly ScoreCalculator $calculator = new ScoreCalculator() ) {
	}

	/**
	 * Rank inputs.
	 *
	 * @param array<int, EntityInput> $inputs  Inputs.
	 * @param RankingContext          $context Context.
	 * @param ScoreVersion            $version Version.
	 * @return array<int, array{position: int, input: EntityInput, result: ScoreResult}>
	 */
	public function rank( array $inputs, RankingContext $context, ScoreVersion $version ): array {
		$rows = array();
		foreach ( $inputs as $input ) {
			$rows[] = array(
				'input'  => $input,
				'result' => $this->calculator->calculate( $input, $context, $version ),
			);
		}
		usort(
			$rows,
			static fn( array $a, array $b ): int => array( $b['result']->total, $b['input']->sourced_facts, $a['input']->entity_id )
				<=> array( $a['result']->total, $a['input']->sourced_facts, $b['input']->entity_id )
		);
		$out = array();
		foreach ( $rows as $i => $row ) {
			$out[] = array( 'position' => $i + 1 ) + $row;
		}
		return $out;
	}
}
