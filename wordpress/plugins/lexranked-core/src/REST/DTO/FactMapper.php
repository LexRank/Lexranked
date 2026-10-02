<?php
/**
 * Fact DTO mapping.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST\DTO;

use LexRanked\Core\Attribute\Attributes;
use LexRanked\Core\Sources\SourceTiers;
use LexRanked\Core\Verification\Freshness;

/**
 * Normalised facts of an entity, each with the source it rests on and its
 * own freshness ("Bar status → Florida Bar → verified Sep 27"). Pure.
 */
final class FactMapper {

	/**
	 * Map fact rows.
	 *
	 * @param array<string, array<string, mixed>> $facts     Fact rows keyed by attribute (FactService::for_entity).
	 * @param array<int, array<string, mixed>>    $claims    Claims keyed by claim ID (hydrated).
	 * @param array<int, array<string, mixed>>    $sources   Source DTOs keyed by source ID.
	 * @param Freshness                           $freshness Freshness rules.
	 * @param \DateTimeImmutable                  $now       Now.
	 * @return array<int, array<string, mixed>>
	 */
	public static function facts( array $facts, array $claims, array $sources, Freshness $freshness, \DateTimeImmutable $now ): array {
		$out = array();
		foreach ( Attributes::facts() as $key => $attribute ) {
			$fact = $facts[ $key ] ?? null;
			if ( null === $fact ) {
				continue;
			}
			$claim    = $claims[ (int) $fact['claim_id'] ] ?? null;
			$source   = null === $fact['source_id'] ? null : ( $sources[ (int) $fact['source_id'] ] ?? null );
			$type     = null === $claim ? ( $source['type'] ?? null ) : $claim['source_type'];
			$observed = self::iso( (string) $fact['observed_at'] );
			$verified = null === $fact['verified_at'] ? null : self::iso( (string) $fact['verified_at'] );
			$out[]    = array(
				'attribute'  => $key,
				'label'      => $attribute->label,
				'category'   => $attribute->category,
				'value'      => $fact['value'],
				'unit'       => $attribute->unit,
				'status'     => (string) $fact['status'],
				'confidence' => round( (float) $fact['confidence'], 3 ),
				'source'     => array(
					'id'        => $source['id'] ?? null,
					'name'      => $source['name'] ?? null,
					'publisher' => $source['publisher'] ?? null,
					'url'       => null !== $claim && '' !== $claim['source_url'] ? $claim['source_url'] : ( $source['url'] ?? null ),
					'type'      => $type,
					'tier'      => (int) $fact['source_tier'],
					'tierLabel' => SourceTiers::label( (int) $fact['source_tier'] ),
				),
				'claimCount' => (int) $fact['claim_count'],
				'observedAt' => $observed,
				'verifiedAt' => $verified,
				'freshness'  => $freshness->evaluate( $attribute->freshness, $verified ?? $observed, $now ),
				'method'     => $claim['method'] ?? null,
			);
		}//end foreach
		return $out;
	}

	/**
	 * MySQL datetime → ISO 8601 UTC.
	 *
	 * @param string $value Datetime.
	 */
	private static function iso( string $value ): string {
		return str_replace( ' ', 'T', substr( $value, 0, 19 ) ) . 'Z';
	}
}
