<?php
/**
 * Validation failure.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Schema;

/**
 * Thrown when a field value fails validation.
 */
final class ValidationException extends \InvalidArgumentException {

	/**
	 * Constructor.
	 *
	 * @param string $field_key Field key.
	 * @param string $reason    Human-readable reason.
	 */
	public function __construct(
		public readonly string $field_key,
		public readonly string $reason
	) {
		parent::__construct( $field_key . ' ' . $reason );
	}
}
