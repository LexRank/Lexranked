<?php
/**
 * Field sanitization and validation.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Schema;

/**
 * Converts raw input (form posts, CLI, importers) into typed, validated values.
 *
 * Pure PHP with no WordPress dependency so it is fully unit-testable. An empty
 * input always becomes null: unknown is stored as unknown, never guessed.
 */
final class FieldSanitizer {

	private const MAX_STRING = 255;
	private const MAX_ITEM   = 1000;
	private const MAX_TEXT   = 20000;
	private const MAX_ITEMS  = 50;

	/**
	 * Sanitize one value.
	 *
	 * @param Field $field Field definition.
	 * @param mixed $raw   Raw value.
	 * @return mixed Sanitized value or null when empty.
	 * @throws ValidationException When a non-empty value is invalid.
	 */
	public static function sanitize( Field $field, mixed $raw ): mixed {
		if ( $field->is_list() ) {
			$items = self::sanitize_list( $field, $raw );
			return array() === $items ? null : $items;
		}

		if ( is_array( $raw ) || is_object( $raw ) ) {
			throw new ValidationException( $field->key, 'must be a scalar value' );
		}

		if ( Field::TYPE_BOOL === $field->type ) {
			return self::to_bool( $raw );
		}

		$value = self::clean_string( (string) ( $raw ?? '' ), Field::TYPE_TEXT === $field->type );
		if ( '' === $value ) {
			return null;
		}

		return match ( $field->type ) {
			Field::TYPE_STRING   => self::limit_length( $field, $value, (int) ( $field->max ?? self::MAX_STRING ) ),
			Field::TYPE_TEXT     => self::limit_length( $field, $value, self::MAX_TEXT ),
			Field::TYPE_INT      => self::to_int( $field, $value ),
			Field::TYPE_POST_REF => self::to_post_ref( $field, $value ),
			Field::TYPE_FLOAT    => self::to_float( $field, $value ),
			Field::TYPE_DATE     => self::to_date( $field, $value ),
			Field::TYPE_DATETIME => self::to_datetime( $field, $value ),
			Field::TYPE_URL      => self::to_url( $field, $value ),
			Field::TYPE_EMAIL    => self::to_email( $field, $value ),
			Field::TYPE_PHONE    => self::to_phone( $field, $value ),
			Field::TYPE_ENUM     => self::to_enum( $field, $value ),
			default              => throw new ValidationException( $field->key, 'has an unsupported type' ),
		};
	}

	/**
	 * Strip tags and control characters; collapse whitespace for single-line values.
	 *
	 * @param string $value     Raw string.
	 * @param bool   $multiline Keep newlines.
	 */
	public static function clean_string( string $value, bool $multiline = false ): string {
		$value = strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-independent by design.
		$value = (string) preg_replace( '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );
		if ( $multiline ) {
			$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
			return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $value ) );
		}
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Sanitize list fields. Accepts arrays or newline-separated strings.
	 * Object list lines use "a | b | c" mapped onto the field's sub-keys.
	 *
	 * @param Field $field Field definition.
	 * @param mixed $raw   Raw value.
	 * @return array<int, mixed>
	 * @throws ValidationException When the list is too long or malformed.
	 */
	private static function sanitize_list( Field $field, mixed $raw ): array {
		if ( null === $raw || '' === $raw ) {
			return array();
		}
		$lines = is_array( $raw ) ? $raw : explode( "\n", (string) $raw );
		$items = array();

		foreach ( $lines as $line ) {
			if ( Field::TYPE_OBJECT_LIST === $field->type ) {
				$item = self::sanitize_object( $field, $line );
				if ( null !== $item ) {
					$items[] = $item;
				}
				continue;
			}
			if ( ! is_scalar( $line ) ) {
				throw new ValidationException( $field->key, 'must contain only text values' );
			}
			$value = self::clean_string( (string) $line );
			if ( '' !== $value ) {
				$items[] = mb_substr( $value, 0, self::MAX_STRING );
			}
		}

		if ( Field::TYPE_STRING_LIST === $field->type ) {
			$items = array_values( array_unique( $items ) );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			throw new ValidationException( $field->key, sprintf( 'may contain at most %d items', self::MAX_ITEMS ) );
		}
		return $items;
	}

	/**
	 * Sanitize one object-list item.
	 *
	 * @param Field $field Field definition (options = sub-keys).
	 * @param mixed $raw   Array keyed by sub-key, or "a | b | c" string.
	 * @return array<string, string|null>|null
	 * @throws ValidationException When the item is malformed.
	 */
	private static function sanitize_object( Field $field, mixed $raw ): ?array {
		$keys = $field->options;
		if ( is_string( $raw ) ) {
			$parts = array_map( 'trim', explode( '|', $raw ) );
			if ( count( $parts ) > count( $keys ) ) {
				throw new ValidationException( $field->key, sprintf( 'items have at most %d parts separated by "|"', count( $keys ) ) );
			}
			$raw = array_combine( array_slice( $keys, 0, count( $parts ) ), $parts );
		}
		if ( ! is_array( $raw ) ) {
			throw new ValidationException( $field->key, 'items must be objects' );
		}

		$item      = array();
		$has_value = false;
		foreach ( $keys as $key ) {
			$value        = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? self::clean_string( (string) $raw[ $key ] ) : '';
			$item[ $key ] = '' === $value ? null : mb_substr( $value, 0, self::MAX_ITEM );
			$has_value    = $has_value || '' !== $value;
		}
		return $has_value ? $item : null;
	}

