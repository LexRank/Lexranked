<?php
/**
 * Statewide figures: counts with their samples, never a single lawyer.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Market\StateData;
use PHPUnit\Framework\TestCase;

final class StateDataTest extends TestCase {

	private static function person( ?string $city, array $areas, ?int $years, array $languages = array(), array $awards = array(), array $schools = array() ): array {
		return array(
			'city_slug' => $city,
			'city_name' => null === $city ? null : ucfirst( $city ),
			'areas'     => array_map( static fn( string $a ): array => array( $a, ucfirst( $a ) ), $areas ),
			'years'     => $years,
			'languages' => $languages,
			'awards'    => $awards,
			'schools'   => $schools,
		);
	}

	private static function data(): array {
		return StateData::compute(
			array(
				self::person( 'miami', array( 'tax' ), 30, array( 'Spanish', 'French' ), array( 'Board Certified in Tax Law', 'Board Certified in Tax Law' ), array( 'Stetson' ) ),
				self::person( 'miami', array( 'tax', 'immigration' ), 12, array( 'Spanish' ), array( 'Board Certified in Immigration and Nationality Law', 'AV Preeminent' ) ),
				self::person( 'tampa', array( 'tax' ), null, array(), array( 'Board Certified in Tax Law' ), array( 'Stetson', 'Stetson' ) ),
				self::person( null, array(), 45 ),
			),
			'2026-10-09T00:00:00Z'
		);
	}

	public function testCountsCitiesAndAreasWithSamples(): void {
		$d = self::data();
		$this->assertSame( 4, $d['lawyers'] );
		$this->assertSame( 3, $d['certified'] );
		$this->assertSame( 0, $d['multiCertified'] );
		$this->assertSame( 2, $d['spanish'] );
		$this->assertSame( array( 'miami', 'tampa' ), array_column( $d['cities'], 'slug' ) );
		$miami = $d['cities'][0];
		$this->assertSame( 2, $miami['lawyers'] );
		$this->assertSame( 2, $miami['spanish'] );
		$this->assertSame( 2, $miami['certified'] );
		$this->assertSame( 21.0, $miami['experience']['median'] );
		$this->assertSame(
			array(
				array(
					'slug'  => 'tax',
					'name'  => 'Tax',
					'count' => 2,
				),
				array(
					'slug'  => 'immigration',
					'name'  => 'Immigration',
					'count' => 1,
				),
			),
			$miami['areas']
		);
		$tax = $d['practiceAreas'][0];
		$this->assertSame( 'tax', $tax['slug'] );
		$this->assertSame( 3, $tax['lawyers'] );
		$this->assertSame( 2, $tax['cities'] );
		// The Tampa lawyer has no years on record: the sample says so.
		$this->assertSame( 2, $tax['experience']['sample'] );
	}

	public function testCertificationsLanguagesSchoolsAreCountedOncePerLawyer(): void {
		$d = self::data();
		$this->assertSame(
			array(
				array(
					'name'  => 'Tax Law',
					'count' => 2,
				),
				array(
					'name'  => 'Immigration and Nationality Law',
					'count' => 1,
				),
			),
			$d['certifications']
		);
		$this->assertSame( 2, $d['languages']['sample'] );
		$this->assertSame( array( 'Spanish', 'French' ), array_column( $d['languages']['items'], 'name' ) );
		$this->assertSame( 2, $d['schools']['sample'] );
		$this->assertSame( 2, $d['schools']['items'][0]['count'] );
	}

	public function testExperienceSpreadAndBuckets(): void {
		$e = self::data()['experience'];
		$this->assertSame( 3, $e['sample'] );
		$this->assertSame( 12, $e['min'] );
		$this->assertSame( 30.0, $e['median'] );
		$this->assertSame( 45, $e['max'] );
		$this->assertSame( array( 0, 1, 0, 1, 1 ), array_column( $e['buckets'], 'count' ) );
		$this->assertNull( StateData::spread( array() )['median'] );
	}

	public function testNothingIdentifiesALawyer(): void {
		$json = (string) json_encode( self::data() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
		$this->assertStringNotContainsString( 'AV Preeminent', $json );
		$this->assertSame( '', StateData::certification( 'AV Preeminent' ) );
	}
}
