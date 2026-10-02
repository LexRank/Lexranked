<?php
/**
 * Ranking post type.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\PostTypes;

use LexRanked\Core\Ranking\RankingQualifier;
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
			new Field( 'context_type', Field::TYPE_ENUM, 'Context ("best for")', options: RankingQualifier::TYPES, help: 'Optional. case_type (a sub-area of the practice area, e.g. car-accidents), client_type or language. Only entities whose stored facts confirm the context are ranked, and the page exists only when enough of them are verified.' ),
			new Field( 'context_value', Field::TYPE_STRING, 'Context value', max: 60, help: 'Slug: a practice-area sub-area (car-accidents), a client type (businesses) or a language (spanish).' ),
			new Field( 'min_verified', Field::TYPE_INT, 'Minimum verified for a context', min: 1, max: 100, help: 'Contextual rankings only: entities whose context is confirmed by a verified fact. Default 3.' ),
			new Field( 'min_entities', Field::TYPE_INT, 'Minimum entities to publish', min: 1, max: 100, help: 'Below this the ranking is treated as thin: no entries and noindex. Default 5.' ),
			new Field( 'max_entities', Field::TYPE_INT, 'Maximum entries shown', min: 1, max: 100, help: 'Default 25.' ),
			new Field( 'summary', Field::TYPE_TEXT, 'Summary (above the ranking)', help: 'Short editorial introduction shown above the ranking, 1–3 sentences. Plain text. Only facts backed by stored data. The main text below the ranking goes in the editor above.' ),
			new Field( 'faq', Field::TYPE_OBJECT_LIST, 'FAQ (below the ranking)', options: array( 'question', 'answer' ), help: 'One per line: Question | Answer. Answers must be factual and must not contain "|".' ),
			new Field( 'reviewed_by', Field::TYPE_STRING, 'Editorially reviewed by', max: 100, help: 'Public name of the editor who reviewed this page.' ),
			new Field( 'reviewed_at', Field::TYPE_DATE, 'Reviewed on' ),
			self::demo_field(),
		);
	}
}
