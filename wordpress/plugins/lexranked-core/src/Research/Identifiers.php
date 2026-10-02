<?php
/**
 * Entity-resolution identifiers.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

use LexRanked\Core\Attribute\Normalizer;

/**
 * Normalised identifiers used to decide whether two records describe the
 * same lawyer or firm. Pure. Strength (docs/knowledge-base.md):
 *
 * - Bar: official identifier (state + bar number), strong.
 * - Email: exact address, strong.
 * - Phone: E.164, strong for firms, weak for lawyers (shared firm lines).
 * - Address: street + ZIP, strong for firms, not used for lawyers.
 * - Name alone: weak.
 */
final class Identifiers {

	public const KEYS = array( 'bar', 'email', 'phone', 'address' );

	/**
	 * Normalise raw identifiers.
	 *
	 * @param array<string, mixed> $raw phone, email, bar_state, bar_number, address, zip_code.
	 * @return array<string, string> Only the usable keys of self::KEYS.
	 */
	public static function from( array $raw ): array {
		$out   = array();
		$phone = isset( $raw['phone'] ) && is_scalar( $raw['phone'] ) ? Normalizer::phone( (string) $raw['phone'] ) : null;
		if ( null !== $phone ) {
			$out['phone'] = $phone;
		}
		$email = isset( $raw['email'] ) && is_scalar( $raw['email'] ) ? strtolower( trim( (string) $raw['email'] ) ) : '';
		if ( false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$out['email'] = $email;
		}
		$bar = self::bar( (string) ( $raw['bar_state'] ?? '' ), (string) ( $raw['bar_number'] ?? '' ) );
		if ( null !== $bar ) {
			$out['bar'] = $bar;
		}
		$address = self::address( (string) ( $raw['address'] ?? '' ), (string) ( $raw['zip_code'] ?? '' ) );
		if ( null !== $address ) {
			$out['address'] = $address;
		}
		return $out;
	}

	/**
	 * "FL:123456".
	 *
	 * @param string $state  State code.
	 * @param string $number Bar number.
	 */
	public static function bar( string $state, string $number ): ?string {
		$state  = strtoupper( trim( $state ) );
		$number = Normalizer::bar_number( $number );
		return preg_match( '/^[A-Z]{2}$/', $state ) && null !== $number ? $state . ':' . $number : null;
	}

	/**
	 * "100 example ave|33101": lowercase, common suffixes abbreviated, punctuation removed.
	 *
	 * @param string $street Street address.
	 * @param string $zip    ZIP code.
	 */
	public static function address( string $street, string $zip ): ?string {
		$zip   = substr( (string) preg_replace( '/\D/', '', $zip ), 0, 5 );
		$s     = strtolower( (string) preg_replace( '/[^a-z0-9 ]+/i', ' ', $street ) );
		$map   = array(
			'street'    => 'st',
			'avenue'    => 'ave',
			'boulevard' => 'blvd',
			'road'      => 'rd',
			'drive'     => 'dr',
			'suite'     => 'ste',
			'floor'     => 'fl',
			'north'     => 'n',
			'south'     => 's',
			'east'      => 'e',
			'west'      => 'w',
		);
		$words = array_map( static fn( string $w ): string => $map[ $w ] ?? $w, array_values( array_filter( explode( ' ', $s ) ) ) );
		return 5 === strlen( $zip ) && count( $words ) >= 2 ? implode( ' ', $words ) . '|' . $zip : null;
	}
}
