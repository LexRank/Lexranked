<?php
/**
 * Public content version.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Support;

/**
 * A counter bumped whenever public data changes. Cache keys include it, so a
 * change invalidates every cached API response at once without tracking
 * which responses a change affects.
 */
final class ContentVersion {

	public const OPTION = 'lexranked_content_version';

	/**
	 * Current version.
	 */
	public static function get(): int {
		return (int) get_option( self::OPTION, 1 );
	}

	/**
	 * Increment (autoloaded so reads are free).
	 */
	public static function bump(): void {
		update_option( self::OPTION, self::get() + 1, true );
	}
}