	/**
	 * Enforce a maximum string length.
	 *
	 * @param Field  $field Field.
	 * @param string $value Value.
	 * @param int    $max   Max length.
	 * @throws ValidationException When too long.
	 */
	private static function limit_length( Field $field, string $value, int $max ): string {
		if ( mb_strlen( $value ) > $max ) {
			throw new ValidationException( $field->key, sprintf( 'must be at most %d characters', $max ) );
		}
		return $value;
	}

	/**
	 * Parse a boolean.
	 *
	 * @param mixed $raw Raw value.
	 */
	private static function to_bool( mixed $raw ): bool {
		return in_array( strtolower( trim( (string) $raw ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Parse an integer and check bounds.
	 *
	 * @param Field  $field Field.
	 * @param string $value Value.
	 * @throws ValidationException When invalid.
	 */
	private static function to_int( Field $field, string $value ): int {
		if ( ! preg_match( '/^-?\d+$/', $value ) ) {
			throw new ValidationException( $field->key, 'must be a whole number' );
		}
		$int = (int) $value;
		self::check_range( $field, $int );
		return $int;
	}

	/**
	 * Parse a post reference (positive ID). Existence is checked by the caller.
	 *
	 * @param Field  $field Field.
	 * @param string $value Value.
	 * @throws ValidationException When invalid.
	 */
	private static function to_post_ref( Field $field, string $value ): int {
		if ( ! preg_match( '/^[1-9]\d*$/', $value ) ) {
			throw new ValidationException( $field->key, 'must reference a valid record' );
		}
		return (int) $value;
	}

	/**
	 * Parse a decimal and check bounds.
	 *
	 * @param Field  $field Field.
	 * @param string $value Value.
	 * @throws ValidationException When invalid.
	 */
	private static function to_float( Field $field, string $value ): float {
		$value = str_replace( ',', '.', $value );
		if ( ! is_numeric( $value ) ) {
			throw new ValidationException( $field->key, 'must be a number' );
		}
		$float = (float) $value;
		self::check_range( $field, $float );
		return $float;
	}

	/**
	 * Check numeric bounds.
	 *
	 * @param Field     $field Field.
	 * @param int|float $value Value.
	 * @throws ValidationException When out of range.
	 */
	private static function check_range( Field $field, int|float $value ): void {
		if ( null !== $field->min && $value < $field->min ) {
			throw new ValidationException( $field->key, sprintf( 'must be at least %s', self::format_number( $field->min ) ) );
		}
		if ( null !== $field->max && $value > $field->max ) {
			throw new ValidationException( $field->key, sprintf( 'must be at most %s', self::format_number( $field->max ) ) );
		}
	}

	/**
	 * Format a bound for messages.
	 *
	 * @param float $number Number.
	 */
	private static function format_number( float $number ): string {
		return floor( $number ) === $number ? (string) (int) $number : (string) $number;
	}

	/**
	 * Parse a Y-m-d date.
	 *
	 * @param Field  $field Field.
	 * @param string $value Value.
	 * @throws ValidationException When invalid.
	 */
	private static function to_date( Field $field, string $value ): string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			throw new ValidationException( $field->key, 'must be a date in YYYY-MM-DD format' );
		}
		return $value;
	}

	/**
	 * Parse a datetime; stored as UTC ISO 8601 (Y-m-d\TH:i:s\Z).
	 *
	 * @param Field  $field Field.
	 * @param string $value Value (ISO 8601, or HTML datetime-local interpreted as UTC).
	 * @throws ValidationException When invalid.
	 */
	private static function to_datetime( Field $field, string $value ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?(Z|[+-]\d{2}:?\d{2})?$/', $value ) ) {
			throw new ValidationException( $field->key, 'must be an ISO 8601 date/time' );
		}
		try {
			$date = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			throw new ValidationException( $field->key, 'must be an ISO 8601 date/time' );
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Validate an absolute http(s) URL.
	 *
	 * @param Field  $field Field.
	 * @param string $value Value.
	 * @throws ValidationException When invalid.
	 */
	private static function to_url( Field $field, string $value ): string {
		$scheme = strtolower( (string) parse_url( $value, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress-independent by design.
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
			throw new ValidationException( $field->key, 'must be a full http(s) URL' );
		}
		return self::limit_length( $field, $value, 2048 );
	}

	/**
	 * Validate an email address.
	 *
	 * @param Field  $field Field.
	 * @param string $value Value.
	 * @throws ValidationException When invalid.
	 */
	private static function to_email( Field $field, string $value ): string {
		$email = filter_var( $value, FILTER_VALIDATE_EMAIL );
		if ( false === $email ) {
			throw new ValidationException( $field->key, 'must be a valid email address' );
		}
		return strtolower( $email );
	}

	/**
	 * Validate a phone number: digits with common separators, 7–15 digits (E.164 length).
	 *
	 * @param Field  $field Field.
	 * @param string $value Value.
	 * @throws ValidationException When invalid.
	 */
	private static function to_phone( Field $field, string $value ): string {
		$digits = (string) preg_replace( '/\D/', '', $value );
		if ( ! preg_match( '/^\+?[\d\s().\-]+$/', $value ) || strlen( $digits ) < 7 || strlen( $digits ) > 15 ) {
			throw new ValidationException( $field->key, 'must be a valid phone number' );
		}
		return $value;
	}

	/**
	 * Validate an enum value.
	 *
	 * @param Field  $field Field.
	 * @param string $value Value.
	 * @throws ValidationException When not allowed.
	 */
	private static function to_enum( Field $field, string $value ): string {
		if ( ! in_array( $value, $field->options, true ) ) {
			throw new ValidationException( $field->key, 'must be one of: ' . implode( ', ', $field->options ) );
		}
		return $value;
	}
}
