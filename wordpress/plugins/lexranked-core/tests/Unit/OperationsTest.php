<?php
/**
 * Phase 8: signatures, health evaluation, cache keys.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Integration\Signature;
use LexRanked\Core\Monitoring\HealthCheck;
use LexRanked\Core\Support\ResponseCache;
use PHPUnit\Framework\TestCase;

final class OperationsTest extends TestCase {

	private const SECRET = 'test-secret-with-at-least-thirty-two-bytes!';

	public function testSignatureRoundTripAndTampering(): void {
		$body   = '{"tags":["lexranked"],"paths":["/"]}';
		$header = Signature::header( self::SECRET, 1_800_000_000, $body );
		$this->assertMatchesRegularExpression( '/^t=1800000000,v1=[0-9a-f]{64}$/', $header );
		$this->assertTrue( Signature::verify( self::SECRET, $header, $body, 1_800_000_100 ) );
		$this->assertFalse( Signature::verify( self::SECRET, $header, $body . ' ', 1_800_000_100 ), 'body tampered' );
		$this->assertFalse( Signature::verify( 'another-secret-with-at-least-thirty-two-b', $header, $body, 1_800_000_100 ), 'wrong secret' );
		$this->assertFalse( Signature::verify( self::SECRET, $header, $body, 1_800_000_000 + 301 ), 'replayed too late' );
		$this->assertFalse( Signature::verify( self::SECRET, 'garbage', $body, 1_800_000_000 ) );
	}

	public function testSignatureMatchesTheFrontendVector(): void {
		// Shared test vector with frontend/tests/revalidate.test.ts.
		$this->assertSame( 't=1700000000,v1=' . hash_hmac( 'sha256', '1700000000.{"a":1}', 'x' ), Signature::header( 'x', 1_700_000_000, '{"a":1}' ) );
		$this->assertFalse( Signature::usable( 'short' ) );
		$this->assertTrue( Signature::usable( self::SECRET ) );
	}

	private static function facts( array $overrides = array() ): array {
		return $overrides + array(
			'schema_version'          => '5',
			'expected_schema'         => '5',
			'cron_next'               => 3600,
			'cron_disabled'           => false,
			'last_calculation'        => '2026-09-26T06:00:00Z',
			'published_rankings'      => 1,
			'jobs_stuck'              => 0,
			'jobs_failed'             => 0,
			'review_claims'           => 2,
			'review_candidates'       => 1,
			'revalidation_configured' => true,
			'revalidation'            => array(
				'state'   => 'ok',
				'message' => 'Frontend refreshed.',
				'attempt' => 1,
				'at'      => '2026-09-26T10:00:00Z',
			),
			'debug_display'           => false,
		);
	}

	public function testHealthyInstallIsOk(): void {
		$report = HealthCheck::evaluate( self::facts(), new \DateTimeImmutable( '2026-09-26T12:00:00Z' ) );
		$this->assertSame( 'ok', $report['status'] );
		$this->assertSame( array( 'database', 'scheduler', 'scores', 'research', 'review_queue', 'revalidation', 'configuration' ), array_column( $report['checks'], 'key' ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
	 */
	public static function problems(): array {
		return array(
			'schema mismatch'    => array( array( 'schema_version' => '4' ), 'database', 'critical' ),
			'no cron'            => array( array( 'cron_next' => null ), 'scheduler', 'critical' ),
			'cron overdue'       => array( array( 'cron_next' => -7200 ), 'scheduler', 'warning' ),
			'stale scores'       => array( array( 'last_calculation' => '2026-09-24T10:00:00Z' ), 'scores', 'warning' ),
			'very stale scores'  => array( array( 'last_calculation' => '2026-09-20T10:00:00Z' ), 'scores', 'critical' ),
			'stuck jobs'         => array( array( 'jobs_stuck' => 1 ), 'research', 'warning' ),
			'no instant refresh' => array( array( 'revalidation_configured' => false ), 'revalidation', 'warning' ),
			'refresh failing'    => array(
				array(
					'revalidation' => array(
						'state'   => 'error',
						'message' => 'HTTP 401',
						'at'      => 'x',
					),
				),
				'revalidation',
				'warning',
			),
			'errors shown'       => array( array( 'debug_display' => true ), 'configuration', 'warning' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'problems' )]
	public function testProblemsAreReported( array $overrides, string $key, string $status ): void {
		$report = HealthCheck::evaluate( self::facts( $overrides ), new \DateTimeImmutable( '2026-09-26T12:00:00Z' ) );
		$check  = array_values( array_filter( $report['checks'], static fn( array $c ): bool => $c['key'] === $key ) )[0];
		$this->assertSame( $status, $check['status'] );
		$this->assertSame( $status, $report['status'] );
	}

	public function testCacheKeysAreOrderIndependentAndVersioned(): void {
		$a = ResponseCache::key(
			'/cities',
			array(
				'state'      => 'fl',
				'hide_empty' => true,
			),
			3
		);
		$b = ResponseCache::key(
			'/cities',
			array(
				'hide_empty' => true,
				'state'      => 'fl',
			),
			3
		);
		$this->assertSame( $a, $b );
		$this->assertNotSame(
			$a,
			ResponseCache::key(
				'/cities',
				array(
					'state'      => 'fl',
					'hide_empty' => true,
				),
				4
			)
		);
		$this->assertLessThanOrEqual( 172, strlen( $a ), 'Transient keys must fit the options table.' );
	}
}
