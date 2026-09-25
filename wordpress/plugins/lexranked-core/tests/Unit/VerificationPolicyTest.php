<?php
/**
 * Verification policy tests.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Verification\Freshness;
use LexRanked\Core\Verification\VerificationPolicy;
use PHPUnit\Framework\TestCase;

final class VerificationPolicyTest extends TestCase {

	private \DateTimeImmutable $now;

	protected function setUp(): void {
		$this->now = new \DateTimeImmutable( '2026-09-23T12:00:00Z' );
	}

	private static function rec( string $type, string $status, ?string $verified_at = '2026-09-01T00:00:00Z', ?string $expires_at = null ): array {
		return array(
			'type'        => $type,
			'status'      => $status,
			'verified_at' => $verified_at,
			'expires_at'  => $expires_at,
		);
	}

	public function testVerifiedOnlyWhenAllRequiredTypesPass(): void {
		$policy = new VerificationPolicy( array( 'identity', 'license', 'bar_status' ) );

		$partial = $policy->evaluate( array( self::rec( 'identity', 'verified' ), self::rec( 'license', 'verified' ) ), $this->now );
		$this->assertSame( VerificationPolicy::UNVERIFIED, $partial['status'] );

		$all = $policy->evaluate(
			array(
				self::rec( 'identity', 'verified', '2026-09-10T00:00:00Z' ),
				self::rec( 'license', 'verified', '2026-09-05T00:00:00Z' ),
				self::rec( 'bar_status', 'verified', '2026-09-20T00:00:00Z' ),
			),
			$this->now
		);
		$this->assertSame( 'verified', $all['status'] );
		// A profile is only as fresh as its oldest required verification.
		$this->assertSame( '2026-09-05T00:00:00Z', $all['verified_at'] );
	}

	public function testFailedRequiredCheckFailsProfile(): void {
		$policy = new VerificationPolicy( array( 'identity', 'bar_status' ) );
		$result = $policy->evaluate( array( self::rec( 'identity', 'verified' ), self::rec( 'bar_status', 'failed' ) ), $this->now );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertNull( $result['verified_at'] );
	}

	public function testExpiryIsEvaluatedAgainstTheClock(): void {
		$policy  = new VerificationPolicy( array( 'license' ) );
		$records = array( self::rec( 'license', 'verified', '2026-01-01T00:00:00Z', '2026-09-01T00:00:00Z' ) );
		$this->assertSame( 'expired', $policy->evaluate( $records, $this->now )['status'] );
		$this->assertSame( 'verified', $policy->evaluate( $records, new \DateTimeImmutable( '2026-08-01T00:00:00Z' ) )['status'] );
	}

	public function testNewerVerifiedRecordSupersedesExpiredOne(): void {
		$policy = new VerificationPolicy( array( 'license' ) );
		$result = $policy->evaluate(
			array(
				self::rec( 'license', 'verified', '2025-01-01T00:00:00Z', '2025-06-01T00:00:00Z' ),
				self::rec( 'license', 'verified', '2026-09-01T00:00:00Z', '2027-09-01T00:00:00Z' ),
			),
			$this->now
		);
		$this->assertSame( 'verified', $result['status'] );
		$this->assertSame( array( 'license' => 'verified' ), $result['types'] );
	}

	public function testPendingWhenChecksAreInProgress(): void {
		$policy = new VerificationPolicy( array( 'identity', 'license' ) );
		$result = $policy->evaluate( array( self::rec( 'identity', 'verified' ), self::rec( 'license', 'pending', null ) ), $this->now );
		$this->assertSame( 'pending', $result['status'] );
	}

	public function testNoRequirementsNeverMeansVerified(): void {
		$policy = new VerificationPolicy( array() );
		$this->assertSame( VerificationPolicy::UNVERIFIED, $policy->evaluate( array( self::rec( 'identity', 'verified' ) ), $this->now )['status'] );
	}

	public function testUnparseableExpiryIsTreatedAsExpired(): void {
		$this->assertSame(
			'expired',
			VerificationPolicy::effective_status(
				array(
					'status'     => 'verified',
					'expires_at' => 'garbage',
				),
				$this->now
			)
		);
	}

	public function testFreshness(): void {
		$freshness = new Freshness(
			array(
				'bar_status' => 30,
				'profile'    => 90,
			)
		);
		$fresh     = $freshness->evaluate( 'bar_status', '2026-09-01T00:00:00Z', $this->now );
		$this->assertFalse( $fresh['isStale'] );
		$this->assertSame( '2026-10-01T00:00:00Z', $fresh['staleAt'] );

		$this->assertTrue( $freshness->evaluate( 'bar_status', '2026-08-01T00:00:00Z', $this->now )['isStale'] );
		$this->assertTrue( $freshness->evaluate( 'profile', null, $this->now )['isStale'] );
		// Unknown category falls back to the profile rule.
		$this->assertSame( 90, $freshness->evaluate( 'unknown', null, $this->now )['maxAgeDays'] );
	}
}
