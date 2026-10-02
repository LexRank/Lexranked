<?php
/**
 * Autonomous research publication rules.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Research\AutoPublishPolicy;
use LexRanked\Core\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class AutoPublishPolicyTest extends TestCase {

	/**
	 * A lawyer draft that passes every check.
	 *
	 * @param array<string, mixed> $override Overrides.
	 * @return array<string, mixed>
	 */
	private static function lawyer( array $override = array() ): array {
		return array_replace(
			array(
				'entity_type'         => 'lawyer',
				'status'              => 'draft',
				'title'               => 'Joel Brown',
				'fields'              => array(
					'bar_state'  => 'FL',
					'bar_number' => '131231',
					'bar_status' => 'active',
					'is_demo'    => false,
				),
				'city'                => 'Miami',
				'state'               => 'Florida',
				'practice_area_count' => 1,
				'flagged_for_review'  => false,
				'verifications'       => array(
					array(
						'type'   => 'license',
						'status' => 'verified',
					),
					array(
						'type'   => 'bar_status',
						'status' => 'verified',
					),
				),
			),
			$override
		);
	}

	public function testLawyerWithOfficialChecksIsPublished(): void {
		$this->assertSame(
			array(
				'publish' => true,
				'reasons' => array(),
			),
			AutoPublishPolicy::decide_entity( self::lawyer() )
		);
	}

	public function testEachDoubtKeepsTheDraftWithAReason(): void {
		$cases = array(
			'not a draft'              => array( 'status' => 'publish' ),
			'demo data'                => array( 'fields' => array( 'is_demo' => true ) + self::lawyer()['fields'] ),
			'sources disagree'         => array( 'flagged_for_review' => true ),
			'no name'                  => array( 'title' => '  ' ),
			'no city and state'        => array( 'city' => null ),
			'no practice area'         => array( 'practice_area_count' => 0 ),
			'no bar state and bar'     => array( 'fields' => array( 'bar_number' => '' ) + self::lawyer()['fields'] ),
			'bar status is not active' => array( 'fields' => array( 'bar_status' => 'inactive' ) + self::lawyer()['fields'] ),
			'no bar status check'      => array( 'verifications' => array( self::lawyer()['verifications'][0] ) ),
		);
		foreach ( $cases as $expected => $override ) {
			$decision = AutoPublishPolicy::decide_entity( self::lawyer( $override ) );
			$this->assertFalse( $decision['publish'], $expected );
			$this->assertStringContainsString( $expected, implode( '; ', $decision['reasons'] ), $expected );
		}
	}

	public function testUnverifiedOrContradictedChecksAreDoubts(): void {
		$pending = AutoPublishPolicy::decide_entity(
			self::lawyer(
				array(
					'verifications' => array(
						array(
							'type'   => 'license',
							'status' => 'pending',
						),
						array(
							'type'   => 'bar_status',
							'status' => 'verified',
						),
					),
				)
			)
		);
		$this->assertFalse( $pending['publish'] );
		$this->assertSame( array( 'license check is pending, not verified by an official source' ), $pending['reasons'] );

		// A failed record next to a verified one is contradicting evidence.
		$conflict = AutoPublishPolicy::decide_entity(
			self::lawyer(
				array(
					'verifications' => array_merge(
						self::lawyer()['verifications'],
						array(
							array(
								'type'   => 'license',
								'status' => 'failed',
							),
						)
					),
				)
			)
		);
		$this->assertFalse( $conflict['publish'] );
		$this->assertSame( array( 'license check is failed, not verified by an official source' ), $conflict['reasons'] );
	}

	public function testLawFirmNeedsWebsiteAndVerifiedBusiness(): void {
		$firm = array(
			'entity_type'         => 'law_firm',
			'status'              => 'draft',
			'title'               => 'Freidin Brown, P.A.',
			'fields'              => array( 'website' => 'https://www.yourfloridatrialteam.com' ),
			'city'                => 'Miami',
			'state'               => 'Florida',
			'practice_area_count' => 1,
			'verifications'       => array(
				array(
					'type'   => 'business',
					'status' => 'verified',
				),
			),
		);
		$this->assertTrue( AutoPublishPolicy::decide_entity( $firm )['publish'] );

		$no_site = AutoPublishPolicy::decide_entity( array( 'fields' => array() ) + $firm );
		$this->assertSame( array( 'no website' ), $no_site['reasons'] );

		$no_check = AutoPublishPolicy::decide_entity( array( 'verifications' => array() ) + $firm );
		$this->assertSame( array( 'no business check' ), $no_check['reasons'] );
	}

	public function testUnknownTypeIsNeverPublished(): void {
		$this->assertFalse( AutoPublishPolicy::decide_entity( array( 'entity_type' => 'ranking' ) )['publish'] );
	}

	public function testSourcesArePublishedOnlyWhenTheyBackAPublishedProfile(): void {
		$this->assertTrue( AutoPublishPolicy::publish_source( 1, true ) );
		$this->assertTrue( AutoPublishPolicy::publish_source( 2, true ) );
		$this->assertFalse( AutoPublishPolicy::publish_source( 3, true ) );
		$this->assertFalse( AutoPublishPolicy::publish_source( 1, false ) );
	}

	public function testNewChecksOnPublishedProfilesNeedNoContradiction(): void {
		$this->assertTrue( AutoPublishPolicy::publish_record_for_published( 'verified', array() ) );
		$this->assertTrue( AutoPublishPolicy::publish_record_for_published( 'verified', array( 'verified' ) ) );
		$this->assertFalse( AutoPublishPolicy::publish_record_for_published( 'pending', array() ) );
		$this->assertFalse( AutoPublishPolicy::publish_record_for_published( 'failed', array() ) );
		$this->assertFalse( AutoPublishPolicy::publish_record_for_published( 'verified', array( 'failed' ) ) );
		$this->assertFalse( AutoPublishPolicy::publish_record_for_published( 'verified', array( 'expired', 'verified' ) ) );
	}

	public function testOfficialEvidenceOnPublishedProfilesFillsOnlyEmptyFields(): void {
		$this->assertTrue( AutoPublishPolicy::approve_claim_for_published( 1, 'seed', true, true ) );
		$this->assertFalse( AutoPublishPolicy::approve_claim_for_published( 2, 'seed', true, true ), 'Not an official source' );
		$this->assertFalse( AutoPublishPolicy::approve_claim_for_published( 1, 'ai', true, true ), 'AI-extracted' );
		$this->assertFalse( AutoPublishPolicy::approve_claim_for_published( 1, 'seed', false, true ), 'Name, location or practice areas' );
		$this->assertFalse( AutoPublishPolicy::approve_claim_for_published( 1, 'seed', true, false ), 'Would change a shown value' );
	}

	public function testRankingIsCreatedOnlyAboveTheThresholdAndOnce(): void {
		$this->assertSame(
			array(
				'create' => false,
				'reason' => '4 published profiles (needs 5)',
			),
			AutoPublishPolicy::decide_ranking( 4, 5, false )
		);
		$this->assertTrue( AutoPublishPolicy::decide_ranking( 5, 5, false )['create'] );
		$this->assertSame( 'a ranking already exists', AutoPublishPolicy::decide_ranking( 18, 5, true )['reason'] );
		$this->assertFalse( AutoPublishPolicy::decide_ranking( 0, 0, false )['create'] );
	}

	public function testRankingTitle(): void {
		$this->assertSame( 'Best Personal Injury Lawyers in Miami, Florida', AutoPublishPolicy::ranking_title( 'lawyer', 'Personal Injury', 'Miami', 'Florida' ) );
		$this->assertSame( 'Best Personal Injury Law Firms in Miami, Florida', AutoPublishPolicy::ranking_title( 'law_firm', 'Personal Injury', 'Miami', 'Florida' ) );
	}

	public function testAutonomyIsOffByDefaultAndToggleable(): void {
		$this->assertFalse( Settings::defaults()['research_autonomy'] );
		$this->assertTrue( Settings::sanitize( array( 'research_autonomy' => '1' ) )['research_autonomy'] );
		$this->assertFalse( Settings::sanitize( array( 'research_autonomy' => '0' ) )['research_autonomy'] );
	}
}
