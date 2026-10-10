<?php
/**
 * AI content draft post type.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\PostTypes;

use LexRanked\Core\Schema\Field;

/**
 * Machine-drafted editorial content waiting for a human. A draft is never
 * public: it carries the generated text, the numbered facts it was allowed
 * to use and the QA report. An editor applies it to its target (e.g. a
 * ranking's summary, body and FAQ) explicitly - nothing is published by the
 * generator.
 */
final class ContentDraft extends PostType {

	public const SLUG = 'lr_content_draft';

	public const CONTENT_TYPES = array( 'ranking_content', 'hub_content', 'profile_summary', 'article' );

	/** Taxonomies a hub_content draft may target. */
	public const HUB_TAXONOMIES = array( 'lr_location', 'lr_practice_area' );

	public const QA_NEEDS_REVIEW = 'needs_review';
	public const QA_READY        = 'ready_for_review';
	public const QA_APPLIED      = 'applied';
	public const QA_STATUSES     = array( self::QA_NEEDS_REVIEW, self::QA_READY, self::QA_APPLIED );

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
		return 'AI Content Draft';
	}

	/**
	 * {@inheritDoc}
	 */
	public function plural(): string {
		return 'AI Content Drafts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return 'dashicons-edit-page';
	}

	/**
	 * Generated body (HTML built from plain-text paragraphs).
	 */
	public function supports_editor(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields(): array {
		return array(
			new Field( 'content_type', Field::TYPE_ENUM, 'Content type', required: true, read_only: true, is_public: false, options: self::CONTENT_TYPES ),
			new Field( 'target_id', Field::TYPE_POST_REF, 'Target', read_only: true, is_public: false, ref_types: array( Ranking::SLUG, Lawyer::SLUG, LawFirm::SLUG ), help: 'Ranking, lawyer or law firm (for articles: the related ranking, if any).' ),
			new Field( 'target_term', Field::TYPE_INT, 'Target location / practice area (term ID)', read_only: true, is_public: false, min: 1 ),
			new Field( 'target_taxonomy', Field::TYPE_ENUM, 'Target taxonomy', read_only: true, is_public: false, options: self::HUB_TAXONOMIES ),
			new Field( 'article_id', Field::TYPE_INT, 'Article created from this draft', read_only: true, is_public: false, min: 1 ),
			new Field( 'qa_status', Field::TYPE_ENUM, 'QA status', required: true, read_only: true, is_public: false, options: self::QA_STATUSES ),
			new Field( 'summary', Field::TYPE_TEXT, 'Summary / excerpt', is_public: false, help: 'Ranking or hub: shown above the list. Profile: the profile summary. Article: the excerpt.' ),
			new Field( 'faq', Field::TYPE_OBJECT_LIST, 'FAQ', is_public: false, options: array( 'question', 'answer' ), help: 'One per line: Question | Answer' ),
			new Field( 'qa_report', Field::TYPE_TEXT, 'QA report (JSON)', read_only: true, is_public: false ),
			new Field( 'facts', Field::TYPE_TEXT, 'Facts supplied to the model (JSON)', read_only: true, is_public: false ),
			new Field( 'model', Field::TYPE_STRING, 'Model', read_only: true, is_public: false, max: 100 ),
			new Field( 'prompt_version', Field::TYPE_STRING, 'Prompt version', read_only: true, is_public: false, max: 40 ),
			new Field( 'job_id', Field::TYPE_INT, 'Research job', read_only: true, is_public: false, min: 0 ),
			new Field( 'applied_at', Field::TYPE_DATETIME, 'Applied at (UTC)', read_only: true, is_public: false ),
		);
	}
}
