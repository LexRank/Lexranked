<?php
/**
 * Source / evidence DTO mapping.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST\DTO;

use LexRanked\Core\Sources\SourceTiers;

/**
 * Maps registered sources and evidence claims to public DTOs.
 */
final class SourceMapper {

	/**
	 * Source registry entry.
	 *
	 * @param array<string, mixed> $record Source record.
	 * @param SourceTiers          $tiers  Tier configuration.
	 * @return array{id: int, name: string, url: string|null, type: string|null, tier: int|null, isDemo: bool}
	 */
	public static function source( array $record, SourceTiers $tiers ): array {
		$type = $record['fields']['source_type'];
		return array(
			'id'     => $record['id'],
			'name'   => $record['title'],
			'url'    => $record['fields']['url'],
			'type'   => $type,
			'tier'   => null === $type ? null : $tiers->tier_for( $type ),
			'isDemo' => (bool) $record['fields']['is_demo'],
		);
	}

	/**
	 * Evidence for an entity: one item per claim, sorted by field then tier.
	 *
	 * @param array<int, array<string, mixed>> $claims  Claims (ClaimRepository::hydrate format).
	 * @param array<int, array<string, mixed>> $sources Source DTOs keyed by ID.
	 * @param SourceTiers                      $tiers   Tier configuration.
	 * @return array<int, array<string, mixed>>
	 */
	public static function evidence( array $claims, array $sources, SourceTiers $tiers ): array {
		$items = array();
		foreach ( $claims as $claim ) {
			$source  = null !== $claim['source_id'] ? ( $sources[ $claim['source_id'] ] ?? null ) : null;
			$items[] = array(
				'field'              => $claim['field_name'],
				'value'              => $claim['value'],
				'source'             => array(
					'id'   => $source['id'] ?? null,
					'name' => $source['name'] ?? null,
					'url'  => '' !== $claim['source_url'] ? $claim['source_url'] : ( $source['url'] ?? null ),
					'type' => $claim['source_type'],
					'tier' => $tiers->tier_for( $claim['source_type'] ),
				),
				'retrievedAt'        => $claim['retrieved_at'],
				'confidence'         => $claim['confidence'],
				'verificationStatus' => $claim['verification_status'],
			);
		}
		usort(
			$items,
			static fn( array $a, array $b ): int => array( $a['field'], $a['source']['tier'], $b['retrievedAt'] ) <=> array( $b['field'], $b['source']['tier'], $a['retrievedAt'] )
		);
		return $items;
	}
}
