<?php
/**
 * Etap D: methodology v1.1 on facts, why-ranked-here, change explanations.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Ranking\InputBuilder;
use LexRanked\Core\Ranking\RankingExplainer;
use LexRanked\Core\Ranking\ScoreVersion;
use LexRanked\Core\Ranking\ScoreVersions;
use PHPUnit\Framework\TestCase;

final class ExplanationTest extends TestCase {

	public function testV11ReadsTheFactLayer(): void {
		$versions = new ScoreVersions();
		$this->assertSame( 'v1.1', ScoreVersions::DEFAULT_VERSION );
		$this->assertSame( ScoreVersion::INPUT_PROFILE, $versions->get( 'v1.0' )->input, 'Historical snapshots keep v1.0' );
		$this->assertSame( ScoreVersion::INPUT_FACTS, $versions->get( 'v1.1' )->input );
		$this->assertSame( $versions->get( 'v1.0' )->weights, $versions->get( 'v1.1' )->weights, 'Only the input source changes' );
		$this->assertSame( 'facts', $versions->get( 'v1.1' )->to_array()['input'] );
		$this->expectException( \InvalidArgumentException::class );
		new ScoreVersion( 'v2.0', $versions->get( 'v1.0' )->weights, $versions->get( 'v1.0' )->params, 'ai' );
	}

	public function testFactFieldsDropConflictsAndMissingValues(): void {
		$fields = InputBuilder::fact_fields(
			array(
				'rating'           => array(
					'value'  => 4.8,
					'status' => 'unverified',
				),
				'review_count'     => array(
					'value'  => 387,
					'status' => 'conflict',
				),
				'years_experience' => array(
					'value'  => 18,
					'status' => 'verified',
				),
				'awards'           => array(
					'value'  => array( array( 'name' => 'X' ) ),
					'status' => 'verified',
				),
			)
		);
		$this->assertSame( 4.8, $fields['rating'] );
		$this->assertNull( $fields['review_count'], 'Conflicting sources count as missing' );
		$this->assertSame( 18, $fields['years_experience'] );
		$this->assertNull( $fields['bar_status'], 'No evidence, no value' );
		$this->assertCount( 1, $fields['awards'] );
		$this->assertSame( array(), $fields['education'] );
	}

	private static function row( int $id, int $position, array $points, array $inputs = array(), string $version = 'v1.1' ): array {
		$weights    = array(
			'reputation'      => 30,
			'review_strength' => 20,
			'credentials'     => 10,
		);
		$components = array();
		foreach ( $weights as $key => $weight ) {
			$components[] = array(
				'key'     => $key,
				'label'   => ScoreVersion::COMPONENTS[ $key ],
				'weight'  => $weight,
				'points'  => $points[ $key ] ?? 0.0,
				'missing' => 'credentials' === $key && empty( $points[ $key ] ) ? array( 'awards' ) : array(),
			);
		}
		return array(
			'entity_id'     => $id,
			'position'      => $position,
			'score'         => array_sum( $points ),
			'score_version' => $version,
			'components'    => $components,
			'inputs'        => $inputs,
		);
	}

	public function testWhyRankedHereComesFromComponents(): void {
		$rows = array(
			self::row(
				1,
				1,
				array(
					'reputation'      => 28,
					'review_strength' => 18,
					'credentials'     => 9,
				)
			),
			self::row(
				2,
				2,
				array(
					'reputation'      => 20,
					'review_strength' => 19,
					'credentials'     => 0,
				)
			),
			self::row(
				3,
				3,
				array(
					'reputation'      => 10,
					'review_strength' => 10,
					'credentials'     => 5,
				)
			),
		);
		$why  = RankingExplainer::why( $rows, array( 1 => 'Avery' ) );
		// Edge relative to the component size: 9/10 vs average 4.7 beats 28/30 vs 19.3.
		$this->assertSame( array( 'credentials', 'reputation' ), array_column( $why[1]['strengths'], 'key' ) );
		$this->assertNull( $why[1]['behind'] );
		$this->assertSame( 'credentials', $why[2]['gaps'][0]['key'], 'The weakest share of its maximum' );
		$this->assertSame( 16.0, $why[2]['behind']['scoreGap'] );
		$this->assertSame( 'credentials', $why[2]['behind']['components'][0]['key'], 'The largest difference to #1' );
		$this->assertSame( 'Avery', $why[2]['behind']['name'] );
		$this->assertSame( array( 'awards' ), $why[2]['missing'] );
		$this->assertStringStartsWith( 'Ranks #2 with 39.00', $why[2]['summary'] );
		$this->assertStringContainsString( 'mostly professional credentials', $why[2]['summary'] );
		$this->assertArrayNotHasKey( 'edge', $why[2]['strengths'][0] ?? array() );
		$this->assertSame( $why, RankingExplainer::why( $rows, array( 1 => 'Avery' ) ), 'Deterministic' );
	}

	public function testChangeExplainedFromSnapshotDiffs(): void {
		$prev  = array(
			1 => self::row(
				1,
				2,
				array(
					'reputation'      => 20,
					'review_strength' => 15,
				),
				array( 'review_count' => 150 ),
				'v1.0'
			),
			2 => self::row(
				2,
				1,
				array(
					'reputation'      => 22,
					'review_strength' => 16,
				),
				array( 'review_count' => 200 ),
				'v1.0'
			),
			3 => self::row( 3, 3, array( 'reputation' => 5 ) ),
		);
		$now   = array(
			1 => self::row(
				1,
				1,
				array(
					'reputation'      => 24,
					'review_strength' => 18,
				),
				array( 'review_count' => 212 )
			),
			2 => self::row(
				2,
				2,
				array(
					'reputation'      => 22,
					'review_strength' => 16,
				),
				array( 'review_count' => 200 )
			),
		);
		$names = array(
			1 => 'Avery',
			2 => 'Blake',
			3 => 'Casey',
		);
		$up    = RankingExplainer::change( $now[1], $prev[1], $now, $prev, $names );
		$this->assertSame( 2, $up['previousPosition'] );
		$this->assertSame( 7.0, $up['scoreDelta'] );
		$types = array_column( $up['reasons'], 'type' );
		$this->assertSame( 'methodology', $types[0] );
		$this->assertSame( 'reputation', $up['reasons'][1]['component'], 'Largest component change first' );
		$this->assertContains( 'Data changed: review count 150 → 212.', array_column( $up['reasons'], 'text' ) );
		$this->assertContains( 'Blake dropped below.', array_column( $up['reasons'], 'text' ) );

		$down = RankingExplainer::change( $now[2], $prev[2], $now, $prev, $names );
		$this->assertContains( 'Avery moved past (their score +7.00).', array_column( $down['reasons'], 'text' ), 'A competitor improving is a reason too' );

		$this->assertSame( 'entered', RankingExplainer::change( $now[1], null, $now, $prev, $names )['reasons'][0]['type'] );
		$same = self::row( 9, 1, array( 'reputation' => 5 ) );
		$this->assertNull( RankingExplainer::change( $same, $same, array( 9 => $same ), array( 9 => $same ) ), 'Nothing changed, nothing to explain' );
	}

	public function testEntriesThatLeftAreNamed(): void {
		$prev   = array(
			1 => self::row( 1, 2, array( 'reputation' => 10 ) ),
			5 => self::row( 5, 1, array( 'reputation' => 20 ) ),
		);
		$now    = array( 1 => self::row( 1, 1, array( 'reputation' => 10 ) ) );
		$change = RankingExplainer::change( $now[1], $prev[1], $now, $prev, array( 5 => 'Emery' ) );
		$this->assertContains( 'Emery is no longer in the ranking.', array_column( $change['reasons'], 'text' ) );
	}
}
