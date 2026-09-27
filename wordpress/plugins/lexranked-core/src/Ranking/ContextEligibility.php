<?php
/**
 * Context ranking eligibility (Etap F).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

/**
 * Whether a contextual ranking ("Best Personal Injury Lawyers in Miami for Car
 * Accidents") may exist as a page. A keyword alone never creates one: the
 * database has to confirm the context for enough entities, enough of them by
 * verified facts, and the result has to differ from the broader ranking.
 * Etap G generalises these checks into the page eligibility engine. Pure.
 */
final class ContextEligibility {

	/** Default minimum entities whose context is confirmed by a verified fact. */
	public const MIN_VERIFIED = 3;

	/**
	 * Evaluate.
	 *
	 * @param array{parent: int, qualified: int, verified: int}|null $stats        Counts from the latest calculation, or null when never calculated.
	 * @param int                                                    $min_entities Minimum qualified entities.
	 * @param int                                                    $min_verified Minimum qualified entities with a verified fact.
	 * @param string|null                                            $problem      Definition problem (e.g. a case type outside the practice area).
	 * @return array{eligible: bool, reasons: array<int, string>, qualified: int, verified: int, parentCount: int, minEntities: int, minVerified: int}
	 */
	public static function evaluate( ?array $stats, int $min_entities, int $min_verified = self::MIN_VERIFIED, ?string $problem = null ): array {
		$parent    = (int) ( $stats['parent'] ?? 0 );
		$qualified = (int) ( $stats['qualified'] ?? 0 );
		$verified  = (int) ( $stats['verified'] ?? 0 );
		$reasons   = array();

		if ( null !== $problem ) {
			$reasons[] = $problem;
		}
		if ( null === $stats ) {
			$reasons[] = 'Not calculated yet.';
		} else {
			if ( $qualified < $min_entities ) {
				$reasons[] = sprintf( '%d of the required %d entities have this context on record.', $qualified, $min_entities );
			}
			if ( $verified < $min_verified ) {
				$reasons[] = sprintf( '%d of the required %d entities have it confirmed by a verified fact.', $verified, $min_verified );
			}
			if ( $qualified > 0 && $qualified >= $parent ) {
				$reasons[] = 'Every entity in the broader ranking qualifies, so the page would repeat it.';
			}
		}

		return array(
			'eligible'    => array() === $reasons,
			'reasons'     => $reasons,
			'qualified'   => $qualified,
			'verified'    => $verified,
			'parentCount' => $parent,
			'minEntities' => $min_entities,
			'minVerified' => $min_verified,
		);
	}
}
