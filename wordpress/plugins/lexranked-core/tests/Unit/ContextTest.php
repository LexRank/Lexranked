<?php
/**
 * Etap F: contextual rankings.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Ranking\ContextDiscovery;
use LexRanked\Core\Ranking\ContextEligibility;
use LexRanked\Core\Ranking\RankingQualifier;
use LexRanked\Core\REST\DTO\RankingMapper;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase {

	private static function fact( mixed $value, string $status = 'verified' ): array {
		return array(
			'value'       => $value,
			'status'      => $status,
			'source_id'   => 7,
			'claim_id'    => 9,
			'observed_at' => '2026-09-20 10:00:00',
			'verified_at' => 'verified' === $status ? '2026-09-21 10:00:00' : null,
		);
	}

	public function testQualifierFromFieldsSegmentsAndLabels(): void {
		$case = RankingQualifier::from_fields( 'case_type', 'Car Accidents' );
		$this->assertNotNull( $case );
		$this->assertSame( 'car-accidents', $case->value );
		$this->assertSame( 'car-accidents', $case->segment() );
		$this->assertSame( 'Car Accidents', $case->label( 'Car Accidents' ) );
		$this->assertSame( 'case_types', $case->attribute() );

		$language = RankingQualifier::from_fields( 'language', 'Spanish' );
		$this->assertSame( 'spanish-speaking', $language?->segment() );
		$this->assertSame( 'Spanish-speaking', $language?->label() );

		$client = RankingQualifier::from_fields( 'client_type', 'businesses' );
		$this->assertSame( 'for-businesses', $client?->segment() );
		$this->assertSame( 'For businesses', $client?->label() );

		$this->assertNull( RankingQualifier::from_fields( 'client_type', 'celebrities' ), 'Client types come from a fixed list' );
		$this->assertNull( RankingQualifier::from_fields( 'keyword', 'cheap' ), 'No free-form contexts' );
		$this->assertNull( RankingQualifier::from_fields( 'language', '' ) );
		$this->assertNull( RankingQualifier::from_fields( null, null ) );
	}

	public function testOnlyASourcedNonConflictingFactQualifies(): void {
		$spanish = RankingQualifier::from_fields( 'language', 'spanish' );
		$this->assertNotNull( $spanish );

		$evidence = $spanish->qualify( array( 'languages' => self::fact( array( 'English', 'Spanish' ) ) ) );
		$this->assertSame( 'Spanish', $evidence['value'] );
		$this->assertSame( 'verified', $evidence['status'] );
		$this->assertSame( 7, $evidence['sourceId'] );
		$this->assertSame( '2026-09-21T10:00:00Z', $evidence['verifiedAt'] );

		$this->assertSame( 'unverified', $spanish->qualify( array( 'languages' => self::fact( array( 'Spanish' ), 'unverified' ) ) )['status'] );
		$this->assertNull( $spanish->qualify( array( 'languages' => self::fact( array( 'Spanish' ), 'conflict' ) ) ), 'Conflicting sources never qualify' );
		$this->assertNull( $spanish->qualify( array( 'languages' => self::fact( array( 'English' ) ) ) ) );
		$this->assertNull( $spanish->qualify( array() ), 'No fact, no qualification' );
		$this->assertNull( $spanish->qualify( array( 'summary' => self::fact( 'Hablamos español' ) ) ), 'Nothing is inferred from text' );

		$case = RankingQualifier::from_fields( 'case_type', 'car-accidents' );
		$this->assertNull( $case?->qualify( array( 'practice_areas' => self::fact( array( 'car-accidents' ) ) ) ), 'Case types are their own fact' );
		$this->assertNotNull( $case?->qualify( array( 'case_types' => self::fact( array( 'car-accidents', 'wrongful-death' ) ) ) ) );
	}

	public function testEligibilityThresholds(): void {
		$ok = ContextEligibility::evaluate(
			array(
				'parent'    => 127,
				'qualified' => 40,
				'verified'  => 22,
			),
			5,
			3
		);
		$this->assertTrue( $ok['eligible'] );
		$this->assertSame( array(), $ok['reasons'] );

		$thin = ContextEligibility::evaluate(
			array(
				'parent'    => 2,
				'qualified' => 2,
				'verified'  => 0,
			),
			5,
			3
		);
		$this->assertFalse( $thin['eligible'] );
		$this->assertCount( 3, $thin['reasons'], 'Too few, too few verified, and identical to the broader ranking' );

		$same = ContextEligibility::evaluate(
			array(
				'parent'    => 8,
				'qualified' => 8,
				'verified'  => 8,
			),
			5,
			3
		);
		$this->assertFalse( $same['eligible'], 'A context everyone has adds nothing' );

		$never = ContextEligibility::evaluate( null, 5 );
		$this->assertFalse( $never['eligible'] );
		$this->assertSame( array( 'Not calculated yet.' ), $never['reasons'] );

		$bad = ContextEligibility::evaluate(
			array(
				'parent'    => 50,
				'qualified' => 20,
				'verified'  => 10,
			),
			5,
			3,
			'Not a sub-area.'
		);
		$this->assertFalse( $bad['eligible'] );
	}

	public function testDiscoveryStartsFromTheDataAndOnlyReports(): void {
		$entities = array();
		foreach ( range( 1, 8 ) as $i ) {
			$entities[ $i ] = array(
				'languages'  => self::fact( $i <= 3 ? array( 'English', 'Spanish' ) : array( 'English' ), 'unverified' ),
				'case_types' => self::fact( $i <= 5 ? array( 'car-accidents' ) : array( 'wrongful-death' ), $i <= 4 ? 'verified' : 'unverified' ),
			);
		}
		$suggestions = ContextDiscovery::suggest( $entities, 5, 3, array( 'car-accidents' ) );
		$by          = array_column( $suggestions, null, 'value' );

		$this->assertTrue( $by['car-accidents']['eligibility']['eligible'] );
		$this->assertSame( 5, $by['car-accidents']['eligibility']['qualified'] );
		$this->assertSame( 4, $by['car-accidents']['eligibility']['verified'] );
		$this->assertSame( 'car-accidents', $suggestions[0]['value'], 'Eligible contexts first' );
		$this->assertFalse( $by['spanish']['eligibility']['eligible'] );
		$this->assertFalse( $by['english']['eligibility']['eligible'], 'Everyone speaks English: not a distinct page' );
		$this->assertContains( 'Not a sub-area of the practice area in the taxonomy.', $by['wrongful-death']['eligibility']['reasons'] );
	}

	public function testPathsCarryTheContextSegment(): void {
		$location = array(
			'stateSlug' => 'florida',
			'citySlug'  => 'miami',
		);
		$practice = array( 'slug' => 'personal-injury' );
		$this->assertSame( '/rankings/florida/miami/personal-injury/', RankingMapper::path( $location, $practice ) );
		$this->assertSame( '/rankings/florida/miami/personal-injury/car-accidents/', RankingMapper::path( $location, $practice, 'car-accidents' ) );
		$this->assertNull( RankingMapper::path( null, null, 'car-accidents' ) );

		$record = array(
			'locations'      => array(
				array(
					'id'     => 1,
					'slug'   => 'florida',
					'name'   => 'Florida',
					'parent' => 0,
				),
				array(
					'id'     => 2,
					'slug'   => 'miami',
					'name'   => 'Miami',
					'parent' => 1,
				),
			),
			'practice_areas' => array(
				array(
					'id'     => 11,
					'slug'   => 'car-accidents',
					'name'   => 'Car Accidents',
					'parent' => 10,
				),
				array(
					'id'     => 10,
					'slug'   => 'personal-injury',
					'name'   => 'Personal Injury',
					'parent' => 0,
				),
			),
			'fields'         => array(
				'context_type'  => 'case_type',
				'context_value' => 'car-accidents',
			),
		);
		$this->assertSame( '/rankings/florida/miami/personal-injury/car-accidents/', RankingMapper::record_path( $record ), 'The case-type term never becomes the practice area' );
		$record['fields'] = array(
			'context_type'  => 'language',
			'context_value' => 'spanish',
		);
		$this->assertSame( '/rankings/florida/miami/personal-injury/spanish-speaking/', RankingMapper::record_path( $record ), 'Top-level area first' );
	}

	public function testContextNeverChangesTheScore(): void {
		foreach ( array( 'src/Ranking/ScoreCalculator.php', 'src/Ranking/RankingEngine.php', 'src/Ranking/InputBuilder.php' ) as $file ) {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			$this->assertStringNotContainsString( 'RankingQualifier', $source, $file . ' must not read the context' );
			$this->assertStringNotContainsString( 'case_types', $source, $file . ' must not score case types' );
			$this->assertStringNotContainsString( 'client_types', $source, $file . ' must not score client types' );
		}
	}
}
