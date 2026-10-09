<?php
/**
 * Contextual ranking discovery (Etap F).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

/**
 * Which "best for" contexts the data could support under a ranking: every
 * case type, client type and language found in the entities' facts, with how
 * many entities qualify, how many by a verified fact, and whether that meets
 * the threshold. It starts from the data, never from a keyword list, and it
 * only reports: an editor decides whether to create the ranking. Pure.
 */
final class ContextDiscovery {

	/**
	 * Suggest contexts.
	 *
	 * @param array<int, array<string, array<string, mixed>>> $facts_by_entity Facts keyed by entity ID, then attribute.
	 * @param int                                             $min_entities    Minimum qualified entities.
	 * @param int                                             $min_verified    Minimum verified.
	 * @param array<int, string>                              $case_types      Sub-area slugs of the ranking's practice area.
	 * @return array<int, array<string, mixed>> Sorted: eligible first, then by qualified count.
	 */
	public static function suggest( array $facts_by_entity, int $min_entities, int $min_verified = ContextEligibility::MIN_VERIFIED, array $case_types = array() ): array {
		$values = array();
		foreach ( $facts_by_entity as $facts ) {
			foreach ( RankingQualifier::TYPES as $type ) {
				foreach ( (array) ( $facts[ RankingQualifier::attribute_for( $type ) ]['value'] ?? array() ) as $item ) {
					if ( is_scalar( $item ) && '' !== RankingQualifier::slug( (string) $item ) ) {
						$values[ $type ][ RankingQualifier::slug( (string) $item ) ] = true;
					}
				}
			}
		}

		$out = array();
		foreach ( $values as $type => $slugs ) {
			foreach ( array_keys( $slugs ) as $slug ) {
				$qualifier = RankingQualifier::from_fields( $type, $slug );
				if ( null === $qualifier ) {
					continue;
					// An unknown client type.
				}
				$qualified = 0;
				$verified  = 0;
				foreach ( $facts_by_entity as $facts ) {
					$evidence = $qualifier->qualify( $facts );
					if ( null !== $evidence ) {
						++$qualified;
						$verified += RankingQualifier::confirms( $evidence ) ? 1 : 0;
					}
				}
				$problem = RankingQualifier::CASE_TYPE === $type && ! in_array( $slug, $case_types, true ) ? 'Not a sub-area of the practice area in the taxonomy.' : null;
				$out[]   = array(
					'type'        => $type,
					'value'       => $slug,
					'segment'     => $qualifier->segment(),
					'eligibility' => ContextEligibility::evaluate(
						array(
							'parent'    => count( $facts_by_entity ),
							'qualified' => $qualified,
							'verified'  => $verified,
						),
						$min_entities,
						$min_verified,
						$problem
					),
				);
			}//end foreach
		}//end foreach
		usort(
			$out,
			static fn( array $a, array $b ): int => array( $b['eligibility']['eligible'], $b['eligibility']['qualified'], $a['type'], $a['value'] )
				<=> array( $a['eligibility']['eligible'], $a['eligibility']['qualified'], $b['type'], $b['value'] )
		);
		return $out;
	}
}
