<?php
/**
 * Review errors.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Reviews;

/**
 * A review operation that cannot proceed (with an HTTP status for REST).
 */
final class ReviewException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $error_code Machine code.
	 * @param string $message    Message (safe to show).
	 * @param int    $status     HTTP status.
	 */
	public function __construct( public readonly string $error_code, string $message, public readonly int $status = 400 ) {
		parent::__construct( $message );
	}
}
