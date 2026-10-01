<?php
/**
 * Candidate normalization and de-duplication keys.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

/**
 * Deterministic name normalization used for de-duplication and matching.
 * "John A. Smith, Esq." and "JOHN SMITH" normalize to comparable forms.
 */
final class CandidateNormalizer {

	private const PERSON_NOISE = array( 'esq', 'esquire', 'jd', 'attorney', 'atty', 'lawyer', 'mr', 'mrs', 'ms', 'dr', 'hon' );
	private const FIRM_NOISE   = array( 'llp', 'llc', 'pllc', 'pa', 'pc', 'inc', 'ltd', 'co', 'the', 'law', 'firm', 'group', 'office', 'offices', 'of', 'and', 'attorneys', 'at' );

	/**
	 * Normalized name: lowercase ASCII words without punctuation, titles or
	 * legal-entity suffixes; person middle initials removed.
	 *
	 * @param string $name        Raw name.
	 * @param string $entity_type lawyer|law_firm.
	 */
	public static function name( string $name, string $entity_type ): string {
		$ascii = self::ascii( $name );
		$ascii = str_replace( array( '&', '.' ), array( ' and ', '' ), $ascii );
		// "P.A." → "PA".
		$ascii = (string) preg_replace( '/[^a-z0-9 ]+/', ' ', strtolower( $ascii ) );
		$words = array_values( array_filter( explode( ' ', $ascii ), static fn( string $w ): bool => '' !== $w ) );
		$noise = 'law_firm' === $entity_type ? self::FIRM_NOISE : self::PERSON_NOISE;
		$words = array_values( array_filter( $words, static fn( string $w ): bool => ! in_array( $w, $noise, true ) ) );
		if ( 'lawyer' === $entity_type && count( $words ) > 2 ) {
			// Drop single-letter middle initials: "john a smith" → "john smith".
			$first = array_shift( $words );
			$last  = array_pop( $words );
			$words = array_merge( array( $first ), array_values( array_filter( $words, static fn( string $w ): bool => strlen( $w ) > 1 ) ), array( $last ) );
		}
		return implode( ' ', $words );
	}

	/**
	 * Stable de-duplication key for a candidate.
	 *
	 * @param string      $entity_type lawyer|law_firm.
	 * @param string      $name        Raw name.
	 * @param string|null $city        City slug.
	 * @param string|null $state       State slug.
	 */
	public static function dedupe_key( string $entity_type, string $name, ?string $city, ?string $state ): string {
		return sha1( implode( '|', array( $entity_type, self::name( $name, $entity_type ), (string) $city, (string) $state ) ) );
	}

	/**
	 * Registrable-ish domain of a URL ("https://www.smithlaw.com/team" → "smithlaw.com").
	 *
	 * @param string|null $url URL.
	 */
	public static function domain( ?string $url ): ?string {
		if ( null === $url || '' === $url ) {
			return null;
		}
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress-independent by design.
		$host = (string) preg_replace( '/^www\./', '', $host );
		return '' === $host ? null : $host;
	}

	/**
	 * Transliterate to ASCII.
	 *
	 * @param string $text Text.
	 */
	private static function ascii( string $text ): string {
		if ( class_exists( \Normalizer::class ) ) {
			$decomposed = \Normalizer::normalize( $text, \Normalizer::FORM_D );
			if ( false !== $decomposed ) {
				$text = (string) preg_replace( '/\p{Mn}+/u', '', $decomposed );
			}
		} elseif ( function_exists( 'remove_accents' ) ) {
			// Without the intl extension, use WordPress's table so "José" stays "jose", not "jos".
			$text = remove_accents( $text );
		}
		return (string) preg_replace( '/[^\x20-\x7E]/', '', $text );
	}
}
