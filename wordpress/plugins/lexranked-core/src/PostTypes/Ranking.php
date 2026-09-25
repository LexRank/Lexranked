<?php
/**
 * Ranking post type.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\PostTypes;

use LexRanked\Core\Schema\Field;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Ranking definition: which entities (location × practice area) are ranked.
 *
 * Positions are never edited by hand. Until the Phase 4 engine writes
 * snapshots, entries are read from stored scores only.
 */
final class Ranking extends PostType {

	public const SLUG = 'lr_ranking';

	public const ENTITY_TYPES = array( 'lawyer', 'law_firm' );

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
		return 'Ranking';
	}

	/**
	 * {@inheritDoc}
	 */
	public function plural(): string {
		return 'Rankings';
	}

	/**
	 * {@inheritDoc}
	 */
	public function public_base(): ?string {
		return 'rankings';
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
	public function taxonomies(): array {
		return array( Location::SLUG, PracticeArea::SLUG );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return 'dashicons-awards';
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields(): array {
		return array(
			new Field( 'entity_type', Field::TYPE_ENUM, 'Ranks', required: true, options: self::ENTITY_TYPES ),
			new Field( 'score_version', Field::TYPE_STRING, 'Score version', max: 20, help: 'Only entities scored with this version are ranked, e.g. v1.0' ),
			new Field( 'min_entities', Field::TYPE_INT, 'Minimum entities to publish', min: 1, max: 100, help: 'Below this the ranking is treated as thin: no entries and noindex. Default 5.' ),
			new Field( 'max_entities', Field::TYPE_INT, 'Maximum entries shown', min: 1, max: 100, help: 'Default 25.' ),
			self::demo_field(),
		);
	}
}
