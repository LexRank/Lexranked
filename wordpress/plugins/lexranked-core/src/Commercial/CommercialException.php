<?php
/**
 * Commercial errors.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Commercial;

/**
 * A claim or placement operation that cannot proceed (with an HTTP status for REST).
 */
final class CommercialException extends \RuntimeException {

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
