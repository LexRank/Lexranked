<?php
/**
 * Claims → facts.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Fact;

use LexRanked\Core\Attribute\Attributes;
use LexRanked\Core\Attribute\Normalizer;
use LexRanked\Core\Research\FactResolver;
use LexRanked\Core\Sources\SourceTiers;

/**
 * CLAIMS → VERIFIED / NORMALIZED FACTS. Pure.
 *
 * One fact per attribute: the value chosen by the FactResolver rules (best
 * source tier, then verification, recency, confidence), normalised, with its
 * status, the claim and source it rests on, and when it was observed and
 * verified. Conflicting sources of the best tier give status "conflict"
 * and are left for an editor; nothing is averaged or guessed.
 */
final class FactBuilder {

	public const VERIFIED   = 'verified';
	public const UNVERIFIED = 'unverified';
	public const CONFLICT   = 'conflict';

	/**
	 * Build facts.
	 *
	 * @param array<int, array<string, mixed>> $claims Approved claims (ClaimRepository::hydrate format).
	 * @param SourceTiers                      $tiers  Tier configuration.
	 * @return array<string, array{attribute: string, value: mixed, status: string, confidence: float, source_tier: int, claim_id: int, source_id: int|null, claim_count: int, observed_at: string, verified_at: string|null}>
	 */
	public static function build( array $claims, SourceTiers $tiers ): array {
		$claims   = array_values( array_filter( $claims, static fn( array $c ): bool => null !== Attributes::fact( (string) $c['field_name'] ) ) );
		$by_id    = array_column( $claims, null, 'claim_id' );
		$counts   = array_count_values( array_column( $claims, 'field_name' ) );
		$resolved = ( new FactResolver( $tiers ) )->resolve( $claims );
		$out      = array();
		foreach ( $resolved as $attribute => $choice ) {
			$best       = $by_id[ $choice['claim_id'] ];
			$definition = Attributes::fact( $attribute );
			$value      = $best['value_normalized'] ?? ( null === $definition ? null : Normalizer::normalize( $definition, $best['value'] ) );
			$status     = $choice['conflict'] ? self::CONFLICT : ( 'verified' === $best['verification_status'] ? self::VERIFIED : self::UNVERIFIED );
			$observed   = self::mysql( (string) $best['retrieved_at'] );

			$out[ $attribute ] = array(
				'attribute'   => $attribute,
				'value'       => $value ?? $best['value'],
				'status'      => $status,
				'confidence'  => (float) $best['confidence'],
				'source_tier' => (int) $choice['tier'],
				'claim_id'    => (int) $best['claim_id'],
				'source_id'   => $best['source_id'],
				'claim_count' => (int) ( $counts[ $attribute ] ?? 1 ),
				'observed_at' => $observed,
				'verified_at' => self::VERIFIED === $status ? $observed : null,
			);
		}
		return $out;
	}

	/**
	 * ISO (…T…Z) or MySQL datetime → MySQL datetime.
	 *
	 * @param string $value Datetime.
	 */
	public static function mysql( string $value ): string {
		return substr( str_replace( 'T', ' ', rtrim( $value, 'Z' ) ), 0, 19 );
	}
}
