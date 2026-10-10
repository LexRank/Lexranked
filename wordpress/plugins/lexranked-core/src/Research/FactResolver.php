<?php
/**
 * Resolves field values from evidence claims.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

use LexRanked\Core\Sources\SourceTiers;

/**
 * Picks the value of each field from its evidence claims (data-model rule):
 * best source tier, then verification status, then most recent, then
 * highest confidence. Failed claims are ignored. When the best claims of the
 * best tier disagree, the field is flagged as a conflict and NOT applied -
 * conflicts go to human review instead of being guessed.
 */
final class FactResolver {

	private const STATUS_RANK = array(
		'verified' => 3,
		'pending'  => 2,
		'expired'  => 1,
	);

	/**
	 * Constructor.
	 *
	 * @param SourceTiers $tiers Configured tiers.
	 */
	public function __construct( private readonly SourceTiers $tiers ) {
	}

	/**
	 * Resolve all fields.
	 *
	 * @param array<int, array<string, mixed>> $claims Claims (ClaimRepository::hydrate format).
	 * @return array<string, array{value: mixed, claim_id: int, tier: int, conflict: bool, alternatives: array<int, mixed>}>
	 */
	public function resolve( array $claims ): array {
		$by_field = array();
		foreach ( $claims as $claim ) {
			if ( 'failed' === $claim['verification_status'] ) {
				continue;
			}
			$by_field[ $claim['field_name'] ][] = $claim;
		}
		ksort( $by_field );

		$out = array();
		foreach ( $by_field as $field => $items ) {
			usort( $items, fn( array $a, array $b ): int => $this->sort_key( $a ) <=> $this->sort_key( $b ) );
			$best      = $items[0];
			$best_tier = $this->tiers->tier_for( $best['source_type'] );

			// Distinct values among claims of the best tier with the best status.
			$peers  = array_filter(
				$items,
				fn( array $c ): bool => $this->tiers->tier_for( $c['source_type'] ) === $best_tier
					&& ( self::STATUS_RANK[ $c['verification_status'] ] ?? 0 ) === ( self::STATUS_RANK[ $best['verification_status'] ] ?? 0 )
			);
			$values = array();
			foreach ( $peers as $peer ) {
				// Compare normalised values: "(305) 555-0101" and "+1 305 555 0101" agree.
				$values[ self::fingerprint( $peer['value_normalized'] ?? $peer['value'] ) ] = $peer['value'];
			}

			$out[ $field ] = array(
				'value'        => $best['value'],
				'claim_id'     => (int) $best['claim_id'],
				'tier'         => $best_tier,
				'conflict'     => count( $values ) > 1,
				'alternatives' => array_values( $values ),
			);
		}//end foreach
		return $out;
	}

	/**
	 * Sort key: tier ASC, status DESC, retrieved DESC, confidence DESC, id ASC.
	 *
	 * @param array<string, mixed> $c Claim.
	 * @return array<int, mixed>
	 */
	private function sort_key( array $c ): array {
		return array(
			$this->tiers->tier_for( $c['source_type'] ),
			-( self::STATUS_RANK[ $c['verification_status'] ] ?? 0 ),
			-strtotime( (string) $c['retrieved_at'] ),
			- (float) $c['confidence'],
			(int) $c['claim_id'],
		);
	}

	/**
	 * Comparable fingerprint of a value (case/whitespace-insensitive for strings).
	 *
	 * @param mixed $value Value.
	 */
	public static function fingerprint( mixed $value ): string {
		if ( is_string( $value ) ) {
			return 's:' . strtolower( trim( (string) preg_replace( '/\s+/', ' ', $value ) ) );
		}
		return 'j:' . json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-independent by design.
	}
}
