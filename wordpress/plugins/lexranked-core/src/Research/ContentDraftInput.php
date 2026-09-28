<?php
/**
 * Validation of machine-drafted content.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

use LexRanked\Core\PostTypes\ContentDraft;
use LexRanked\Core\Schema\ValidationException;

/**
 * Validates a content draft submitted by a worker and turns it into plain
 * values. Text is plain (no markup accepted); the body HTML is built here
 * from escaped paragraphs, so a model can never inject markup or links.
 * The QA status is recomputed: a draft with any error-level issue is always
 * "needs_review", whatever the worker claims. Pure logic (no WordPress).
 */
final class ContentDraftInput {

	public const MAX_SECTIONS = 8;

	/** Fact statuses (Etap J): verified by LexRanked, backed by a source, or computed by the backend. */
	public const FACT_STATUSES  = array( 'verified', 'sourced', 'computed' );
	public const MAX_PARAGRAPHS = 6;
	public const MAX_FAQ        = 10;
	public const MAX_FACTS      = 300;
	public const MAX_ISSUES     = 100;
	public const SEVERITIES     = array( 'error', 'warning' );

	/**
	 * Validate.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 * @return array{content_type: string, target_id: int|null, target_term: int|null, target_taxonomy: string|null, title: string, summary: string, sections: array<int, array{heading: string, paragraphs: array<int, string>}>, faq: array<int, array{question: string, answer: string}>, qa_status: string, issues: array<int, array<string, string>>, facts: array<int, array{id: string, label: string, value: string, status: string, origin: string}>, model: string, prompt_version: string}
	 * @throws ValidationException When invalid.
	 */
	public static function validate( array $input ): array {
		$type = (string) ( $input['content_type'] ?? '' );
		if ( ! in_array( $type, ContentDraft::CONTENT_TYPES, true ) ) {
			throw new ValidationException( 'content_type', 'must be one of ' . implode( ', ', ContentDraft::CONTENT_TYPES ) );
		}
		$target = null;
		if ( isset( $input['target_id'] ) && null !== $input['target_id'] ) {
			$target = filter_var( $input['target_id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false === $target ) {
				throw new ValidationException( 'target_id', 'must be a positive integer' );
			}
		}
		$term     = null;
		$taxonomy = null;
		if ( 'hub_content' === $type ) {
			$term     = filter_var( $input['target_term'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			$taxonomy = (string) ( $input['target_taxonomy'] ?? '' );
			if ( false === $term || ! in_array( $taxonomy, ContentDraft::HUB_TAXONOMIES, true ) ) {
				throw new ValidationException( 'target_term', 'hub content needs a target_term and target_taxonomy' );
			}
		} elseif ( 'article' !== $type && null === $target ) {
			throw new ValidationException( 'target_id', 'is required for ' . $type );
		}

		$content  = is_array( $input['content'] ?? null ) ? $input['content'] : array();
		$summary  = self::text( $content['summary'] ?? '', 'summary', 1200, true );
		$sections = array();
		foreach ( self::list( $content['sections'] ?? array(), 'sections', self::MAX_SECTIONS ) as $i => $section ) {
			$paragraphs = array();
			foreach ( self::list( $section['paragraphs'] ?? array(), "sections.$i.paragraphs", self::MAX_PARAGRAPHS ) as $j => $paragraph ) {
				$paragraphs[] = self::text( is_array( $paragraph ) ? ( $paragraph['text'] ?? '' ) : $paragraph, "sections.$i.paragraphs.$j", 1500, true );
			}
			if ( array() === $paragraphs ) {
				throw new ValidationException( "sections.$i.paragraphs", 'must not be empty' );
			}
			$sections[] = array(
				'heading'    => self::text( $section['heading'] ?? '', "sections.$i.heading", 120, true ),
				'paragraphs' => $paragraphs,
			);
		}
		$faq = array();
		foreach ( self::list( $content['faq'] ?? array(), 'faq', self::MAX_FAQ ) as $i => $item ) {
			$faq[] = array(
				'question' => self::text( $item['question'] ?? '', "faq.$i.question", 200, true ),
				'answer'   => self::text( $item['answer'] ?? '', "faq.$i.answer", 1000, true ),
			);
		}
		$title = '';
		if ( 'profile_summary' === $type ) {
			if ( array() !== $sections || array() !== $faq || mb_strlen( $summary ) > 800 ) {
				throw new ValidationException( 'content', 'a profile summary is only a summary of at most 800 characters' );
			}
		} elseif ( 'article' === $type ) {
			$title = self::text( $content['title'] ?? '', 'title', 150, true );
			if ( count( $sections ) < 2 ) {
				throw new ValidationException( 'sections', 'an article needs at least two sections' );
			}
		} elseif ( array() === $sections && array() === $faq ) {
			throw new ValidationException( 'content', 'needs at least one section or FAQ item' );
		}

		$facts = array();
		foreach ( self::list( $input['facts'] ?? array(), 'facts', self::MAX_FACTS ) as $i => $fact ) {
			$status = (string) ( $fact['status'] ?? '' );
			if ( '' !== $status && ! in_array( $status, self::FACT_STATUSES, true ) ) {
				throw new ValidationException( "facts.$i.status", 'must be verified, sourced or computed' );
			}
			$facts[] = array(
				'id'     => self::text( $fact['id'] ?? '', "facts.$i.id", 10, true ),
				'label'  => self::text( $fact['label'] ?? '', "facts.$i.label", 200, true ),
				'value'  => self::text( $fact['value'] ?? '', "facts.$i.value", 500, true ),
				// Etap J: how the fact is known and which backend computation produced it.
				'status' => $status,
				'origin' => self::text( $fact['origin'] ?? '', "facts.$i.origin", 80, false ),
			);
		}
		if ( array() === $facts ) {
			throw new ValidationException( 'facts', 'are required: generated content must be traceable to supplied facts' );
		}

		$issues = array();
		foreach ( self::list( $input['qa']['issues'] ?? array(), 'qa.issues', self::MAX_ISSUES ) as $i => $issue ) {
			$severity = (string) ( $issue['severity'] ?? '' );
			if ( ! in_array( $severity, self::SEVERITIES, true ) ) {
				throw new ValidationException( "qa.issues.$i.severity", 'must be error or warning' );
			}
			$issues[] = array(
				'code'     => substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $issue['code'] ?? '' ) ) ), 0, 40 ),
				'severity' => $severity,
				'message'  => self::text( $issue['message'] ?? '', "qa.issues.$i.message", 500, true ),
				'excerpt'  => self::text( $issue['excerpt'] ?? '', "qa.issues.$i.excerpt", 300, false ),
			);
		}
		$has_error = array() !== array_filter( $issues, static fn( array $i ): bool => 'error' === $i['severity'] );
		$claimed   = (string) ( $input['qa']['status'] ?? '' );
		$status    = ( ! $has_error && ContentDraft::QA_READY === $claimed ) ? ContentDraft::QA_READY : ContentDraft::QA_NEEDS_REVIEW;

