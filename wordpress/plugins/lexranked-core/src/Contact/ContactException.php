<?php
/**
 * Contact form errors.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Contact;

/**
 * A contact message that cannot be sent (with an HTTP status and field errors for REST).
 */
final class ContactException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string                $error_code Machine code.
	 * @param string                $message    Message (safe to show).
	 * @param int                   $status     HTTP status.
	 * @param array<string, string> $errors     Field errors.
	 */
	public function __construct( public readonly string $error_code, string $message, public readonly int $status = 400, public readonly array $errors = array() ) {
		parent::__construct( $message );
	}
}
