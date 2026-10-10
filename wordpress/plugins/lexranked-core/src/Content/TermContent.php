<?php
/**
 * Editorial content for state, city and practice-area pages.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Content;

use LexRanked\Core\Schema\Field;
use LexRanked\Core\Schema\FieldSanitizer;
use LexRanked\Core\Schema\ValidationException;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Summary (above), guide body and FAQ (below) for hub pages, stored as term
 * meta and edited on the term screen - the same SEO/GEO layout rankings use.
 * The frontend only shows it when the hub page exists (enough data); the
 * text never creates a page on its own.
 */
final class TermContent {

	public const TAXONOMIES = array( Location::SLUG, PracticeArea::SLUG );

	public const META_SUMMARY     = '_lr_summary';
	public const META_BODY        = '_lr_body';
	public const META_FAQ         = '_lr_faq';
	public const META_REVIEWED_BY = '_lr_reviewed_by';
	public const META_REVIEWED_AT = '_lr_reviewed_at';

	private const NONCE_ACTION = 'lexranked_term_content';
	private const NONCE_FIELD  = 'lexranked_term_content_nonce';
	private const INPUT        = 'lexranked_term_content';
	private const MAX_BODY     = 20000;

	/**
	 * Hooks.
	 */
	public function register(): void {
		foreach ( self::TAXONOMIES as $taxonomy ) {
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render' ), 20 );
			add_action( 'edited_' . $taxonomy, array( $this, 'save' ) );
		}
	}

	/**
	 * FAQ field definition (reuses the object-list sanitizer).
	 */
	private static function faq_field(): Field {
		return new Field( 'faq', Field::TYPE_OBJECT_LIST, 'FAQ', options: array( 'question', 'answer' ) );
	}

	/**
	 * Edit-screen fields.
	 *
	 * @param \WP_Term $term Term.
	 */
	public function render( \WP_Term $term ): void {
		$c = self::raw( $term->term_id );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$faq_lines = implode( "\n", array_map( static fn( array $f ): string => $f['question'] . ' | ' . $f['answer'], $c['faq'] ) );
		$rows      = array(
			array( 'summary', 'Page summary (above the list)', sprintf( '<textarea name="%1$s[summary]" rows="3" class="large-text">%2$s</textarea>', esc_attr( self::INPUT ), esc_textarea( $c['summary'] ) ), '1-3 plain-text sentences, only facts backed by stored data. Shown on the hub page when it has enough profiles.' ),
			array( 'body', 'Guide (below the list)', sprintf( '<textarea name="%1$s[body]" rows="10" class="large-text code">%2$s</textarea>', esc_attr( self::INPUT ), esc_textarea( $c['body'] ) ), 'Basic HTML allowed (h2, p, ul, a, strong). Sanitized on save.' ),
			array( 'faq', 'FAQ', sprintf( '<textarea name="%1$s[faq]" rows="5" class="large-text">%2$s</textarea>', esc_attr( self::INPUT ), esc_textarea( $faq_lines ) ), 'One per line: Question | Answer' ),
			array( 'reviewed_by', 'Editorially reviewed by', sprintf( '<input type="text" name="%1$s[reviewed_by]" value="%2$s" class="regular-text">', esc_attr( self::INPUT ), esc_attr( $c['reviewed_by'] ) ), '' ),
			array( 'reviewed_at', 'Reviewed on', sprintf( '<input type="date" name="%1$s[reviewed_at]" value="%2$s">', esc_attr( self::INPUT ), esc_attr( $c['reviewed_at'] ) ), '' ),
		);
		foreach ( $rows as [ $key, $label, $input, $help ] ) {
			printf(
				'<tr class="form-field"><th scope="row"><label>%s</label></th><td>%s%s</td></tr>',
				esc_html( $label ),
				$input, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped values above.
				'' === $help ? '' : '<p class="description">' . esc_html( $help ) . '</p>'
			);
			unset( $key );
		}
	}

