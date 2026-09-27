<?php
/**
 * Value normalisation.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Attribute;

/**
 * RAW FACT → NORMALIZED FACT. Pure. The raw value a source published is
 * kept on the claim; the normalised value is what comparisons, conflict
 * detection and the engine use ("(305) 555-0101" and "+1 305 555 0101" are
 * the same phone). Returns null when a value cannot be normalised: the raw
 * value stays, nothing is guessed.
 */
final class Normalizer {

	/**
	 * Normalise a value for an attribute.
	 *
	 * @param Attribute $attribute Attribute.
	 * @param mixed     $value     Raw (decoded) value.
	 */
	public static function normalize( Attribute $attribute, mixed $value ): mixed {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return match ( $attribute->value_type ) {
			'integer'     => is_numeric( $value ) && (float) $value >= 0 && floor( (float) $value ) === (float) $value ? (int) $value : null,
			'number'      => is_numeric( $value ) ? round( (float) $value, 2 ) : null,
			'url'         => self::url( (string) $value ),
			'phone'       => self::phone( (string) $value ),
			'email'       => false !== filter_var( trim( (string) $value ), FILTER_VALIDATE_EMAIL ) ? strtolower( trim( (string) $value ) ) : null,
			'code'        => self::code( $attribute->key, (string) $value ),
			'enum'        => strtolower( self::text( (string) $value ) ),
			'reference'   => is_numeric( $value ) && (int) $value > 0 ? (int) $value : null,
			'list'        => self::items( $attribute->key, $value ),
			'object_list' => is_array( $value ) ? array_values( $value ) : null,
			default       => is_scalar( $value ) ? self::text( (string) $value ) : null,
		};
	}

	/**
	 * Collapse whitespace.
	 *
	 * @param string $value Value.
	 */
	public static function text( string $value ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Canonical URL: lowercase scheme and host, no fragment, no trailing slash on the root.
	 *
	 * @param string $value URL.
	 */
	private static function url( string $value ): ?string {
		$parts = parse_url( trim( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress-independent by design.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		$path  = (string) ( $parts['path'] ?? '' );
		$path  = '/' === $path ? '' : rtrim( $path, '/' );
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $path . $query;
	}

	/**
	 * E.164-style phone: US 10-digit numbers get +1.
	 *
	 * @param string $value Phone.
	 */
	public static function phone( string $value ): ?string {
		$digits = (string) preg_replace( '/\D/', '', $value );
		if ( 10 === strlen( $digits ) ) {
			return '+1' . $digits;
		}
		if ( 11 === strlen( $digits ) && str_starts_with( $digits, '1' ) ) {
			return '+' . $digits;
		}
		return strlen( $digits ) >= 7 && strlen( $digits ) <= 15 ? '+' . $digits : null;
	}

	/**
	 * Identifiers and codes.
	 *
	 * @param string $key   Attribute key.
	 * @param string $value Value.
	 */
	private static function code( string $key, string $value ): ?string {
		$value = strtoupper( self::text( $value ) );
		return match ( $key ) {
			'bar_number' => self::bar_number( $value ),
			'zip_code'   => self::zip( $value ),
			'state', 'bar_state', 'country' => preg_match( '/^[A-Z]{2}$/', $value ) ? $value : null,
			default      => $value,
		};
	}

	/**
	 * ZIP / ZIP+4.
	 *
	 * @param string $value Value.
	 */
	private static function zip( string $value ): ?string {
		if ( ! preg_match( '/^(\d{5})(?:-?(\d{4}))?$/', $value, $m ) ) {
			return null;
		}
		return isset( $m[2] ) ? $m[1] . '-' . $m[2] : $m[1];
	}

	/**
	 * Bar number: letters and digits only, no leading zeros ("0123-456" = "123456").
	 *
	 * @param string $value Value.
	 */
	public static function bar_number( string $value ): ?string {
		$n = ltrim( (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( $value ) ), '0' );
		return '' === $n ? null : $n;
	}

	/**
	 * Lists: trimmed, de-duplicated; practice areas as taxonomy slugs.
	 *
	 * @param string $key   Attribute key.
	 * @param mixed  $value Value.
	 * @return array<int, string>|null
	 */
	private static function items( string $key, mixed $value ): ?array {
		$items = is_array( $value ) ? $value : preg_split( '/[,;\n]+/', (string) $value );
		$out   = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$item = self::text( (string) $item );
			if ( '' === $item ) {
				continue;
			}
			$item         = 'practice_areas' === $key ? trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $item ) ), '-' ) : $item;
			$out[ $item ] = true;
		}
		$out = array_keys( $out );
		sort( $out );
		return array() === $out ? null : array_map( 'strval', $out );
	}
}
