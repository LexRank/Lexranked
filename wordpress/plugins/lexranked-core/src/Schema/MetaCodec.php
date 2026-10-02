<?php
/**
 * Meta value encoding.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Schema;

/**
 * Converts between typed field values and post-meta storage.
 *
 * Lists are stored as JSON; null is represented by the absence of the meta
 * row (callers delete meta instead of storing empty strings).
 */
final class MetaCodec {

	/**
	 * Encode for storage.
	 *
	 * @param Field $field Field.
	 * @param mixed $value Sanitized value (non-null).
	 */
	public static function encode( Field $field, mixed $value ): string|int|float|bool {
		if ( $field->is_list() ) {
			return (string) json_encode( array_values( (array) $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-independent by design.
		}
		return match ( $field->type ) {
			Field::TYPE_BOOL => $value ? '1' : '0',
			default => is_scalar( $value ) ? $value : '',
		};
	}

	/**
	 * Decode a stored meta value into its typed form (null when absent/empty).
	 *
	 * @param Field $field Field.
	 * @param mixed $raw   Raw meta value ('' when absent).
	 */
	public static function decode( Field $field, mixed $raw ): mixed {
		if ( Field::TYPE_BOOL === $field->type ) {
			return in_array( $raw, array( true, 1, '1' ), true );
		}
		if ( null === $raw || '' === $raw || false === $raw ) {
			return $field->is_list() ? array() : null;
		}
		if ( $field->is_list() ) {
			$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			return is_array( $decoded ) ? array_values( $decoded ) : array();
		}
		return match ( $field->type ) {
			Field::TYPE_INT, Field::TYPE_POST_REF => (int) $raw,
			Field::TYPE_FLOAT => round( (float) $raw, 2 ),
			default => (string) $raw,
		};
	}
}
