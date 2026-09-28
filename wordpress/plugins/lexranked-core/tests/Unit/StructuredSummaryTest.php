<?php
/**
 * Etap H: structured, AI-readable summaries.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Content\StructuredSummary;
use PHPUnit\Framework\TestCase;

final class StructuredSummaryTest extends TestCase {

	private static function fact( string $attribute, mixed $value, string $status = 'verified', string $source = 'State Bar' ): array {
		return array(
			'attribute'  => $attribute,
			'value'      => $value,
			'status'     => $status,
			'source'     => array( 'name' => $source ),
			'observedAt' => '2026-09-20T10:00:00Z',
			'verifiedAt' => 'verified' === $status ? '2026-09-27T10:00:00Z' : null,
		);
	}

	private static function lawyer( array $facts, array $extra = array() ): array {
		return array_replace(
			array(
				'type'          => 'lawyer',
				'name'          => 'John Smith',
				'title'         => 'Partner',
				'firm'          => array( 'name' => 'Smith Law Group' ),
				'location'      => array(
					'city'  => 'Miami',
					'state' => 'Florida',
				),
				'practiceAreas' => array( array( 'name' => 'Personal Injury' ) ),
				'ranking'       => array(
					'score'        => 93.7,
					'scoreVersion' => 'v1.1',
					'calculatedAt' => '2026-09-27T12:00:00Z',
				),
				'verification'  => array(
					'status'     => 'verified',
					'verifiedAt' => '2026-09-27T10:00:00Z',
				),
				'commercial'    => array( 'status' => 'premium' ),
				'facts'         => $facts,
			),
			$extra
		);
	}

	public function testBuildsTheBriefsSummaryFromData(): void {
		$summary = StructuredSummary::for_detail(
			self::lawyer(
				array(
					self::fact( 'rating', 4.8, 'unverified', 'Google Reviews' ),
					self::fact( 'review_count', 387, 'unverified', 'Google Reviews' ),
					self::fact( 'years_experience', 18 ),
					self::fact( 'bar_status', 'active' ),
					self::fact( 'bar_state', 'FL' ),
					self::fact( 'practice_areas', array( 'personal-injury' ) ),
					self::fact( 'case_types', array( 'car-accidents', 'wrongful-death' ) ),
				)
			)
		);
		$text    = $summary['text'];
		$this->assertStringStartsWith( 'John Smith is a Partner at Smith Law Group in Miami, Florida, focused on personal injury law (car accidents and wrongful death).', $text );
		$this->assertStringContainsString( 'LexRank score: 93.70/100 (methodology v1.1).', $text );
		$this->assertStringContainsString( 'Client rating: 4.8/5 from 387 reviews (Google Reviews).', $text );
		$this->assertStringContainsString( 'Bar status: active (FL), verified September 27, 2026.', $text );
		$this->assertStringContainsString( 'Verified practice areas: Personal Injury.', $text );
		$this->assertStringContainsString( 'Verified case types: Car Accidents and Wrongful Death.', $text );
		$this->assertStringContainsString( 'Data verified: September 27, 2026.', $text );
		$this->assertStringNotContainsString( 'premium', strtolower( $text ), 'Commercial status is never read' );

		$by = array_column( $summary['facts'], null, 'key' );
		$this->assertSame( 'sourced', $by['rating']['status'] );
		$this->assertSame( 'Google Reviews', $by['rating']['source'] );
		$this->assertSame( 'verified', $by['bar_status']['status'] );
		$this->assertSame( '2026-09-27T12:00:00Z', $summary['asOf'] );
	}

	public function testUnverifiedMissingAndConflictingFactsAreWordedHonestly(): void {
		$summary = StructuredSummary::for_detail(
			self::lawyer(
				array(
					self::fact( 'bar_status', 'active', 'unverified' ),
					self::fact( 'practice_areas', array( 'personal-injury' ), 'unverified' ),
					self::fact( 'rating', 4.9, 'conflict' ),
				),
				array(
					'verification' => array(
						'status'     => 'pending',
						'verifiedAt' => null,
					),
				)
			)
		);
		$text    = $summary['text'];
		$this->assertStringContainsString( 'Bar status on record: active (not yet verified).', $text );
		$this->assertStringContainsString( 'Practice areas on record: Personal Injury.', $text );
		$this->assertStringNotContainsString( 'Client rating', $text, 'A conflicting fact is not stated' );
		$this->assertStringNotContainsString( 'years in practice', $text, 'A missing fact is not guessed' );
		$this->assertStringNotContainsString( 'Data verified', $text );
	}

	public function testFirms(): void {
		$summary = StructuredSummary::for_detail(
			array(
				'type'          => 'law_firm',
				'name'          => 'Smith Law Group',
				'location'      => array(
					'city'  => 'Miami',
					'state' => 'Florida',
				),
				'practiceAreas' => array( array( 'name' => 'Personal Injury' ) ),
				'ranking'       => array( 'score' => null ),
				'verification'  => array( 'status' => 'unverified' ),
				'lawyers'       => array( array(), array() ),
				'facts'         => array( self::fact( 'years_experience', 30 ) ),
			)
		);
		$this->assertStringStartsWith( 'Smith Law Group is a law firm in Miami, Florida, focused on personal injury law.', $summary['text'] );
		$this->assertStringContainsString( '2 lawyers listed on LexRanked.', $summary['text'] );
		$this->assertStringNotContainsString( 'LexRank score', $summary['text'] );
		$this->assertStringNotContainsString( 'years in practice', $summary['text'], 'Experience is a lawyer attribute' );
	}
}
