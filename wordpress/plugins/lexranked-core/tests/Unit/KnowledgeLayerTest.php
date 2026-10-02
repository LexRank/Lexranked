<?php
/**
 * Etap B: attributes, normalisation, facts, sources, entity resolution.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Attribute\Attribute;
use LexRanked\Core\Attribute\Attributes;
use LexRanked\Core\Attribute\Normalizer;
use LexRanked\Core\Fact\FactBuilder;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\Ranking\ScoreVersion;
use LexRanked\Core\REST\DTO\FactMapper;
use LexRanked\Core\REST\DTO\SourceMapper;
use LexRanked\Core\Research\CandidateMatcher;
use LexRanked\Core\Research\Identifiers;
use LexRanked\Core\Services;
use LexRanked\Core\Settings\Settings;
use LexRanked\Core\Sources\SourceTiers;
use LexRanked\Core\Verification\Freshness;
use PHPUnit\Framework\TestCase;

final class KnowledgeLayerTest extends TestCase {

	public function testAttributeRegistryCoversEveryTraceableField(): void {
		foreach ( array(
			'lawyer'   => new Lawyer(),
			'law_firm' => new LawFirm(),
		) as $type => $definition ) {
			foreach ( Services::traceable_fields( $definition ) as $field ) {
				$attribute = Attributes::fact( $field );
				$this->assertNotNull( $attribute, "$field has no attribute definition" );
				$this->assertContains( $type, $attribute->entity_types, "$field is not declared for $type" );
			}
		}
		foreach ( Attributes::facts() as $attribute ) {
			$this->assertSame( Attribute::LAYER_FACT, $attribute->layer );
		}
		foreach ( array_keys( ScoreVersion::COMPONENTS ) as $component ) {
			$this->assertSame( Attribute::LAYER_DERIVED, Attributes::derived()[ $component ]->layer, 'Score components are derived metrics, never facts' );
		}
		$this->assertArrayNotHasKey( 'commercial_status', Attributes::facts() );
		$this->assertArrayNotHasKey( 'summary', Attributes::facts(), 'Interpretation (text) is not an attribute' );
		$dictionary = array_column( Attributes::dictionary(), null, 'key' );
		$this->assertSame( 'review_data', $dictionary['review_count']['freshness'] );
		$this->assertSame( 'years', $dictionary['years_experience']['unit'] );
	}

	public function testNormalisation(): void {
		$n = static fn( string $key, mixed $value ): mixed => Normalizer::normalize( Attributes::fact( $key ), $value );
		$this->assertSame( '+13055550101', $n( 'phone', '(305) 555-0101' ) );
		$this->assertSame( '+13055550101', $n( 'phone', '+1 305 555 0101' ) );
		$this->assertSame( 'https://examplelaw.com', $n( 'website', 'HTTPS://ExampleLaw.com/' ) );
		$this->assertSame( 'https://examplelaw.com/team', $n( 'website', 'https://examplelaw.com/team/#top' ) );
		$this->assertNull( $n( 'website', 'ftp://examplelaw.com' ) );
		$this->assertSame( '123456', $n( 'bar_number', '0123-456' ) );
		$this->assertSame( 'FL', $n( 'bar_state', ' fl ' ) );
		$this->assertSame( '33101-1234', $n( 'zip_code', '331011234' ) );
		$this->assertSame( 18, $n( 'years_experience', '18' ) );
		$this->assertNull( $n( 'years_experience', '18.5' ) );
		$this->assertSame( 4.8, $n( 'rating', '4.80' ) );
		$this->assertSame( 'active', $n( 'bar_status', ' Active ' ) );
		$this->assertSame( array( 'car-accidents', 'personal-injury' ), $n( 'practice_areas', 'Personal Injury; Car Accidents, personal injury' ) );
		$this->assertSame( array( 'English', 'Spanish' ), $n( 'languages', array( 'Spanish', 'English', ' English ' ) ) );
		$this->assertSame( 'avery@examplelaw.com', $n( 'email', 'Avery@ExampleLaw.com' ) );
		$this->assertNull( $n( 'email', 'nope' ) );
	}

	private static function claim( int $id, string $field, mixed $value, string $source_type, string $status = 'verified', string $at = '2026-09-20T10:00:00Z', float $confidence = 0.9 ): array {
		$attribute = Attributes::fact( $field );
		return array(
			'claim_id'            => $id,
			'entity_id'           => 1,
			'entity_type'         => 'lawyer',
			'field_name'          => $field,
			'value'               => $value,
			'value_normalized'    => null === $attribute ? null : Normalizer::normalize( $attribute, $value ),
			'source_id'           => $id * 10,
			'source_url'          => 'https://example.com/' . $id,
			'source_type'         => $source_type,
			'retrieved_at'        => $at,
			'confidence'          => $confidence,
			'verification_status' => $status,
			'method'              => 'seed',
		);
	}

	public function testFactsComeFromTheBestEvidence(): void {
		$facts = FactBuilder::build(
			array(
				self::claim( 1, 'bar_status', 'active', 'bar_association' ),
				self::claim( 2, 'bar_status', 'inactive', 'secondary', 'pending', '2026-09-25T10:00:00Z' ),
				self::claim( 3, 'phone', '(305) 555-0101', 'official_website', 'pending' ),
				self::claim( 4, 'phone', '+1 305 555 0101', 'official_website', 'pending', '2026-09-21T10:00:00Z' ),
				self::claim( 5, 'rating', 4.8, 'review_platform', 'pending' ),
				self::claim( 6, 'rating', 4.6, 'review_platform', 'pending' ),
				self::claim( 7, 'commercial_status', 'premium', 'secondary' ),
			),
			new SourceTiers()
		);
		$this->assertSame( array( 'bar_status', 'phone', 'rating' ), array_keys( $facts ), 'Unknown attributes never become facts' );
		$this->assertSame( 'active', $facts['bar_status']['value'], 'The official registry beats a newer secondary source' );
		$this->assertSame( FactBuilder::VERIFIED, $facts['bar_status']['status'] );
		$this->assertSame( '2026-09-20 10:00:00', $facts['bar_status']['verified_at'] );
		$this->assertSame( 2, $facts['bar_status']['claim_count'] );
		$this->assertSame( '+13055550101', $facts['phone']['value'], 'Normalised' );
		$this->assertSame( FactBuilder::UNVERIFIED, $facts['phone']['status'], 'Two formats of the same number are not a conflict' );
		$this->assertNull( $facts['phone']['verified_at'] );
		$this->assertSame( FactBuilder::CONFLICT, $facts['rating']['status'], 'Disagreeing sources of the same tier are flagged, not averaged' );
	}

	public function testFactDtoCarriesSourceAndFreshness(): void {
		$claims = array( 1 => self::claim( 1, 'bar_status', 'active', 'bar_association' ) );
		$facts  = array(
			'bar_status' => array(
				'attribute'   => 'bar_status',
				'value'       => 'active',
				'status'      => 'verified',
				'confidence'  => 0.99,
				'source_tier' => 1,
				'claim_id'    => 1,
				'source_id'   => 10,
				'claim_count' => 1,
				'observed_at' => '2026-09-20 10:00:00',
				'verified_at' => '2026-09-20 10:00:00',
			),
		);
		$dto    = FactMapper::facts(
			$facts,
			$claims,
			array(
				10 => array(
					'id'        => 10,
					'name'      => 'Florida Bar',
					'publisher' => 'The Florida Bar',
				),
			),
			new Freshness(),
			new \DateTimeImmutable( '2026-09-27T00:00:00Z' )
		)[0];
		$this->assertSame( 'Bar status', $dto['label'] );
		$this->assertSame( 'Florida Bar', $dto['source']['name'] );
		$this->assertSame( 'Official / regulatory', $dto['source']['tierLabel'] );
		$this->assertSame( '2026-09-20T10:00:00Z', $dto['verifiedAt'] );
		$this->assertSame( 'bar_status', $dto['freshness']['category'] );
		$this->assertFalse( $dto['freshness']['isStale'] );
	}

	public function testSourcesAreObjects(): void {
		$tiers = ( new Settings( array() ) )->source_tiers();
		foreach ( array( 'professional_association', 'editorial', 'social', 'other' ) as $type ) {
			$this->assertContains( $type, $tiers->types() );
		}
		$this->assertSame( 1, $tiers->tier_for( 'bar_association' ), 'Existing tiers are unchanged' );
		$this->assertSame( 5, $tiers->tier_for( 'secondary' ) );
		$this->assertSame( 'Official business / professional', SourceTiers::label( 2 ) );
		$old = ( new Settings( array( 'source_tiers' => array( 'official_registry' => 1 ) ) ) )->source_tiers();
		$this->assertContains( 'editorial', $old->types(), 'New types appear on existing installs' );

		$dto = SourceMapper::source(
			array(
				'id'     => 3,
				'title'  => 'Florida Bar member profile',
				'fields' => array(
					'url'             => 'https://www.floridabar.org/directories/1',
					'source_type'     => 'bar_association',
					'publisher'       => 'The Florida Bar',
					'last_checked_at' => '2026-09-27T08:00:00Z',
					'status'          => null,
					'is_demo'         => false,
				),
			),
			$tiers
		);
		$this->assertSame( 'floridabar.org', $dto['domain'] );
		$this->assertSame( 'Official / regulatory', $dto['tierLabel'] );
		$this->assertSame( 'active', $dto['status'] );
		$this->assertSame( '2026-09-27T08:00:00Z', $dto['lastCheckedAt'] );
	}

	public function testIdentifiers(): void {
		$ids = Identifiers::from(
			array(
				'phone'      => '(305) 555-0101',
				'email'      => 'Avery@ExampleLaw.com',
				'bar_state'  => 'fl',
				'bar_number' => '0012345',
				'address'    => '100 Example Avenue, Suite 5',
				'zip_code'   => '33101-0001',
			)
		);
		$this->assertSame(
			array(
				'phone'   => '+13055550101',
				'email'   => 'avery@examplelaw.com',
				'bar'     => 'FL:12345',
				'address' => '100 example ave ste 5|33101',
			),
			$ids
		);
		$this->assertSame(
			array(),
			Identifiers::from(
				array(
					'phone'      => 'call me',
					'bar_number' => '123',
				)
			),
			'A bar number needs its state'
		);
	}

	private static function entity( int $id, string $name, array $identifiers = array(), array $cities = array( 'Miami' ) ): array {
		return array(
			'id'              => $id,
			'normalized_name' => $name,
			'cities'          => $cities,
			'states'          => array( 'FL' ),
			'domain'          => null,
			'identifiers'     => $identifiers,
		);
	}

	private static function probe( string $type, string $name, array $identifiers, ?string $city = 'Miami' ): array {
		return array(
			'entity_type'     => $type,
			'normalized_name' => $name,
			'city'            => $city,
			'state'           => 'FL',
			'domain'          => null,
			'identifiers'     => $identifiers,
		);
	}

	public function testOfficialIdentifierBeatsNameSpelling(): void {
		$r = CandidateMatcher::decide( self::probe( 'lawyer', 'jordan sample', array( 'bar' => 'FL:1001' ), null ), array( self::entity( 5, 'jordan q sample', array( 'bar' => 'FL:1001' ), array( 'Tampa' ) ) ) );
		$this->assertSame( CandidateMatcher::MATCH, $r['decision'] );
		$this->assertSame( 5, $r['entity_id'] );
		$this->assertStringContainsString( 'bar number', $r['reason'] );
	}

	public function testSameNameDifferentBarNumberIsADifferentPerson(): void {
		$r = CandidateMatcher::decide( self::probe( 'lawyer', 'jane doe', array( 'bar' => 'FL:2002' ) ), array( self::entity( 5, 'jane doe', array( 'bar' => 'FL:1001' ) ) ) );
		$this->assertSame( CandidateMatcher::CREATE, $r['decision'] );
	}

	public function testPhoneIsStrongForFirmsButWeakForLawyers(): void {
		$firm = CandidateMatcher::decide( self::probe( 'law_firm', 'smith injury group', array( 'phone' => '+13055550100' ) ), array( self::entity( 8, 'smith law', array( 'phone' => '+13055550100' ) ) ) );
		$this->assertSame( CandidateMatcher::MATCH, $firm['decision'] );

		$lawyer = CandidateMatcher::decide( self::probe( 'lawyer', 'alex other', array( 'phone' => '+13055550100' ) ), array( self::entity( 9, 'blake sample', array( 'phone' => '+13055550100' ) ) ) );
		$this->assertSame( CandidateMatcher::REVIEW, $lawyer['decision'], 'A shared firm line alone is only a possible duplicate' );

		$same = CandidateMatcher::decide( self::probe( 'lawyer', 'blake sample', array( 'phone' => '+13055550100' ), 'Tampa' ), array( self::entity( 9, 'blake sample', array( 'phone' => '+13055550100' ) ) ) );
		$this->assertSame( CandidateMatcher::MATCH, $same['decision'], 'Name plus phone confirms' );

		$email = CandidateMatcher::decide( self::probe( 'lawyer', 'b sample', array( 'email' => 'b@x.com' ) ), array( self::entity( 9, 'blake sample', array( 'email' => 'b@x.com' ) ) ) );
		$this->assertSame( CandidateMatcher::MATCH, $email['decision'] );
	}

	public function testNameAloneStaysWeak(): void {
		$r = CandidateMatcher::decide( self::probe( 'lawyer', 'jane doe', array(), null ), array( self::entity( 5, 'jane doe' ) ) );
		$this->assertSame( CandidateMatcher::REVIEW, $r['decision'] );
	}
}
