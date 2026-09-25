<?php
/**
 * Settings, source tiers and claim validation tests.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Schema\ValidationException;
use LexRanked\Core\Security\AuditLog;
use LexRanked\Core\Security\RateLimiter;
use LexRanked\Core\Settings\Settings;
use LexRanked\Core\Sources\ClaimValidator;
use LexRanked\Core\Sources\SourceTiers;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase {

	public function testSettingsFallBackToDefaults(): void {
		$this->assertSame( Settings::defaults(), Settings::sanitize( 'garbage' ) );
		$this->assertSame( Settings::defaults(), Settings::sanitize( array( 'frontend_url' => 'javascript:alert(1)' ) ) );
	}

	public function testSettingsParseEditorInput(): void {
		$s = Settings::sanitize(
			array(
				'frontend_url'           => 'https://lexranked.com/',
				'headless_redirect'      => '1',
				'source_tiers'           => "official_registry = 1\nmy_directory = 3\nbad line\nx = 9",
				'freshness_rules'        => "bar_status = 14\nreview_data = 0",
				'required_verifications' => array(
					'lawyer'   => 'license, bar_status, made_up',
					'law_firm' => '',
				),
				'rate_limit_per_minute'  => '999999',
			)
		);
		$this->assertSame( 'https://lexranked.com', $s['frontend_url'] );
		$this->assertTrue( $s['headless_redirect'] );
		$this->assertSame(
			array(
				'official_registry' => 1,
				'my_directory'      => 3,
			),
			$s['source_tiers']
		);
		$this->assertSame(
			array(
				'bar_status' => 14,
				'profile'    => 90,
			),
			$s['freshness_rules']
		);
		$this->assertSame( array( 'license', 'bar_status' ), $s['required_verifications']['lawyer'] );
		$this->assertSame( array(), $s['required_verifications']['law_firm'] );
		$this->assertSame( 10000, $s['rate_limit_per_minute'] );
	}

	public function testSourceTiersAreConfigurable(): void {
		$tiers = new SourceTiers( array( 'my_registry' => 1 ) );
		$this->assertSame( 1, $tiers->tier_for( 'my_registry' ) );
		// Unknown types are never trusted more than the lowest tier.
		$this->assertSame( SourceTiers::LOWEST_TIER, $tiers->tier_for( 'official_registry' ) );
		$this->assertSame( "a = 1\nb = 2", SourceTiers::format( SourceTiers::parse( "a=1\n b = 2 " ) ) );
	}

	private function validator(): ClaimValidator {
		return new ClaimValidator( array( 'lawyer' => array( 'bar_status', 'rating' ) ), new SourceTiers() );
	}

	private static function claim( array $overrides = array() ): array {
		return array_merge(
			array(
				'entity_id'    => 12,
				'entity_type'  => 'lawyer',
				'field_name'   => 'bar_status',
				'value'        => 'active',
				'source_url'   => 'https://example.com/registry',
				'source_type'  => 'official_registry',
				'retrieved_at' => '2026-09-23T10:00:00+02:00',
				'confidence'   => 0.99,
			),
			$overrides
		);
	}

	public function testValidClaimIsNormalized(): void {
		$row = $this->validator()->validate( self::claim() );
		$this->assertSame( '2026-09-23 08:00:00', $row['retrieved_at'] );
		$this->assertSame( '"active"', $row['value'] );
		$this->assertSame( 'pending', $row['verification_status'] );
		$this->assertNull( $row['source_id'] );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function invalidClaims(): array {
		return array(
			'no source'         => array(
				array(
					'source_url' => '',
					'source_id'  => null,
				),
				'source',
			),
			'untraceable field' => array( array( 'field_name' => 'bio' ), 'field_name' ),
			'confidence > 1'    => array( array( 'confidence' => 1.2 ), 'confidence' ),
			'unknown source'    => array( array( 'source_type' => 'gossip' ), 'source_type' ),
			'bad url'           => array( array( 'source_url' => 'example.com' ), 'source_url' ),
			'empty value'       => array( array( 'value' => '' ), 'value' ),
			'bad entity'        => array( array( 'entity_type' => 'judge' ), 'entity_type' ),
			'missing retrieval' => array( array( 'retrieved_at' => '' ), 'retrieved_at' ),
			'bad status'        => array( array( 'verification_status' => 'probably' ), 'verification_status' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalidClaims' )]
	public function testInvalidClaimsAreRejected( array $overrides, string $field ): void {
		try {
			$this->validator()->validate( self::claim( $overrides ) );
			$this->fail( 'Expected rejection' );
		} catch ( ValidationException $e ) {
			$this->assertSame( $field, $e->field_key );
		}
	}

	public function testRateLimiterFixedWindow(): void {
		$store   = array();
		$limiter = new RateLimiter(
			static function ( string $key ) use ( &$store ): int {
				return $store[ $key ] ?? 0;
			},
			static function ( string $key, int $value, int $ttl ) use ( &$store ): void {
				$store[ $key ] = $ttl > 0 ? $value : 0;
			}
		);
		$now     = 1_790_000_040;
		$results = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$results[] = $limiter->hit( 'client', 3, 60, $now )['allowed'];
		}
		$this->assertSame( array( true, true, true, false ), $results );
		$this->assertTrue( $limiter->hit( 'other-client', 3, 60, $now )['allowed'] );
		// Next window resets the budget.
		$this->assertTrue( $limiter->hit( 'client', 3, 60, $now + 60 )['allowed'] );
	}

	public function testClientKeyNeverContainsTheRawIp(): void {
		$key = RateLimiter::client_key( array( 'REMOTE_ADDR' => '203.0.113.7' ), false, 'salt' );
		$this->assertStringNotContainsString( '203.0.113.7', $key );
		$this->assertSame( 64, strlen( $key ) );
		// Proxy header is ignored unless explicitly trusted.
		$spoofed = array(
			'REMOTE_ADDR'           => '203.0.113.7',
			'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
		);
		$this->assertSame( $key, RateLimiter::client_key( $spoofed, false, 'salt' ) );
		$this->assertNotSame( $key, RateLimiter::client_key( $spoofed, true, 'salt' ) );
	}

	public function testAuditLogRedactsSecrets(): void {
		$this->assertSame(
			array(
				'user'     => 'admin',
				'password' => '[redacted]',
				'nested'   => array( 'api_key' => '[redacted]' ),
			),
			AuditLog::redact(
				array(
					'user'     => 'admin',
					'password' => 'hunter2',
					'nested'   => array( 'api_key' => 'sk-123' ),
				)
			)
		);
	}
}
