<?php
/**
 * Research engine pure-logic tests.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Research\Backoff;
use LexRanked\Core\Research\CandidateNormalizer;
use LexRanked\Core\Research\FactResolver;
use LexRanked\Core\Research\VerificationRules;
use LexRanked\Core\Schema\ValidationException;
use LexRanked\Core\Sources\SourceTiers;
use PHPUnit\Framework\TestCase;

final class ResearchTest extends TestCase {

	public function testBackoffIsExponentialAndCapped(): void {
		$b = new Backoff( 300, 3600, 3 );
		$this->assertSame( array( 300, 600, 1200, 2400, 3600, 3600 ), array_map( array( $b, 'delay' ), array( 1, 2, 3, 4, 5, 9 ) ) );
		$this->assertTrue( $b->can_retry( 2 ) );
		$this->assertFalse( $b->can_retry( 3 ) );
	}

	public function testNamesNormalizeForMatching(): void {
		$this->assertSame( 'john smith', CandidateNormalizer::name( 'John A. Smith, Esq.', 'lawyer' ) );
		$this->assertSame( 'john smith', CandidateNormalizer::name( 'JOHN SMITH', 'lawyer' ) );
		$this->assertSame( 'jose martinez', CandidateNormalizer::name( 'José Martínez', 'lawyer' ) );
		$this->assertSame( 'smith jones', CandidateNormalizer::name( 'The Smith & Jones Law Group, P.A.', 'law_firm' ) );
		$this->assertSame(
			CandidateNormalizer::dedupe_key( 'lawyer', 'John A. Smith, Esq.', 'miami', 'florida' ),
			CandidateNormalizer::dedupe_key( 'lawyer', 'john smith', 'miami', 'florida' )
		);
		$this->assertNotSame(
			CandidateNormalizer::dedupe_key( 'lawyer', 'John Smith', 'miami', 'florida' ),
			CandidateNormalizer::dedupe_key( 'lawyer', 'John Smith', 'tampa', 'florida' )
		);
		$this->assertSame( 'smithlaw.com', CandidateNormalizer::domain( 'https://WWW.SmithLaw.com/team?x=1' ) );
		$this->assertNull( CandidateNormalizer::domain( null ) );
	}

	private static function claim( int $id, string $field, mixed $value, string $type, string $status = 'pending', string $at = '2026-09-01T00:00:00Z', float $confidence = 0.9 ): array {
		return array(
			'claim_id'            => $id,
			'field_name'          => $field,
			'value'               => $value,
			'source_type'         => $type,
			'verification_status' => $status,
			'retrieved_at'        => $at,
			'confidence'          => $confidence,
		);
	}

	public function testResolverPrefersAuthoritativeSources(): void {
		$resolved = ( new FactResolver( new SourceTiers() ) )->resolve(
			array(
				self::claim( 1, 'bar_status', 'inactive', 'professional_directory', 'pending', '2026-09-20T00:00:00Z' ),
				self::claim( 2, 'bar_status', 'active', 'official_registry', 'verified', '2026-08-01T00:00:00Z' ),
				self::claim( 3, 'rating', 4.8, 'review_platform' ),
				self::claim( 4, 'rating', 4.6, 'review_platform', 'pending', '2026-09-10T00:00:00Z' ),
				self::claim( 5, 'website', 'https://a.example', 'secondary', 'failed' ),
			)
		);
		$this->assertSame( 'active', $resolved['bar_status']['value'] );
		$this->assertSame( 1, $resolved['bar_status']['tier'] );
		$this->assertFalse( $resolved['bar_status']['conflict'] );
		// Same tier and status: the most recent claim wins, but disagreement is flagged.
		$this->assertSame( 4.6, $resolved['rating']['value'] );
		$this->assertTrue( $resolved['rating']['conflict'] );
		// Failed claims never resolve a value.
		$this->assertArrayNotHasKey( 'website', $resolved );
	}

	public function testResolverTreatsEquivalentStringsAsAgreement(): void {
		$resolved = ( new FactResolver( new SourceTiers() ) )->resolve(
			array(
				self::claim( 1, 'phone', '305-555-0100', 'official_website' ),
				self::claim( 2, 'phone', '305-555-0100 ', 'official_website' ),
			)
		);
		$this->assertFalse( $resolved['phone']['conflict'] );
	}

	public function testResolverIsDeterministic(): void {
		$claims   = array(
			self::claim( 2, 'rating', 4.6, 'review_platform' ),
			self::claim( 1, 'rating', 4.8, 'review_platform' ),
		);
		$resolver = new FactResolver( new SourceTiers() );
		$this->assertSame( $resolver->resolve( $claims ), $resolver->resolve( array_reverse( $claims ) ) );
		$this->assertSame( 1, $resolver->resolve( $claims )['rating']['claim_id'] ); // Full tie → lowest claim ID.
	}

	public function testVerifiedRequiresAnAuthoritativeSource(): void {
		$this->assertSame( 'verified', VerificationRules::decide( 'bar_status', 'verified', 1 ) );
		$this->assertSame( 'pending', VerificationRules::decide( 'bar_status', 'verified', 3 ), 'A directory cannot verify bar status' );
		$this->assertSame( 'verified', VerificationRules::decide( 'website', 'verified', 2 ) );
		$this->assertSame( 'failed', VerificationRules::decide( 'license', 'failed', 3 ) );
		$this->expectException( ValidationException::class );
		VerificationRules::decide( 'bar_status', 'verified', null );
	}

	public function testVerificationRulesRejectUnknownValues(): void {
		$this->expectException( ValidationException::class );
		VerificationRules::decide( 'vibes', 'verified', 1 );
	}
}
