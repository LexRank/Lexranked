<?php
/**
 * Declarative field definition.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Schema;

/**
 * One structured field of an entity.
 *
 * A single field list drives meta registration, admin forms, sanitization
 * and DTO mapping, so the rules for a field live in exactly one place.
 */
final class Field {

	public const TYPE_STRING      = 'string';
	public const TYPE_TEXT        = 'text';
	public const TYPE_INT         = 'int';
	public const TYPE_FLOAT       = 'float';
	public const TYPE_BOOL        = 'bool';
	public const TYPE_DATE        = 'date';
	public const TYPE_DATETIME    = 'datetime';
	public const TYPE_URL         = 'url';
	public const TYPE_EMAIL       = 'email';
	public const TYPE_PHONE       = 'phone';
	public const TYPE_ENUM        = 'enum';
	public const TYPE_STRING_LIST = 'string_list';
	public const TYPE_OBJECT_LIST = 'object_list';
	public const TYPE_POST_REF    = 'post_ref';

	/** Meta key prefix. The leading underscore hides keys from the generic Custom Fields UI. */
	public const META_PREFIX = '_lr_';

	/**
	 * Constructor.
	 *
	 * @param string             $key         Field key (snake_case), e.g. "bar_status".
	 * @param string             $type        One of the TYPE_* constants.
	 * @param string             $label       Human-readable label for admin UI.
	 * @param bool               $required    Whether a value is required.
	 * @param bool               $is_public   Whether the value may appear in public API responses.
	 * @param bool               $read_only   Set by the system (e.g. ranking engine), never by editors.
	 * @param array<int, string> $options     Allowed values for enum fields; sub-field keys for object lists.
	 * @param float|null         $min         Minimum for numeric fields.
	 * @param float|null         $max         Maximum for numeric fields; max length for strings.
	 * @param string             $help        Help text for admin UI.
	 * @param array<int, string> $ref_types   Allowed post types for post_ref fields.
	 * @throws \InvalidArgumentException When the key is not snake_case.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $type,
		public readonly string $label,
		public readonly bool $required = false,
		public readonly bool $is_public = true,
		public readonly bool $read_only = false,
		public readonly array $options = array(),
		public readonly ?float $min = null,
		public readonly ?float $max = null,
		public readonly string $help = '',
		public readonly array $ref_types = array(),
	) {
		if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $key ) ) {
			throw new \InvalidArgumentException( 'Invalid field key.' );
		}
	}

	/**
	 * Meta key used to store the field.
	 */
	public function meta_key(): string {
		return self::META_PREFIX . $this->key;
	}

	/**
	 * Whether the stored value is an array (serialized as JSON in meta).
	 */
	public function is_list(): bool {
		return self::TYPE_STRING_LIST === $this->type || self::TYPE_OBJECT_LIST === $this->type;
	}

	/**
	 * WordPress meta type for register_post_meta().
	 */
	public function wp_meta_type(): string {
		return match ( $this->type ) {
			self::TYPE_INT, self::TYPE_POST_REF => 'integer',
			self::TYPE_FLOAT => 'number',
			self::TYPE_BOOL => 'boolean',
			default => 'string',
		};
	}
}
