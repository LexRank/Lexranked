<?php
/**
 * Score version (methodology configuration).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

/**
 * An immutable, named set of weights and parameters. Scores always record the
 * version that produced them; changing weights means creating a new version,
 * never editing one that has been used.
 */
final class ScoreVersion {

	public const COMPONENTS = array(
		'reputation'         => 'Reputation',
		'review_strength'    => 'Review strength',
		'experience'         => 'Experience',
		'practice_relevance' => 'Practice-area relevance',
		'credentials'        => 'Professional credentials',
		'local_relevance'    => 'Local relevance',
		'data_quality'       => 'Data quality',
	);

	/** Where the engine reads entity data: profile fields (v1.0) or the evidence-backed fact layer (v1.1+). */
	public const INPUT_PROFILE = 'profile';
	public const INPUT_FACTS   = 'facts';

	public const REQUIRED_PARAMS = array(
		'review_prior_mean',
		'review_prior_weight',
		'review_floor',
		'review_volume_cap',
		'awards_cap',
		'experience_cap_years',
	);

	/**
	 * Constructor.
	 *
	 * @param string               $id      Version id, e.g. "v1.0".
	 * @param array<string, float> $weights Component key => weight (sum 100).
	 * @param array<string, float> $params  Calculation parameters.
	 * @param string               $input   Input source: INPUT_PROFILE or INPUT_FACTS.
	 * @throws \InvalidArgumentException When the definition is invalid.
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $weights,
		public readonly array $params,
		public readonly string $input = self::INPUT_PROFILE
	) {
		if ( ! in_array( $input, array( self::INPUT_PROFILE, self::INPUT_FACTS ), true ) ) {
			throw new \InvalidArgumentException( 'Input source must be profile or facts.' );
		}
		if ( ! preg_match( '/^v\d+\.\d+(-[a-z0-9]+)?$/', $id ) ) {
			throw new \InvalidArgumentException( 'Score version ids look like v1.0.' );
		}
		if ( array_keys( self::COMPONENTS ) !== array_keys( $weights ) ) {
			throw new \InvalidArgumentException( 'Weights must define every component, in order.' );
		}
		foreach ( $weights as $weight ) {
			if ( ! is_numeric( $weight ) || $weight < 0 ) {
				throw new \InvalidArgumentException( 'Weights must be non-negative numbers.' );
			}
		}
		if ( abs( array_sum( $weights ) - 100 ) > 0.0001 ) {
			throw new \InvalidArgumentException( 'Weights must sum to 100.' );
		}
		foreach ( self::REQUIRED_PARAMS as $param ) {
			if ( ! isset( $params[ $param ] ) || ! is_numeric( $params[ $param ] ) || $params[ $param ] <= 0 ) {
				throw new \InvalidArgumentException( sprintf( 'Parameter %s must be a positive number.', $param ) );
			}
		}
	}

	/**
	 * Parameter value.
	 *
	 * @param string $key Parameter.
	 */
	public function param( string $key ): float {
		return (float) $this->params[ $key ];
	}

	/**
	 * Whether client reviews count (optional param reviews_scored, default yes).
	 */
	public function reviews_scored(): bool {
		return (float) ( $this->params['reviews_scored'] ?? 1 ) > 0;
	}

	/**
	 * Public description.
	 *
	 * @return array{id: string, input: string, weights: array<int, array{key: string, label: string, weight: float}>, params: array<string, float>}
	 */
	public function to_array(): array {
		$weights = array();
		foreach ( $this->weights as $key => $weight ) {
			$weights[] = array(
				'key'    => $key,
				'label'  => self::COMPONENTS[ $key ],
				'weight' => (float) $weight,
			);
		}
		return array(
			'id'      => $this->id,
			'input'   => $this->input,
			'weights' => $weights,
			'params'  => array_map( 'floatval', $this->params ),
		);
	}
}
