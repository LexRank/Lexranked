<?php
/**
 * Research job API error.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

/**
 * A job operation that cannot proceed; carries a stable error code and HTTP
 * status for the REST layer.
 */
final class JobException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $error_code Stable machine-readable code (e.g. lexranked_lease_lost).
	 * @param string $message    Human-readable message.
	 * @param int    $status     HTTP status.
	 */
	public function __construct( public readonly string $error_code, string $message, public readonly int $status = 409 ) {
		parent::__construct( $message );
	}
}
