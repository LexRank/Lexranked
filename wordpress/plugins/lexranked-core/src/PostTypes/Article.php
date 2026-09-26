<?php
/**
 * Editorial article fields (core "post" type).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\PostTypes;

use LexRanked\Core\Schema\Field;

/**
 * Field schema for editorial articles, which are normal WordPress Posts.
 * The core post type is not re-registered: this class only describes the
 * LexRanked fields shown in the article meta box and mapped by the API.
 */
final class Article extends PostType {

	public const SLUG = 'post';

	/** Articles shorter than this are "thin": published but not indexed. */
	public const MIN_INDEXABLE_WORDS = 300;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function singular(): string {
		return 'Article';
	}

	/**
	 * {@inheritDoc}
	 */
	public function plural(): string {
		return 'Articles';
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_editor(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields(): array {
		return array(
			new Field( 'related_ranking', Field::TYPE_POST_REF, 'Related ranking', ref_types: array( Ranking::SLUG ), help: 'Ranking this article supports; linked from the article.' ),
			new Field( 'reviewed_by', Field::TYPE_STRING, 'Editorially reviewed by', max: 100, help: 'Public name of the reviewing editor or attorney.' ),
			new Field( 'reviewed_at', Field::TYPE_DATE, 'Reviewed on' ),
			self::demo_field(),
		);
	}

	/**
	 * Never registered: "post" is a core type.
	 */
	public function register(): void {
	}
}
