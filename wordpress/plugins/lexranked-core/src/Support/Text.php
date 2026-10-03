<?php
/**
 * Plain-text helpers for API output.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Support;

/**
 * The API returns plain text; clients escape it themselves. get_the_title()
 * runs wptexturize, which turns ' into &#8217;, so decode entities here.
 */
final class Text {

	/**
	 * A post's title as plain text (typographic quotes kept as characters).
	 *
	 * @param int|\WP_Post $post Post.
	 */
	public static function title( $post ): string {
		return self::plain( (string) get_the_title( $post ) );
	}

	/**
	 * Decode HTML entities.
	 *
	 * @param string $text Text that may contain entities.
	 */
	public static function plain( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
