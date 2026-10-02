<?php
/**
 * Commercial features (Phase 9): claims, placements, separation from ranking.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Commercial\ClaimRequest;
use LexRanked\Core\Commercial\ClaimSignals;
use LexRanked\Core\Commercial\ClaimStatus;
use LexRanked\Core\Commercial\PlacementPolicy;
use LexRanked\Core\Commercial\Product;
use LexRanked\Core\Commercial\StatusResolver;
use LexRanked\Core\Domain\CommercialStatus;
use LexRanked\Core\Schema\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommercialTest extends TestCase {

	private static function claim( array $overrides = array() ): array {
		return array_merge(
			array(
				'entityType' => 'lawyer',
				'entityId'   => 42,
				'name'       => '  Avery   Example ',
				'email'      => 'Avery@ExampleLaw.com',
				'phone'      => '(305) 555-0110',
				'role'       => 'self',
				'barState'   => 'fl',
				'barNumber'  => '0123456',
				'message'    => "I am the lawyer.\nPlease update my phone.",
				'consent'    => true,
			),
			$overrides
		);
	}

	public function testValidClaimIsNormalised(): void {
		$c = ClaimRequest::validate( self::claim() );
		$this->assertSame( 'Avery Example', $c['claimant_name'] );
		$this->assertSame( 'avery@examplelaw.com', $c['claimant_email'] );
		$this->assertSame( 'FL', $c['bar_state'] );
		$this->assertSame( 42, $c['entity_id'] );
		$this->assertSame( "I am the lawyer.\nPlease update my phone.", $c['message'] );
	}

	public static function invalidClaims(): array {
		return array(
			'no consent'           => array( array( 'consent' => false ), 'consent' ),
			'consent as string'    => array( array( 'consent' => 'true' ), 'consent' ),
			'bad type'             => array( array( 'entityType' => 'ranking' ), 'entityType' ),
			'bad id'               => array( array( 'entityId' => '0' ), 'entityId' ),
			'no name'              => array( array( 'name' => '  ' ), 'name' ),
			'bad email'            => array( array( 'email' => 'not-an-email' ), 'email' ),
			'bad phone'            => array( array( 'phone' => 'call me' ), 'phone' ),
			'link in message'      => array( array( 'message' => 'See https://spam.example' ), 'message' ),
			'long message'         => array( array( 'message' => str_repeat( 'a', 1001 ) ), 'message' ),
			'self without bar no.' => array( array( 'barNumber' => '' ), 'barNumber' ),
			'bad bar state'        => array( array( 'barState' => 'XX' ), 'barState' ),
			'firm claimed as self' => array( array( 'entityType' => 'law_firm' ), 'role' ),
			'unknown role'         => array( array( 'role' => 'owner' ), 'role' ),
			'array value'          => array( array( 'name' => array( 'x' ) ), 'name' ),
		);
	}

	#[DataProvider( 'invalidClaims' )]
	public function testInvalidClaimsAreRejected( array $overrides, string $field ): void {
		try {
			ClaimRequest::validate( self::claim( $overrides ) );
			$this->fail( 'Expected a validation error for ' . $field );
		} catch ( ValidationException $e ) {
			$this->assertSame( $field, $e->field_key );
		}
	}

	public function testFirmRepresentativeNeedsNoBarNumber(): void {
		$c = ClaimRequest::validate(
			self::claim(
				array(
					'entityType' => 'law_firm',
					'role'       => 'firm_representative',
					'barState'   => '',
					'barNumber'  => '',
				)
			)
		);
		$this->assertSame( 'firm_representative', $c['claimant_role'] );
	}

	public function testClaimLifecycle(): void {
		$this->assertTrue( ClaimStatus::PendingEmail->can_become( ClaimStatus::PendingReview ) );
		$this->assertFalse( ClaimStatus::PendingEmail->can_become( ClaimStatus::Approved ), 'Email must be confirmed before approval' );
		$this->assertTrue( ClaimStatus::PendingReview->can_become( ClaimStatus::Approved ) );
		$this->assertTrue( ClaimStatus::Approved->can_become( ClaimStatus::Rejected ), 'Approved claims can be revoked' );
		$this->assertFalse( ClaimStatus::Rejected->can_become( ClaimStatus::Approved ) );
		$this->assertFalse( ClaimStatus::Expired->can_become( ClaimStatus::PendingReview ) );
		$this->assertTrue( ClaimStatus::Expired->is_closed() );
		$this->assertFalse( ClaimStatus::Approved->is_closed(), 'Approved claims keep contact details' );
	}

	public function testBarSignal(): void {
		$this->assertSame( 'match', ClaimSignals::bar( 'FL', '0123456', 'fl', '123-456' ) );
		$this->assertSame( 'mismatch', ClaimSignals::bar( 'GA', '123456', 'FL', '123456' ) );
		$this->assertSame( 'mismatch', ClaimSignals::bar( 'FL', '123457', 'FL', '123456' ) );
		$this->assertSame( 'unknown', ClaimSignals::bar( 'FL', '123456', 'FL', '' ) );
	}

	public function testEmailDomainSignal(): void {
		$this->assertSame( 'match', ClaimSignals::email_domain( 'a@examplelaw.com', 'https://www.examplelaw.com/team' ) );
		$this->assertSame( 'match', ClaimSignals::email_domain( 'a@mail.examplelaw.com', 'https://examplelaw.com' ) );
		$this->assertSame( 'no_match', ClaimSignals::email_domain( 'a@examplelaw.co', 'https://examplelaw.com' ) );
		$this->assertSame( 'no_match', ClaimSignals::email_domain( 'a@notexamplelaw.com', 'https://examplelaw.com' ), 'Suffix must be a whole label' );
		$this->assertSame( 'free_mail', ClaimSignals::email_domain( 'a@gmail.com', 'https://examplelaw.com' ) );
		$this->assertSame( 'unknown', ClaimSignals::email_domain( 'a@examplelaw.com', '' ) );
	}

	public function testStatusResolver(): void {
		$this->assertSame( CommercialStatus::Free, StatusResolver::resolve( false, true ), 'Premium needs an approved claim' );
		$this->assertSame( CommercialStatus::Claimed, StatusResolver::resolve( true, false ) );
		$this->assertSame( CommercialStatus::Premium, StatusResolver::resolve( true, true ) );
		$this->assertFalse( StatusResolver::resolve( true, true )->is_paid_placement(), 'A profile status is never a paid placement' );
	}

	private static function placement( array $overrides = array() ): array {
		return array_merge(
			array(
				'product'     => 'sponsored',
				'entity_type' => 'lawyer',
				'entity_id'   => '7',
				'ranking_id'  => '9',
				'starts_at'   => '2026-10-01',
				'ends_at'     => '2026-11-01',
			),
			$overrides
		);
	}

	public function testValidPlacement(): void {
		$p = PlacementPolicy::validate( self::placement( array( 'location_term_id' => '5' ) ) );
		$this->assertSame( 'sponsored', $p['product'] );
		$this->assertSame( 9, $p['ranking_id'] );
		$this->assertSame( 0, $p['location_term_id'], 'Scope fields of other products are dropped' );
		$this->assertSame( '2026-10-01 00:00:00', $p['starts_at'] );
		$this->assertSame( 'active', $p['status'] );
	}

	public static function invalidPlacements(): array {
		return array(
			'unknown product'         => array( array( 'product' => 'boost' ), 'product' ),
			'sponsored w/o ranking'   => array( array( 'ranking_id' => '' ), 'ranking_id' ),
			'featured w/o page'       => array( array( 'product' => 'featured' ), 'location_term_id' ),
			'featured with both'      => array(
				array(
					'product'               => 'featured',
					'location_term_id'      => 3,
					'practice_area_term_id' => 4,
				),
				'location_term_id',
			),
			'ends before start'       => array( array( 'ends_at' => '2026-09-30' ), 'ends_at' ),
			'more than a year'        => array( array( 'ends_at' => '2027-12-01' ), 'ends_at' ),
			'bad date'                => array( array( 'starts_at' => '2026-02-30' ), 'starts_at' ),
			'message on sponsored'    => array( array( 'premium_message' => 'Hi' ), 'premium_message' ),
			'link in premium message' => array(
				array(
					'product'         => 'premium',
					'premium_message' => 'Visit www.example.com',
				),
				'premium_message',
			),
			'http cta'                => array(
				array(
					'product' => 'premium',
					'cta_url' => 'http://example.com',
				),
				'cta_url',
			),
			'bad status'              => array( array( 'status' => 'live' ), 'status' ),
			'no entity'               => array( array( 'entity_id' => '' ), 'entity_id' ),
		);
	}

	#[DataProvider( 'invalidPlacements' )]
	public function testInvalidPlacementsAreRejected( array $overrides, string $field ): void {
		try {
			PlacementPolicy::validate( self::placement( $overrides ) );
			$this->fail( 'Expected a validation error for ' . $field );
		} catch ( ValidationException $e ) {
			$this->assertSame( $field, $e->field_key );
		}
	}

	public function testLiveWindowIsHalfOpen(): void {
		$row = array(
			'status'    => 'active',
			'starts_at' => '2026-10-01 00:00:00',
			'ends_at'   => '2026-11-01 00:00:00',
		);
		$at  = static fn( string $t ): \DateTimeImmutable => new \DateTimeImmutable( $t, new \DateTimeZone( 'UTC' ) );
		$this->assertFalse( PlacementPolicy::is_live( $row, $at( '2026-09-30 23:59:59' ) ) );
		$this->assertTrue( PlacementPolicy::is_live( $row, $at( '2026-10-01 00:00:00' ) ) );
		$this->assertFalse( PlacementPolicy::is_live( $row, $at( '2026-11-01 00:00:00' ) ) );
		$this->assertFalse( PlacementPolicy::is_live( array( 'status' => 'paused' ) + $row, $at( '2026-10-15' ) ) );
	}

	public function testSelectionIsDeterministicDedupedAndCapped(): void {
		$now  = new \DateTimeImmutable( '2026-10-15', new \DateTimeZone( 'UTC' ) );
		$row  = static fn( int $id, int $entity, string $start, string $status = 'active' ): array => array(
			'placement_id' => $id,
			'entity_id'    => $entity,
			'starts_at'    => $start . ' 00:00:00',
			'ends_at'      => '2026-12-01 00:00:00',
			'status'       => $status,
		);
		$rows = array(
			$row( 5, 50, '2026-10-10' ),
			$row( 1, 10, '2026-10-02' ),
			$row( 2, 10, '2026-10-01' ),
			$row( 3, 30, '2026-10-20' ),
			$row( 4, 40, '2026-10-01', 'paused' ),
			$row( 6, 60, '2026-10-11' ),
		);
		$ids  = static fn( array $out ): array => array_column( $out, 'placement_id' );
		$this->assertSame( array( 2, 5 ), $ids( PlacementPolicy::select( $rows, $now, 2 ) ), 'Oldest booking first, one per profile, capped' );
		$this->assertSame( $ids( PlacementPolicy::select( array_reverse( $rows ), $now, 5 ) ), $ids( PlacementPolicy::select( $rows, $now, 5 ) ), 'Input order does not matter' );
		$this->assertSame( array(), PlacementPolicy::select( $rows, $now, 0 ) );
	}

	public function testEligibility(): void {
		$ok = array(
			'published'  => true,
			'claimed'    => true,
			'bar_status' => 'active',
			'in_scope'   => true,
		);
		$this->assertSame( array(), PlacementPolicy::ineligibility( $ok ) );
		$this->assertSame( array(), PlacementPolicy::ineligibility( array( 'bar_status' => null ) + $ok ) );
		$this->assertCount( 1, PlacementPolicy::ineligibility( array( 'claimed' => false ) + $ok ) );
		$this->assertCount( 1, PlacementPolicy::ineligibility( array( 'bar_status' => 'suspended' ) + $ok ) );
		$this->assertCount(
			4,
			PlacementPolicy::ineligibility(
				array(
					'published'  => false,
					'claimed'    => false,
					'bar_status' => 'disbarred',
					'in_scope'   => false,
				)
			)
		);
	}

	public function testScope(): void {
		// Entity in Miami (2) under Florida (1), practising personal injury (7).
		$this->assertTrue( PlacementPolicy::in_scope( array( 2, 1 ), array( 7 ), 1, 7 ), 'A city profile belongs on its state ranking' );
		$this->assertTrue( PlacementPolicy::in_scope( array( 2, 1 ), array( 7 ), 2, 0 ) );
		$this->assertFalse( PlacementPolicy::in_scope( array( 2, 1 ), array( 7 ), 3, 7 ) );
		$this->assertFalse( PlacementPolicy::in_scope( array( 2, 1 ), array( 7 ), 2, 8 ) );
	}

	public function testProducts(): void {
		$this->assertFalse( Product::Premium->is_listing() );
		$this->assertSame( 'Sponsored', Product::Sponsored->label() );
		$this->assertSame( array( 'premium', 'featured', 'sponsored' ), Product::values() );
	}

	/**
	 * The organic ranking must not be able to see commercial data: no code in
	 * src/Ranking refers to the commercial namespace, tables or status field.
	 * (Comments are ignored; they may explain the rule.)
	 */
	public function testRankingCodeNeverReadsCommercialData(): void {
		$files = glob( dirname( __DIR__, 2 ) . '/src/Ranking/*.php' );
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
				if ( ! is_array( $token ) || in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$this->assertDoesNotMatchRegularExpression( '/commercial|placement|profile_claim|sponsor|premium|featured/i', $token[1], basename( $file ) . ' must not reference commercial data' );
			}
		}
	}
}
