<?php
/**
 * Knowledge added after release.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Content\KnowledgeStore;
use LexRanked\Core\Content\RankingContentBuilder;
use LexRanked\Core\Content\RankingContentService;
use PHPUnit\Framework\TestCase;

final class KnowledgeStoreTest extends TestCase {

	/**
	 * A complete area entry.
	 *
	 * @return array<string, mixed>
	 */
	private static function area(): array {
		return array(
			'cert'     => 'Health',
			'certName' => 'Board Certified in Health Law',
			'lead'     => 'A lead sentence about the rules.',
			'rows'     => array( array( 'Rule', 'What it means (Fla. Stat. § 1.01)' ) ),
			'src'      => array( array( 'https://www.flsenate.gov/Laws/Statutes/2025/1.01', 'Florida Statutes, section 1.01' ) ),
			'faq'      => array( array( 'A question?', 'An answer.' ) ),
			'guides'   => array( array( '/articles/a-guide/', 'A guide' ) ),
		);
	}

	public function testShippedPackMeetsTheSameRules(): void {
		$pack = RankingContentService::load_pack( 'FL' );
		foreach ( $pack['areas'] as $slug => $area ) {
			$this->assertSame( array(), KnowledgeStore::area_errors( $area ), $slug );
		}
		foreach ( $pack['cities'] as $slug => $city ) {
			$this->assertSame( array(), KnowledgeStore::city_errors( $city ), $slug );
		}
	}

	public function testIncompleteAreasAreRejected(): void {
		$this->assertSame( array(), KnowledgeStore::area_errors( self::area() ) );
		$cases = array(
			'src'      => array( array( 'http://example.com/', 'Not HTTPS' ) ),
			'faq'      => array(),
			'guides'   => array( array( 'https://example.com/', 'Not a site path' ) ),
			'certName' => 'Board Certified in Tax Law',
			'rows'     => array( array( 'Only one cell' ) ),
			'lead'     => ' ',
		);
		foreach ( $cases as $key => $value ) {
			$area         = self::area();
			$area[ $key ] = $value;
			$this->assertNotSame( array(), KnowledgeStore::area_errors( $area ), $key );
		}
	}

	public function testAdditionsExtendThePackWithoutLosingShippedData(): void {
		$pack   = RankingContentService::load_pack( 'FL' );
		$merged = KnowledgeStore::merge(
			$pack,
			array(
				'areas'  => array(
					'health-law' => self::area(),
					'broken'     => array( 'cert' => 'X' ),
				),
				'cities' => array(
					'key-west' => array(
						'county'  => 'Monroe',
						'circuit' => 'Sixteenth',
					),
					'miami'    => array(
						'county'  => 'Miami-Dade',
						'circuit' => 'Eleventh',
					),
				),
			)
		);
		$this->assertArrayHasKey( 'health-law', $merged['areas'] );
		$this->assertArrayNotHasKey( 'broken', $merged['areas'] );
		$this->assertSame( 'Monroe', $merged['cities']['key-west']['county'] );
		$this->assertSame( $pack['cities']['miami'], $merged['cities']['miami'], 'shipped keys such as crash data are kept' );
		$this->assertSame( $pack['areas']['personal-injury'], $merged['areas']['personal-injury'] );

		$c = RankingContentBuilder::build(
			$merged,
			array(
				'area_slug'   => 'health-law',
				'area_name'   => 'Health Care',
				'city_slug'   => 'key-west',
				'city_name'   => 'Key West',
				'state_slug'  => 'florida',
				'entity_type' => 'lawyer',
			),
			array(
				array(
					'years'     => 12,
					'languages' => array(),
					'awards'    => array( 'Board Certified in Health Law' ),
					'schools'   => array(),
				),
			),
			'October 2026',
			'2026-10-05'
		);
		$this->assertNotNull( $c, 'an added area and city produce a complete page' );
		$this->assertStringContainsString( 'Monroe County', $c['body'] );
		$this->assertStringContainsString( 'section 1.01', $c['body'] );
	}
}
