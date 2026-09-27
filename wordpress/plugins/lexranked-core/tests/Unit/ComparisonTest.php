<?php
/**
 * Etap E: comparison engine.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Compare\ComparisonEngine;
use PHPUnit\Framework\TestCase;

final class ComparisonTest extends TestCase {

	private static function fact( string $attribute, mixed $value, string $status = 'verified', bool $stale = false ): array {
		return array(
			'attribute'  => $attribute,
			'value'      => $value,
			'status'     => $status,
			'source'     => array(
				'name'      => 'Florida Bar',
				'publisher' => 'The Florida Bar',
				'url'       => 'https://example.com/bar',
				'tierLabel' => 'Official',
			),
			'observedAt' => '2026-09-20T10:00:00Z',
			'verifiedAt' => 'verified' === $status ? '2026-09-25T10:00:00Z' : null,
			'freshness'  => array( 'isStale' => $stale ),
		);
	}

	private static function lawyer( int $id, string $name, array $facts, array $extra = array() ): array {
		return array_replace(
			array(
				'id'            => $id,
				'entityId'      => $id + 1000,
				'type'          => 'lawyer',
				'name'          => $name,
				'path'          => '/lawyers/' . strtolower( str_replace( ' ', '-', $name ) ) . '/',
				'location'      => array( 'city' => 'Miami' ),
				'firm'          => null,
				'practiceAreas' => array(
					array(
						'slug' => 'personal-injury',
						'name' => 'Personal Injury',
					),
					array(
						'slug' => 'car-accidents',
						'name' => 'Car Accidents',
					),
				),
				'ranking'       => array(
					'score'        => 80.0,
					'scoreVersion' => 'v1.1',
					'calculatedAt' => '2026-09-27T10:00:00Z',
				),
				'verification'  => array(
					'status'     => 'verified',
					'verifiedAt' => '2026-09-25T10:00:00Z',
					'checks'     => array(
						'identity' => 'verified',
						'license'  => 'verified',
					),
				),
				'dataQuality'   => array(
					'score'        => 91.5,
					'calculatedAt' => '2026-09-27T10:00:00Z',
				),
				'commercial'    => array(
					'status'  => 'premium',
					'premium' => true,
				),
				'rankings'      => array(),
				'facts'         => $facts,
			),
			$extra
		);
	}

	private static function pair(): array {
		return array(
			self::lawyer(
				1,
				'John Smith',
				array(
					self::fact( 'city', 'Miami' ),
					self::fact( 'state', 'FL' ),
					self::fact( 'rating', 4.8, 'unverified' ),
					self::fact( 'review_count', 154, 'unverified' ),
					self::fact( 'years_experience', 18 ),
					self::fact( 'practice_areas', array( 'personal-injury', 'car-accidents' ) ),
					self::fact( 'bar_status', 'active' ),
				),
				array(
					'ranking'  => array(
						'score'        => 84.03,
						'scoreVersion' => 'v1.1',
						'calculatedAt' => '2026-09-27T10:00:00Z',
					),
					'rankings' => array(
						array(
							'id'       => 50,
							'title'    => 'Best Personal Injury Lawyers in Miami',
							'path'     => '/rankings/florida/miami/personal-injury/',
							'position' => 1,
							'score'    => 84.03,
						),
						array(
							'id'       => 51,
							'title'    => 'Only John',
							'path'     => '/rankings/x/',
							'position' => 2,
							'score'    => 84.03,
						),
					),
				)
			),
			self::lawyer(
				2,
				'Jane Doe',
				array(
					self::fact( 'city', 'Miami' ),
					self::fact( 'state', 'FL' ),
					self::fact( 'rating', 4.9, 'unverified' ),
					self::fact( 'review_count', 900, 'unverified' ),
					self::fact( 'years_experience', 12 ),
					self::fact( 'practice_areas', array( 'personal-injury' ) ),
				),
				array(
					'practiceAreas' => array(
						array(
							'slug' => 'personal-injury',
							'name' => 'Personal Injury',
						),
					),
					'rankings'      => array(
						array(
							'id'       => 50,
							'title'    => 'Best Personal Injury Lawyers in Miami',
							'path'     => '/rankings/florida/miami/personal-injury/',
							'position' => 3,
							'score'    => 71.2,
						),
					),
				)
			),
		);
	}

	private static function row( array $result, string $key ): array {
		foreach ( $result['rows'] as $row ) {
			if ( $key === $row['key'] ) {
				return $row;
			}
		}
		self::fail( 'No row ' . $key );
	}

	public function testComparesStoredValuesAndMarksTheHighest(): void {
		$result = ComparisonEngine::compare( 'lawyer', self::pair() );

		$this->assertSame( 'cmp-1.0', $result['version'] );
		$this->assertSame( array( 1, 2 ), array_column( $result['entities'], 'id' ) );
		$this->assertSame( array( 1001, 1002 ), array_column( $result['entities'], 'entityId' ) );
		$this->assertSame( array( 1 ), self::row( $result, 'lexrank_score' )['highest'] );
		$this->assertSame( array( 2 ), self::row( $result, 'review_count' )['highest'] );
		$this->assertSame( array( 2 ), self::row( $result, 'rating' )['highest'] );
		$this->assertSame( array( 1 ), self::row( $result, 'years_experience' )['highest'] );
		$this->assertSame( array(), self::row( $result, 'bar_status' )['highest'], 'Text rows never have a winner' );

		$cell = self::row( $result, 'years_experience' )['cells'][0];
		$this->assertSame( '18 years', $cell['display'] );
		$this->assertSame( 'verified', $cell['status'] );
		$this->assertSame( 'Florida Bar', $cell['source']['name'] );
		$this->assertSame( '2026-09-25T10:00:00Z', $cell['checkedAt'] );
		$this->assertSame( 'Miami, FL', self::row( $result, 'location' )['cells'][1]['display'] );
		$this->assertSame( 'Personal Injury, Car Accidents', self::row( $result, 'practice_areas' )['cells'][0]['display'] );
		$this->assertSame( array( 'personal-injury' ), self::row( $result, 'practice_areas' )['shared'] );
	}

	public function testMissingValuesAreShownNotEstimated(): void {
		$result = ComparisonEngine::compare( 'lawyer', self::pair() );
		$bar    = self::row( $result, 'bar_status' );
		$this->assertSame( 'missing', $bar['cells'][1]['status'] );
		$this->assertNull( $bar['cells'][1]['value'] );
		$this->assertNull( $bar['cells'][1]['display'] );
		$this->assertContains( 'Not on record for Jane Doe: law firm, bar status, bar admission state, education, awards and recognition, languages.', $result['summary'] );
	}

	public function testSummaryStatesDifferencesNotVerdicts(): void {
		$summary = ComparisonEngine::compare( 'lawyer', self::pair() )['summary'];
		$this->assertContains( 'John Smith has the higher LexRank score (84.03 vs 80.00).', $summary );
		$this->assertContains( 'Jane Doe has more reviews on record (900 vs 154).', $summary );
		$this->assertContains( 'John Smith has more years of experience (18 vs 12).', $summary );
		$this->assertContains( 'Both practice Personal Injury.', $summary );
		$this->assertContains( 'In Best Personal Injury Lawyers in Miami, John Smith is #1 and Jane Doe is #3.', $summary );
		foreach ( $summary as $line ) {
			$this->assertDoesNotMatchRegularExpression( '/\b(better|best choice|recommend|should hire|winner)\b/i', $line );
		}
	}

	public function testSharedRankingsNeedEveryEntity(): void {
		$shared = ComparisonEngine::compare( 'lawyer', self::pair() )['sharedRankings'];
		$this->assertCount( 1, $shared );
		$this->assertSame( 50, $shared[0]['id'] );
		$this->assertSame( array( 1, 3 ), array_column( $shared[0]['positions'], 'position' ) );
	}

	public function testConflictsAndTiesHaveNoHighest(): void {
		$pair                = self::pair();
		$pair[1]['facts'][3] = self::fact( 'review_count', 900, 'conflict' );
		$pair[1]['facts'][2] = self::fact( 'rating', 4.8, 'unverified' );
		$result              = ComparisonEngine::compare( 'lawyer', $pair );
		$reviews             = self::row( $result, 'review_count' );
		$this->assertSame( array(), $reviews['highest'] );
		$this->assertStringContainsString( 'Sources disagree', (string) $reviews['note'] );
		$this->assertSame( array(), self::row( $result, 'rating' )['highest'], 'A tie has no highest' );
		$this->assertContains( 'Sources disagree on review count for Jane Doe; not compared.', $result['summary'] );
	}

	public function testScoresFromDifferentMethodologiesAreNotCompared(): void {
		$pair                               = self::pair();
		$pair[1]['ranking']['scoreVersion'] = 'v1.0';
		$row                                = self::row( ComparisonEngine::compare( 'lawyer', $pair ), 'lexrank_score' );
		$this->assertSame( array(), $row['highest'] );
		$this->assertStringContainsString( 'different methodology versions', (string) $row['note'] );
	}

	public function testFewReviewsAreFlagged(): void {
		$pair                = self::pair();
		$pair[0]['facts'][3] = self::fact( 'review_count', 3, 'unverified' );
		$cell                = self::row( ComparisonEngine::compare( 'lawyer', $pair ), 'rating' )['cells'][0];
		$this->assertSame( 'Based on 3 reviews.', $cell['note'] );
	}

	public function testCommercialStatusIsNeverADimension(): void {
		$json = (string) json_encode( ComparisonEngine::compare( 'lawyer', self::pair() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		$this->assertStringNotContainsString( 'premium', strtolower( $json ) );
		$this->assertStringNotContainsString( 'commercial', strtolower( $json ) );
		$this->assertStringNotContainsString( 'claimed', strtolower( $json ) );

		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Compare/ComparisonEngine.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$this->assertStringNotContainsString( "['commercial']", $source );
		$this->assertStringNotContainsString( 'premiumContent', $source );
	}

	public function testThreeWayComparisonAndLimits(): void {
		$pair   = self::pair();
		$third  = self::lawyer( 3, 'Alex Roe', array( self::fact( 'review_count', 20, 'unverified' ) ) );
		$result = ComparisonEngine::compare( 'lawyer', array_merge( $pair, array( $third ) ) );
		$this->assertContains( 'Jane Doe has the most reviews on record (900; others 154, 20).', $result['summary'] );
		$this->assertSame( array(), $result['sharedRankings'], 'Alex is in no shared ranking' );

		$this->expectException( \InvalidArgumentException::class );
		ComparisonEngine::compare( 'lawyer', array( $pair[0] ) );
	}

	public function testFirmsCompareTheirOwnRows(): void {
		$a      = self::lawyer(
			1,
			'Smith Law',
			array( self::fact( 'rating', 4.5 ) ),
			array(
				'type'    => 'law_firm',
				'lawyers' => array( array(), array() ),
			)
		);
		$b      = self::lawyer(
			2,
			'Doe Legal',
			array( self::fact( 'rating', 4.7 ) ),
			array(
				'type'    => 'law_firm',
				'lawyers' => array(),
			)
		);
		$result = ComparisonEngine::compare( 'law_firm', array( $a, $b ) );
		$keys   = array_column( $result['rows'], 'key' );
		$this->assertContains( 'lawyers', $keys );
		$this->assertNotContains( 'years_experience', $keys );
		$this->assertNotContains( 'bar_status', $keys );
		$this->assertSame( '2 lawyers', self::row( $result, 'lawyers' )['cells'][0]['display'] );
		$this->assertSame( array(), self::row( $result, 'lawyers' )['highest'], 'Firm size is not a merit' );

		$this->expectException( \InvalidArgumentException::class );
		ComparisonEngine::compare( 'location', array( $a, $b ) );
	}
}
