<?php
/**
 * Ranking engine tests (deterministic).
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Ranking\BayesianReviewScorer;
use LexRanked\Core\Ranking\EntityInput;
use LexRanked\Core\Ranking\RankingContext;
use LexRanked\Core\Ranking\RankingEngine;
use LexRanked\Core\Ranking\ScoreCalculator;
use LexRanked\Core\Ranking\ScoreVersion;
use LexRanked\Core\Ranking\ScoreVersions;
use PHPUnit\Framework\TestCase;

final class RankingEngineTest extends TestCase {

	private ScoreVersion $v1;
	private RankingContext $miami_pi;

	protected function setUp(): void {
		$this->v1       = ( new ScoreVersions() )->get( 'v1.0' );
		$this->miami_pi = new RankingContext( 'personal-injury', 'miami', 'florida' );
	}

	private static function lawyer( int $id, array $overrides = array() ): EntityInput {
		return EntityInput::from_array(
			array_merge(
				array(
					'entity_type'         => 'lawyer',
					'entity_id'           => $id,
					'rating'              => 4.8,
					'review_count'        => 150,
					'years_experience'    => 15,
					'awards_count'        => 1,
					'education_count'     => 1,
					'bar_status'          => 'active',
					'practice_areas'      => array( 'personal-injury' ),
					'city'                => 'miami',
					'state'               => 'florida',
					'verification_status' => 'verified',
					'verification_checks' => array(
						'identity'   => 'verified',
						'license'    => 'verified',
						'bar_status' => 'verified',
					),
					'present_fields'      => ScoreCalculator::KEY_FIELDS,
					'sourced_fields'      => array( 'bar_status', 'years_experience', 'rating', 'review_count', 'website' ),
					'sourced_facts'       => 5,
				),
				$overrides
			)
		);
	}

	public function testV1WeightsMatchTheDocumentedMethodology(): void {
		$this->assertSame(
			array(
				'reputation'         => 30,
				'review_strength'    => 20,
				'experience'         => 15,
				'practice_relevance' => 15,
				'credentials'        => 10,
				'local_relevance'    => 5,
				'data_quality'       => 5,
			),
			$this->v1->weights
		);
	}

	public function testScoreIsAnExactFunctionOfInputs(): void {
		$calc   = new ScoreCalculator();
		$result = $calc->calculate( self::lawyer( 1 ), $this->miami_pi, $this->v1 );

		// Golden value, verified by hand — changing the formula must be a deliberate new version:
		// reputation 15.11 (awards 1/5, volume ln151/ln501) + reviews 16.86 (Bayes 4.686)
		// + experience 9.00 + practice 15.00 + credentials 10.00 + local 5.00 + data quality 4.57.
		$this->assertSame( 75.54, $result->total );
		$this->assertSame( array( 15.11, 16.86, 9.0, 15.0, 10.0, 5.0, 4.57 ), array_column( $result->components, 'points' ) );
		$this->assertSame( 'v1.0', $result->version );
		$this->assertEqualsWithDelta( $result->total, array_sum( array_column( $result->components, 'points' ) ), 0.00001 );
		$this->assertSame( array_keys( ScoreVersion::COMPONENTS ), array_column( $result->components, 'key' ) );

		// Same inputs → identical result, every time.
		$this->assertEquals( $result, $calc->calculate( self::lawyer( 1 ), $this->miami_pi, $this->v1 ) );
	}

	public function testV12LeavesReviewsOutOfTheScore(): void {
		$v12    = ( new ScoreVersions() )->get( 'v1.2' );
		$calc   = new ScoreCalculator();
		$result = $calc->calculate( self::lawyer( 1 ), $this->miami_pi, $v12 );

		// reputation 4.00 (1 of 5 awards) + experience 18.00 (15 of 25 years) + practice 20.00
		// + credentials 15.00 + local 5.00 + data quality 8.80 (5 of 5 key facts, 3 sourced, verified).
		$this->assertSame( 70.8, $result->total );
		$this->assertSame( array( 'reputation', 'experience', 'practice_relevance', 'credentials', 'local_relevance', 'data_quality' ), array_column( $result->components, 'key' ), 'Review strength is not a v1.2 component' );
		$this->assertSame( array( 4.0, 18.0, 20.0, 15.0, 5.0, 8.8 ), array_column( $result->components, 'points' ) );
		$this->assertSame( '1 recorded award (counted up to 5).', $result->components[0]['explanation'] );

		// Ratings and review counts change nothing in v1.2.
		$none = self::lawyer(
			1,
			array(
				'rating'         => null,
				'review_count'   => null,
				'present_fields' => array_values( array_diff( ScoreCalculator::KEY_FIELDS, array( 'rating', 'review_count' ) ) ),
			)
		);
		$this->assertSame( $result->total, $calc->calculate( $none, $this->miami_pi, $v12 )->total );
	}

	public function testStoredInputsReproduceTheScore(): void {
		$input  = self::lawyer( 7 );
		$stored = json_decode( (string) json_encode( $input->to_array() ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		$calc   = new ScoreCalculator();
		$this->assertEquals(
			$calc->calculate( $input, $this->miami_pi, $this->v1 ),
			$calc->calculate( EntityInput::from_array( $stored ), $this->miami_pi, $this->v1 )
		);
	}

	public function testMissingDataScoresZeroAndIsReportedNotGuessed(): void {
		$result = ( new ScoreCalculator() )->calculate(
			self::lawyer(
				2,
				array(
					'rating'           => null,
					'review_count'     => null,
					'years_experience' => null,
				)
			),
			$this->miami_pi,
			$this->v1
		);
		$by_key = array_column( $result->components, null, 'key' );
		$this->assertSame( 0.0, $by_key['review_strength']['points'] );
		$this->assertSame( 0.0, $by_key['experience']['points'] );
		$this->assertContains( 'years_experience', $by_key['experience']['missing'] );
		$this->assertContains( 'rating', $by_key['review_strength']['missing'] );
	}

	public function testPaymentCannotBeAnInput(): void {
		$this->assertNotContains( 'commercial_status', EntityInput::FIELDS );
		foreach ( ( new \ReflectionClass( EntityInput::class ) )->getProperties() as $property ) {
			$this->assertDoesNotMatchRegularExpression( '/commercial|paid|sponsor|featured|premium/i', $property->getName() );
		}
		// Extra keys in stored data are ignored, so they cannot leak in either.
		$a = ( new ScoreCalculator() )->calculate( self::lawyer( 3 ), $this->miami_pi, $this->v1 );
		$b = ( new ScoreCalculator() )->calculate( EntityInput::from_array( self::lawyer( 3 )->to_array() + array( 'commercial_status' => 'sponsored' ) ), $this->miami_pi, $this->v1 );
		$this->assertSame( $a->total, $b->total );
	}

	public function testBayesianReviewsRewardVolume(): void {
		$scorer = new BayesianReviewScorer( 4.0, 25, 3.0 );
		$this->assertGreaterThan( $scorer->factor( 5.0, 3 ), $scorer->factor( 4.8, 400 ) );
		$this->assertEqualsWithDelta( ( 25 * 4.0 + 3 * 5.0 ) / 28, $scorer->adjusted_rating( 5.0, 3 ), 1e-9 );
		$this->assertNull( $scorer->adjusted_rating( 4.5, 0 ) );
		$this->assertSame( 0.0, $scorer->factor( null, 10 ) );
		$this->assertSame( 0.0, $scorer->factor( 2.0, 1000 ), 'Below the floor scores zero' );
	}

	public function testContextChangesRelevanceNotIdentity(): void {
		$calc  = new ScoreCalculator();
		$input = self::lawyer( 4, array( 'practice_areas' => array( 'personal-injury', 'family-law' ) ) );

		$here      = array_column( $calc->calculate( $input, $this->miami_pi, $this->v1 )->components, null, 'key' );
		$elsewhere = array_column( $calc->calculate( $input, new RankingContext( 'personal-injury', 'tampa', 'florida' ), $this->v1 )->components, null, 'key' );
		$off_topic = array_column( $calc->calculate( $input, new RankingContext( 'criminal-defense', 'miami', 'florida' ), $this->v1 )->components, null, 'key' );

		$this->assertSame( 5.0, $here['local_relevance']['points'] );
		$this->assertSame( 2.5, $elsewhere['local_relevance']['points'] );
		$this->assertSame( 11.25, $here['practice_relevance']['points'] ); // 0.5 + 0.5/2 = 0.75 × 15.
		$this->assertSame( 0.0, $off_topic['practice_relevance']['points'] );
	}

	public function testEngineOrdersDeterministicallyWithTieBreaks(): void {
		$engine = new RankingEngine();
		$inputs = array(
			self::lawyer( 30 ),
			self::lawyer( 10 ),
			self::lawyer( 20, array( 'sourced_facts' => 9 ) ),
			self::lawyer( 40, array( 'rating' => 3.1 ) ),
		);
		$ranked = $engine->rank( $inputs, $this->miami_pi, $this->v1 );
		// 10/20/30 tie on score: more sourced facts first, then lower ID.
		$this->assertSame( array( 20, 10, 30, 40 ), array_map( static fn( array $r ): int => $r['input']->entity_id, $ranked ) );
		$this->assertSame( array( 1, 2, 3, 4 ), array_column( $ranked, 'position' ) );
		$this->assertEquals( $ranked, $engine->rank( array_reverse( $inputs ), $this->miami_pi, $this->v1 ), 'Input order must not matter' );
	}

	public function testVersionsValidateTheirDefinition(): void {
		$weights = ScoreVersions::builtin()['v1.0']['weights'];
		$params  = ScoreVersions::builtin()['v1.0']['params'];
		$this->expectException( \InvalidArgumentException::class );
		new ScoreVersion( 'v9.9', array( 'reputation' => 100 ) + $weights, $params );
	}

	public function testConfiguredVersionsCannotRedefineBuiltins(): void {
		$weights                    = ScoreVersions::builtin()['v1.0']['weights'];
		$weights['reputation']      = 25;
		$weights['review_strength'] = 25;
		$versions                   = new ScoreVersions(
			array(
				'v1.0' => array(
					'weights' => $weights,
					'params'  => ScoreVersions::builtin()['v1.0']['params'],
				),
				'v1.1' => array(
					'weights' => $weights,
					'params'  => ScoreVersions::builtin()['v1.0']['params'],
				),
				'v1.2' => array(
					'weights' => $weights,
					'params'  => ScoreVersions::builtin()['v1.0']['params'],
				),
				'v1.9' => array(
					'weights' => $weights,
					'params'  => ScoreVersions::builtin()['v1.0']['params'],
				),
			)
		);
		$this->assertSame( 30, $versions->get( 'v1.0' )->weights['reputation'] );
		$this->assertSame( 30, $versions->get( 'v1.1' )->weights['reputation'], 'v1.1 is built in too' );
		$this->assertSame( 'facts', $versions->get( 'v1.1' )->input );
		$this->assertSame( 20, $versions->get( 'v1.2' )->weights['reputation'], 'v1.2 is built in too' );
		$this->assertSame( 25, $versions->get( 'v1.9' )->weights['reputation'] );
		$this->assertSame( 'profile', $versions->get( 'v1.9' )->input, 'Configured versions default to profile input' );
		$this->assertSame( array( 'v1.0', 'v1.1', 'v1.2', 'v1.9' ), array_keys( $versions->all() ) );
	}
}
