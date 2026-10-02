<?php
/**
 * Etap C: Data Quality Score.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Attribute\Attributes;
use LexRanked\Core\Quality\DataQuality;
use LexRanked\Core\Quality\QualityService;
use LexRanked\Core\Verification\Freshness;
use PHPUnit\Framework\TestCase;

final class DataQualityTest extends TestCase {

	private const NOW = '2026-09-27T12:00:00Z';

	private static function fact( string $status = 'verified', int $tier = 1, string $at = '2026-09-25 10:00:00' ): array {
		return array(
			'value'       => 'x',
			'status'      => $status,
			'source_tier' => $tier,
			'observed_at' => $at,
			'verified_at' => 'verified' === $status ? $at : null,
		);
	}

	private static function dq( array $facts, array $checks = array(), array $profile = array() ): array {
		return DataQuality::evaluate( 'lawyer', $facts, $profile, $checks, array( 'identity', 'license', 'bar_status' ), new Freshness(), new \DateTimeImmutable( self::NOW ) );
	}

	public function testWeightsArePublishedAndSumTo100(): void {
		$this->assertSame( 100, array_sum( array_column( DataQuality::model()['dimensions'], 'weight' ) ) );
		$this->assertSame( DataQuality::VERSION, DataQuality::model()['version'] );
		foreach ( DataQuality::EXPECTED as $type => $expected ) {
			foreach ( array_keys( $expected ) as $key ) {
				$this->assertContains( $type, Attributes::fact( $key )->entity_types, "$key is not a $type attribute" );
			}
		}
	}

	public function testNothingOnRecordScoresZero(): void {
		$r = self::dq( array() );
		$this->assertSame( 0.0, $r['score'] );
		$this->assertCount( 14, $r['missing'] );
	}

	public function testFullyDocumentedFreshVerifiedProfileScores100(): void {
		$facts = array_map( static fn(): array => self::fact(), DataQuality::EXPECTED['lawyer'] );
		// Review data has a 7-day window: checked 2 days ago is fresh.
		$r = self::dq(
			$facts,
			array(
				'identity'   => 'verified',
				'license'    => 'verified',
				'bar_status' => 'verified',
			)
		);
		$this->assertSame( 100.0, $r['score'] );
		$this->assertSame( array(), $r['missing'] );
	}

	public function testDimensionsReactToTheRightProblems(): void {
		$facts = array(
			'bar_status'   => self::fact( 'verified', 1 ),
			'rating'       => self::fact( 'conflict', 4, '2026-09-01 10:00:00' ),
			'review_count' => self::fact( 'unverified', 4, '2026-09-26 10:00:00' ),
			'website'      => self::fact( 'unverified', 2 ),
		);
		$r     = self::dq( $facts, array( 'bar_status' => 'verified' ), array( 'years_experience', 'city' ) );
		$d     = array_column( $r['dimensions'], 'score', 'key' );
		$this->assertSame( array( 'rating' ), $r['stale'], 'Review data older than 7 days is stale' );
		$this->assertSame( 75.0, $d['freshness'] );
		$this->assertSame( array( 'rating' ), $r['conflicts'] );
		$this->assertSame( 75.0, $d['consistency'] );
		$this->assertSame( 65.0, $d['source'], '(100 + 40 + 40 + 80) / 4' );
		$this->assertEqualsWithDelta( 100 * ( 0.5 * 0.25 + 0.5 / 3 ), $d['verification'], 0.05 );
		$this->assertSame( array( 'city', 'years_experience' ), $r['unsourced'], 'Filled in on the profile but without evidence' );
		$this->assertGreaterThan( 0, $r['score'] );
		$this->assertLessThan( 60, $r['score'] );
	}

	public function testDeterministic(): void {
		$facts = array( 'bar_status' => self::fact() );
		$this->assertSame( self::dq( $facts ), self::dq( $facts ) );
	}

	public function testProfileKeys(): void {
		$keys = QualityService::profile_keys(
			array(
				'title'          => 'Jane Doe',
				'fields'         => array(
					'rating'  => 4.5,
					'awards'  => array(),
					'website' => null,
				),
				'locations'      => array(
					array( 'state_code' => 'FL' ),
					array( 'state_code' => null ),
				),
				'practice_areas' => array( array( 'slug' => 'personal-injury' ) ),
			)
		);
		$this->assertSame( array( 'rating', 'name', 'state', 'city', 'practice_areas' ), $keys );
	}

	/**
	 * Data Quality describes documentation, not merit: the ranking engine
	 * cannot read it (its own "data quality" component is separately disclosed).
	 */
	public function testRankingNeverReadsTheDataQualityScore(): void {
		foreach ( glob( dirname( __DIR__, 2 ) . '/src/Ranking/*.php' ) as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
				if ( is_array( $token ) && ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					$this->assertDoesNotMatchRegularExpression( '/DataQuality|QualityService|quality_score|quality_json/', $token[1], basename( $file ) );
				}
			}
		}
	}
}