		return array(
			'content_type'    => $type,
			'target_id'       => null === $target ? null : (int) $target,
			'target_term'     => null === $term ? null : (int) $term,
			'target_taxonomy' => $taxonomy,
			'title'           => $title,
			'summary'         => $summary,
			'sections'        => $sections,
			'faq'             => $faq,
			'qa_status'       => $status,
			'issues'          => $issues,
			'facts'           => $facts,
			'model'           => self::text( $input['model'] ?? '', 'model', 100, true ),
			'prompt_version'  => self::text( $input['prompt_version'] ?? '', 'prompt_version', 40, true ),
		);
	}

	/**
	 * Body HTML from sections (escaped here; never model-supplied markup).
	 *
	 * @param array<int, array{heading: string, paragraphs: array<int, string>}> $sections Sections.
	 */
	public static function to_html( array $sections ): string {
		$html = '';
		foreach ( $sections as $section ) {
			$html .= '<h2>' . self::esc( $section['heading'] ) . "</h2>\n";
			foreach ( $section['paragraphs'] as $paragraph ) {
				$html .= '<p>' . self::esc( $paragraph ) . "</p>\n";
			}
		}
		return $html;
	}

	/**
	 * FAQ in the "Question | Answer" line format of object-list fields.
	 * A "|" inside text would break the format, so it is replaced.
	 *
	 * @param array<int, array{question: string, answer: string}> $faq FAQ.
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function faq_for_field( array $faq ): array {
		return array_map(
			static fn( array $f ): array => array(
				'question' => str_replace( '|', '/', $f['question'] ),
				'answer'   => str_replace( '|', '/', $f['answer'] ),
			),
			$faq
		);
	}

	/**
	 * HTML-escape.
	 *
	 * @param string $text Text.
	 */
	private static function esc( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * A list of arrays.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field path.
	 * @param int    $max   Max items.
	 * @return array<int, array<string, mixed>|string>
	 * @throws ValidationException When not a list or too long.
	 */
	private static function list( mixed $value, string $field, int $max ): array {
		if ( ! is_array( $value ) || ( array() !== $value && ! array_is_list( $value ) ) ) {
			throw new ValidationException( $field, 'must be a list' );
		}
		if ( count( $value ) > $max ) {
			throw new ValidationException( $field, 'has more than ' . $max . ' items' );
		}
		return $value;
	}

	/**
	 * Plain single-paragraph text.
	 *
	 * @param mixed  $value    Value.
	 * @param string $field    Field path.
	 * @param int    $max      Max characters.
	 * @param bool   $required Required.
	 * @throws ValidationException When invalid.
	 */
	private static function text( mixed $value, string $field, int $max, bool $required ): string {
		if ( ! is_string( $value ) ) {
			if ( $required || null !== $value ) {
				throw new ValidationException( $field, 'must be text' );
			}
			return '';
		}
		if ( 1 === preg_match( '/<[a-z!\/][^>]*>/i', $value ) ) {
			throw new ValidationException( $field, 'must be plain text (no markup)' );
		}
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		if ( $required && '' === $value ) {
			throw new ValidationException( $field, 'is required' );
		}
		if ( mb_strlen( $value ) > $max ) {
			throw new ValidationException( $field, 'is longer than ' . $max . ' characters' );
		}
		return $value;
	}
}
