<?php
/**
 * Score version registry.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

/**
 * Built-in versions live in code (reviewed and versioned in git, so they can
 * never change silently). Additional versions may be registered through
 * configuration, but a built-in id can never be redefined.
 */
final class ScoreVersions {

	public const DEFAULT_VERSION = 'v1.0';

	/**
	 * Built-in definitions (docs/ranking-methodology.md).
	 *
	 * @return array<string, array{weights: array<string, float>, params: array<string, float>}>
	 */
	public static function builtin(): array {
		return array(
			'v1.0' => array(
				'weights' => array(
					'reputation'         => 30,
					'review_strength'    => 20,
					'experience'         => 15,
					'practice_relevance' => 15,
					'credentials'        => 10,
					'local_relevance'    => 5,
					'data_quality'       => 5,
				),
				'params'  => array(
					// Bayesian review average: (C*m + n*r) / (C + n).
					'review_prior_mean'    => 4.0,
					'review_prior_weight'  => 25,
					// Adjusted ratings at or below this floor score 0; 5.0 scores 1.
					'review_floor'         => 3.0,
					// Review volume counts toward reputation on a log scale up to this cap.
					'review_volume_cap'    => 500,
					'awards_cap'           => 5,
					'experience_cap_years' => 25,
				),
			),
		);
	}

	/**
	 * Constructor.
	 *
	 * @param array<string, array{weights: array<string, float>, params: array<string, float>}> $extra Configured additional versions.
	 */
	public function __construct( private readonly array $extra = array() ) {
	}

	/**
	 * Get a version.
	 *
	 * @param string $id Version id.
	 * @throws \InvalidArgumentException When unknown or invalid.
	 */
	public function get( string $id ): ScoreVersion {
		$builtin = self::builtin();
		$def     = $builtin[ $id ] ?? $this->extra[ $id ] ?? null;
		if ( null === $def ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown score version %s.', $id ) );
		}
		return new ScoreVersion( $id, $def['weights'], $def['params'] );
	}

	/**
	 * All valid versions (invalid configured versions are skipped).
	 *
	 * @return array<string, ScoreVersion>
	 */
	public function all(): array {
		$out = array();
		foreach ( array_keys( self::builtin() + $this->extra ) as $id ) {
			try {
				$out[ $id ] = $this->get( (string) $id );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}
		return $out;
	}
}