	/**
	 * Save from the edit screen.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		// Raw values are sanitized field by field in store().
		$input = isset( $_POST[ self::INPUT ] ) && is_array( $_POST[ self::INPUT ] ) ? wp_unslash( $_POST[ self::INPUT ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::store( $term_id, $input );
	}

	/**
	 * Sanitize and store content (also used when applying an AI draft).
	 *
	 * @param int                  $term_id Term ID.
	 * @param array<string, mixed> $input   summary, body, faq (lines or list), reviewed_by, reviewed_at.
	 * @return array<string, string> Validation errors.
	 */
	public static function store( int $term_id, array $input ): array {
		$errors = array();
		if ( array_key_exists( 'summary', $input ) ) {
			$summary = trim( sanitize_textarea_field( (string) $input['summary'] ) );
			self::put( $term_id, self::META_SUMMARY, mb_substr( $summary, 0, 1000 ) );
		}
		if ( array_key_exists( 'body', $input ) ) {
			self::put( $term_id, self::META_BODY, mb_substr( wp_kses_post( (string) $input['body'] ), 0, self::MAX_BODY ) );
		}
		if ( array_key_exists( 'faq', $input ) ) {
			try {
				$faq = FieldSanitizer::sanitize( self::faq_field(), $input['faq'] );
				self::put( $term_id, self::META_FAQ, null === $faq ? '' : (string) wp_json_encode( $faq ) );
			} catch ( ValidationException $e ) {
				$errors['faq'] = 'FAQ ' . $e->reason . '.';
			}
		}
		if ( array_key_exists( 'reviewed_by', $input ) ) {
			self::put( $term_id, self::META_REVIEWED_BY, mb_substr( sanitize_text_field( (string) $input['reviewed_by'] ), 0, 100 ) );
		}
		if ( array_key_exists( 'reviewed_at', $input ) ) {
			$date  = (string) $input['reviewed_at'];
			$valid = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
			self::put( $term_id, self::META_REVIEWED_AT, $valid ? $date : '' );
		}
		return $errors;
	}

	/**
	 * Update or delete a meta value.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Meta key.
	 * @param string $value   Value ('' deletes).
	 */
	private static function put( int $term_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_term_meta( $term_id, $key );
		} else {
			update_term_meta( $term_id, $key, wp_slash( $value ) );
		}
	}

	/**
	 * Stored values.
	 *
	 * @param int $term_id Term ID.
	 * @return array{summary: string, body: string, faq: array<int, array{question: string, answer: string}>, reviewed_by: string, reviewed_at: string}
	 */
	public static function raw( int $term_id ): array {
		$faq = json_decode( (string) get_term_meta( $term_id, self::META_FAQ, true ), true );
		$faq = is_array( $faq ) ? array_values(
			array_filter(
				array_map(
					static fn( $f ): ?array => is_array( $f ) && ! empty( $f['question'] ) && ! empty( $f['answer'] ) ? array(
						'question' => (string) $f['question'],
						'answer'   => (string) $f['answer'],
					) : null,
					$faq
				)
			)
		) : array();
		return array(
			'summary'     => (string) get_term_meta( $term_id, self::META_SUMMARY, true ),
			'body'        => (string) get_term_meta( $term_id, self::META_BODY, true ),
			'faq'         => $faq,
			'reviewed_by' => (string) get_term_meta( $term_id, self::META_REVIEWED_BY, true ),
			'reviewed_at' => (string) get_term_meta( $term_id, self::META_REVIEWED_AT, true ),
		);
	}

	/**
	 * Public DTO, or null when the term has no editorial content.
	 *
	 * @param int $term_id Term ID.
	 * @return array{summary: string|null, body: string, faq: array<int, array{question: string, answer: string}>, reviewedBy: string|null, reviewedAt: string|null}|null
	 */
	public static function dto( int $term_id ): ?array {
		$c = self::raw( $term_id );
		if ( '' === $c['summary'] && '' === trim( $c['body'] ) && array() === $c['faq'] ) {
			return null;
		}
		return array(
			'summary'    => '' === $c['summary'] ? null : $c['summary'],
			'body'       => '' === trim( $c['body'] ) ? '' : wp_kses_post( wpautop( $c['body'] ) ),
			'faq'        => $c['faq'],
			'reviewedBy' => '' === $c['reviewed_by'] ? null : $c['reviewed_by'],
			'reviewedAt' => '' === $c['reviewed_at'] ? null : $c['reviewed_at'],
		);
	}
}
