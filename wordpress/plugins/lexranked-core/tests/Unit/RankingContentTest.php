<?php
/**
 * Generated ranking text: complete, sourced, built only from facts and the knowledge pack.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Content\RankingContentBuilder;
use LexRanked\Core\Content\RankingContentService;
use PHPUnit\Framework\TestCase;

final class RankingContentTest extends TestCase {

	private static function context( array $over = array() ): array {
		return $over + array(
			'area_slug'   => 'personal-injury',
			'area_name'   => 'Personal Injury',
			'city_slug'   => 'miami',
			'city_name'   => 'Miami',
			'entity_type' => 'lawyer',
		);
	}

	private static function people(): array {
		return array(
			array(
				'years'     => 30,
				'languages' => array( 'English', 'Spanish' ),
				'awards'    => array( 'Board Certified in Civil Trial Law' ),
				'schools'   => array( 'University of Miami School of Law' ),
			),
			array(
				'years'     => 12,
				'languages' => array( 'English' ),
				'awards'    => array( 'Board Certified in Civil Trial Law' ),
				'schools'   => array( 'University of Miami School of Law' ),
			),
			array(
				'years'     => 20,
				'languages' => array( 'English', 'Spanish', 'Portuguese' ),
				'awards'    => array(),
				'schools'   => array( 'Florida State University College of Law' ),
			),
		);
	}

	private static function build( array $context = array(), ?array $people = null ): ?array {
		return RankingContentBuilder::build( RankingContentService::load_pack( 'FL' ), self::context( $context ), $people ?? self::people(), 'October 2026', '2026-10-05' );
	}

	public function testEveryPracticeAreaInThePackIsComplete(): void {
		$pack = RankingContentService::load_pack( 'FL' );
		$this->assertNotNull( $pack );
		$this->assertGreaterThanOrEqual( 11, count( $pack['areas'] ) );
		foreach ( $pack['areas'] as $slug => $area ) {
			foreach ( array( 'cert', 'certName', 'lead', 'rows', 'src', 'faq' ) as $key ) {
				$this->assertNotEmpty( $area[ $key ], "$slug.$key" );
			}
			$this->assertStringContainsString( $area['cert'], $area['certName'], $slug );
			foreach ( $area['src'] as $src ) {
				$this->assertStringStartsWith( 'https://', $src[0], $slug );
			}
		}
		foreach ( $pack['cities'] as $slug => $city ) {
			$this->assertNotEmpty( $city['county'], $slug );
			$this->assertNotEmpty( $city['circuit'], $slug );
		}
	}

	public function testTextStatesOnlyWhatTheFactsSupport(): void {
		$c = self::build();
		$this->assertNotNull( $c );
		$this->assertStringContainsString( 'lists 3 personal injury lawyers in Miami, Florida', $c['summary'] );
		// Two of three are certified: never "all".
		$this->assertStringContainsString( '2 of the 3 are Board Certified in Civil Trial Law by The Florida Bar', $c['summary'] );
		$this->assertStringNotContainsString( 'All 3', $c['summary'] . $c['body'] );
		$this->assertStringContainsString( 'from 12 to 30 years', $c['summary'] );
		$this->assertStringContainsString( '2 of the 3 list Spanish', $c['summary'] );
		$this->assertStringContainsString( '<td>12 to 30 (median 20)</td>', $c['body'] );
		$this->assertStringContainsString( '<td>Spanish (2), Portuguese (1)</td>', $c['body'] );
		$this->assertStringContainsString( 'University of Miami School of Law (2 lawyers)', $c['body'] );
		$this->assertSame( RankingContentBuilder::REVIEWED_BY, $c['reviewed_by'] );
		$this->assertSame( '2026-10-05', $c['reviewed_at'] );
	}

	public function testBodyHasEverySectionWithAnswersTablesAndSources(): void {
		$c = self::build();
		foreach ( array( 'What sets these Miami lawyers apart', 'About this ranking', 'Florida rules to know', 'Miami-Dade County crashes by the numbers', 'How to choose among these lawyers', 'Further reading', 'Sources' ) as $h ) {
			$this->assertStringContainsString( '<h2>' . $h . '</h2>', $c['body'] );
		}
		// A direct answer opens every section that is not a list of links.
		$this->assertSame( 5, substr_count( $c['body'], '</h2>' . "\n" . '<p><strong>' ) );
		$this->assertGreaterThanOrEqual( 3, substr_count( $c['body'], '<table>' ) );
		$this->assertStringContainsString( 'https://www.flsenate.gov/Laws/Statutes/2025/95.11', $c['body'] );
		$this->assertStringContainsString( 'Eleventh Judicial Circuit', $c['body'] );
		$this->assertStringContainsString( 'requires at least five years of practice', $c['body'] );
		$this->assertStringContainsString( 'Claims above $50,000 go to the circuit court', $c['body'] );
		$this->assertStringContainsString( 'href="/methodology/"', $c['body'] );
		$questions = array_column( $c['faq'], 'question' );
		$this->assertGreaterThanOrEqual( 7, count( $questions ) );
		$this->assertContains( 'Are there Spanish-speaking personal injury lawyers in Miami?', $questions );
		$this->assertContains( 'Which court handles cases in Miami?', $questions );
		foreach ( $c['faq'] as $item ) {
			$this->assertNotSame( '', trim( $item['answer'] ) );
		}
	}

	public function testOtherAreasSkipCrashDataAndUseTheirOwnRules(): void {
		$c = self::build(
			array(
				'area_slug' => 'family-law',
				'area_name' => 'Family',
				'city_slug' => 'tampa',
				'city_name' => 'Tampa',
			),
			array(
				array(
					'years'  => 15,
					'awards' => array( 'Board Certified in Marital and Family Law' ),
				),
			)
		);
		$this->assertStringContainsString( 'The lawyer in this ranking is Board Certified in Marital and Family Law', $c['body'] );
		$this->assertStringContainsString( 'section 61.021', $c['body'] );
		$this->assertStringNotContainsString( 'crashes', $c['body'] );
		$this->assertStringNotContainsString( 'Spanish', $c['summary'] );
		$this->assertStringContainsString( 'Thirteenth Judicial Circuit', $c['body'] );
	}

	public function testNoCompleteTextMeansNoContent(): void {
		$this->assertNull( self::build( array( 'city_slug' => 'naples' ) ), 'no city knowledge' );
		$this->assertNull( self::build( array( 'area_slug' => 'bankruptcy' ) ), 'no practice-area knowledge' );
		$this->assertNull( self::build( array( 'entity_type' => 'law_firm' ) ), 'firm rankings are written by editors' );
		$this->assertNull( self::build( array(), array() ), 'no entries' );
		$this->assertNull( RankingContentService::load_pack( 'TX' ) );
		$this->assertNull( RankingContentService::load_pack( '../FL' ) );
	}

	public function testPersonIgnoresFactsInConflict(): void {
		$p = RankingContentService::person(
			array(
				'years_experience' => array(
					'status' => 'conflict',
					'value'  => 9,
				),
				'languages'        => array(
					'status' => 'approved',
					'value'  => array( 'English', 'Spanish' ),
				),
				'awards'           => array(
					'status' => 'pending',
					'value'  => array(
						array(
							'name'   => 'Board Certified in Civil Trial Law',
							'issuer' => 'The Florida Bar',
						),
					),
				),
				'education'        => array(
					'status' => 'pending',
					'value'  => array( array( 'institution' => 'Stetson University College of Law' ) ),
				),
			)
		);
		$this->assertNull( $p['years'] );
		$this->assertSame( array( 'English', 'Spanish' ), $p['languages'] );
		$this->assertSame( array( 'Board Certified in Civil Trial Law' ), $p['awards'] );
		$this->assertSame( array( 'Stetson University College of Law' ), $p['schools'] );
	}

	public function testUserTextIsEscaped(): void {
		$people               = self::people();
		$people[0]['schools'] = array( '<script>x</script> Law' );
		$people[1]['schools'] = array( '<script>x</script> Law' );
		$c                    = self::build( array(), $people );
		$this->assertStringNotContainsString( '<script>', $c['body'] );
	}
}
