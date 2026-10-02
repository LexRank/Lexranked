<?php
/**
 * Etap I: market statistics.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Market\MarketStatistics;
use PHPUnit\Framework\TestCase;

final class MarketStatisticsTest extends TestCase {

	private static function fact( mixed $value, string $status = 'unverified' ): array {
		return array(
			'value'  => $value,
			'status' => $status,
		);
	}

	private static function lawyer( ?float $rating, ?int $reviews, bool $verified = false, array $areas = array( 'personal-injury' ), string $rating_status = 'unverified' ): array {
		$facts = array( 'practice_areas' => self::fact( $areas ) );
		if ( null !== $rating ) {
			$facts['rating'] = self::fact( $rating, $rating_status );
		}
		if ( null !== $reviews ) {
			$facts['review_count'] = self::fact( $reviews );
		}
		return array(
			'type'        => 'lawyer',
			'verified'    => $verified,
			'verified_at' => $verified ? '2026-09-27T10:00:00Z' : null,
			'is_demo'     => false,
			'facts'       => $facts,
		);
	}

	private static function firm( float $rating ): array {
		return array(
			'type'        => 'law_firm',
			'verified'    => false,
			'verified_at' => null,
			'is_demo'     => false,
			'facts'       => array( 'rating' => self::fact( $rating ) ),
		);
	}

	private static function stats( array $entities ): array {
		return MarketStatistics::compute(
			$entities,
			array(
				'personal-injury' => 'Personal Injury',
				'family-law'      => 'Family Law',
			),
			'2026-09-28T00:00:00Z'
		);
	}

	public function testCountsAndFiguresComeFromStoredData(): void {
		$stats = self::stats(
			array(
				self::lawyer( 4.8, 387, true ),
				self::lawyer( 4.6, 120, true ),
				self::lawyer( 4.2, 40, false, array( 'personal-injury', 'family-law' ) ),
				self::lawyer( 5.0, 3, false, array( 'family-law' ) ),
				self::firm( 1.0 ),
			)
		);
		$this->assertSame( 'mkt-1.0', $stats['version'] );
		$this->assertSame( 4, $stats['lawyers'] );
		$this->assertSame( 1, $stats['firms'] );
		$this->assertSame( 2, $stats['verifiedLawyers'] );
		$this->assertSame( 4.65, $stats['averageRating']['value'], 'Lawyers only; a firm rating is not mixed in' );
		$this->assertSame( 4, $stats['averageRating']['sample'] );
		$this->assertSame( 80, $stats['medianReviewCount']['value'] );
		$this->assertSame( 'personal-injury', $stats['mostCommonPractice']['slug'] );
		$this->assertSame( 3, $stats['mostCommonPractice']['count'] );
		$this->assertSame( 'Family Law', $stats['practiceAreas'][1]['name'] );
		$this->assertSame( '2026-09-27T10:00:00Z', $stats['dataVerifiedAt'] );
		$this->assertSame( '2026-09-28T00:00:00Z', $stats['calculatedAt'] );
	}

	public function testUnsourcedOrConflictingFactsAreNotCounted(): void {
		$stats = self::stats(
			array(
				self::lawyer( 4.8, 100 ),
				self::lawyer( 1.0, 100, false, array(), 'conflict' ),
				self::lawyer( 4.4, 100 ),
				self::lawyer( 4.6, 100 ),
			)
		);
		$this->assertSame( 3, $stats['averageRating']['sample'] );
		$this->assertSame( 4.6, $stats['averageRating']['value'] );
	}

	public function testSmallSamplesAreWithheldNotEstimated(): void {
		$stats = self::stats( array( self::lawyer( 4.9, 10 ), self::lawyer( 4.1, null ) ) );
		$this->assertNull( $stats['averageRating'] );
		$this->assertNull( $stats['medianReviewCount'] );
		$this->assertCount( 2, $stats['notes'] );
		$this->assertStringContainsString( 'withheld', $stats['notes'][0] );
		$this->assertNull( $stats['dataVerifiedAt'], 'No verified profile, no verification date' );
	}

	public function testSummaryStatesOnlyComputedNumbers(): void {
		$stats   = self::stats( array( self::lawyer( 4.8, 387, true ), self::lawyer( 4.6, 120 ), self::lawyer( 4.2, 40 ) ) );
		$summary = MarketStatistics::summary( $stats, 'in Miami, Florida', 'Miami, Florida' );
		$this->assertStringStartsWith( 'LexRanked tracks 3 lawyers in Miami, Florida. 1 of the lawyers has verified professional data.', $summary );
		$this->assertStringContainsString( 'The average client rating is 4.5 out of 5 across 3 lawyers with a sourced rating.', $summary );
		$this->assertStringContainsString( 'The median lawyer profile has 120 reviews.', $summary );
		$this->assertStringContainsString( 'The most common practice area in Miami, Florida is Personal Injury (3 lawyers).', $summary );
		$this->assertStringNotContainsString( 'most common', MarketStatistics::summary( $stats, 'x', 'Miami', false ) );

		$even = self::stats( array( self::lawyer( 4.8, 97, true ), self::lawyer( 4.6, 100 ), self::lawyer( 4.2, 90 ), self::lawyer( 4.4, 120 ) ) );
		$this->assertStringContainsString( 'has 98.5 reviews', MarketStatistics::summary( $even, 'x', 'Miami' ), 'The summary states the same number the tiles show' );

		$thin = MarketStatistics::summary( self::stats( array( self::lawyer( 4.8, 10 ) ) ), 'in Miami', 'Miami' );
		$this->assertStringNotContainsString( 'average', $thin, 'A withheld figure is not stated' );
		$this->assertSame( '', MarketStatistics::summary( self::stats( array() ), 'in Nowhere', 'Nowhere' ) );
	}

	public function testMedian(): void {
		$this->assertSame( 2, MarketStatistics::median( array( 3, 1, 2 ) ) );
		$this->assertSame( 2.5, MarketStatistics::median( array( 4, 1, 2, 3 ) ) );
	}
}
