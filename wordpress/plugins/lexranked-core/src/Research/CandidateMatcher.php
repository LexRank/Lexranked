<?php
/**
 * Deterministic candidate → entity matching.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

/**
 * Decides whether a candidate is an existing lawyer/firm, a new one, or
 * needs a human. Rules only — no LLM, no scores learned from data — so the
 * same inputs always give the same decision and the reason is explainable.
 *
 * When in doubt the matcher says "review": a wrong merge corrupts a real
 * person's profile, a missed merge only costs an editor a click.
 *
 * Pure logic (no WordPress calls).
 */
final class CandidateMatcher {

	public const MATCH  = 'match';
	public const CREATE = 'create';
	public const REVIEW = 'review';

	/**
	 * Decide.
	 *
	 * @param array{entity_type: string, normalized_name: string, city: string|null, state: string|null, domain: string|null}                  $candidate Candidate.
	 * @param array<int, array{id: int, normalized_name: string, cities: array<int, string>, states: array<int, string>, domain: string|null}> $entities Existing entities of the same type
	 *        whose name, name key or domain could match (pre-filtered by the index).
	 * @return array{decision: string, entity_id: int|null, confidence: float|null, reason: string}
	 */
	public static function decide( array $candidate, array $entities ): array {
		usort( $entities, static fn( array $a, array $b ): int => $a['id'] <=> $b['id'] );
		$is_firm = 'law_firm' === $candidate['entity_type'];
		$city    = null === $candidate['city'] ? null : strtolower( $candidate['city'] );
		$state   = $candidate['state'];
		$domain  = $candidate['domain'];

		$same_state = static fn( array $e ): bool => null === $state || array() === $e['states'] || in_array( $state, $e['states'], true );
		$same_city  = static fn( array $e ): bool => null !== $city && in_array( $city, array_map( 'strtolower', $e['cities'] ), true );
		$same_dom   = static fn( array $e ): bool => null !== $domain && $domain === $e['domain'];

		// 1. Firms: a shared website domain in the same state is the strongest signal.
		if ( $is_firm && null !== $domain ) {
			$by_domain = array_values( array_filter( $entities, static fn( array $e ): bool => $same_dom( $e ) && $same_state( $e ) ) );
			if ( 1 === count( $by_domain ) ) {
				return self::result( self::MATCH, $by_domain[0]['id'], 0.95, 'same website domain in the same state' );
			}
			if ( count( $by_domain ) > 1 ) {
				return self::result( self::REVIEW, null, null, 'several firms share this website domain' );
			}
		}

		// 2. Exact normalized name.
		$by_name = array_values( array_filter( $entities, static fn( array $e ): bool => $e['normalized_name'] === $candidate['normalized_name'] ) );
		$local   = array_values( array_filter( $by_name, $same_state ) );
		if ( 1 === count( $local ) ) {
			$e = $local[0];
			if ( $same_city( $e ) || $same_dom( $e ) ) {
				return self::result( self::MATCH, $e['id'], $same_city( $e ) && $same_dom( $e ) ? 1.0 : 0.9, $same_city( $e ) ? 'same name in the same city' : 'same name and website domain' );
			}
			if ( null === $city && null === $domain ) {
				return self::result( self::REVIEW, $e['id'], 0.6, 'same name in the same state, no city or website to confirm' );
			}
			return self::result( self::REVIEW, $e['id'], 0.5, 'same name in the same state but a different city' );
		}
		if ( count( $local ) > 1 ) {
			return self::result( self::REVIEW, null, null, 'several existing profiles share this name' );
		}
		if ( array() !== $by_name ) {
			return self::result( self::REVIEW, $by_name[0]['id'], 0.4, 'same name exists in another state' );
		}

		// 3. Lawyers: same last name and first initial in the same city ("Jon" vs "Jonathan").
		if ( ! $is_firm && null !== $city ) {
			$key   = self::name_key( $candidate['normalized_name'] );
			$fuzzy = array_values( array_filter( $entities, static fn( array $e ): bool => self::name_key( $e['normalized_name'] ) === $key && $same_city( $e ) ) );
			if ( array() !== $fuzzy ) {
				return self::result( self::REVIEW, $fuzzy[0]['id'], 0.5, 'similar name (same last name and first initial) in the same city' );
			}
		}

		return self::result( self::CREATE, null, null, 'no existing profile matches' );
	}

	/**
	 * "last|first-initial" key used for the fuzzy lawyer rule and the index.
	 *
	 * @param string $normalized_name Normalized name.
	 */
	public static function name_key( string $normalized_name ): string {
		$words = explode( ' ', $normalized_name );
		if ( count( $words ) < 2 ) {
			return $normalized_name;
		}
		return end( $words ) . '|' . substr( $words[0], 0, 1 );
	}

	/**
	 * Result tuple.
	 *
	 * @param string     $decision   Decision.
	 * @param int|null   $entity_id  Entity ID.
	 * @param float|null $confidence Confidence.
	 * @param string     $reason     Human-readable reason.
	 * @return array{decision: string, entity_id: int|null, confidence: float|null, reason: string}
	 */
	private static function result( string $decision, ?int $entity_id, ?float $confidence, string $reason ): array {
		return array(
			'decision'   => $decision,
			'entity_id'  => $entity_id,
			'confidence' => $confidence,
			'reason'     => $reason,
		);
	}
}
