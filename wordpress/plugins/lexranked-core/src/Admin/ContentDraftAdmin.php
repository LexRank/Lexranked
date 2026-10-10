<?php
/**
 * AI content draft review screen.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Admin;

use LexRanked\Core\Content\DraftApplier;
use LexRanked\Core\PostTypes\ContentDraft;
use LexRanked\Core\Services;

/**
 * Shows the QA report and the facts a draft was generated from, and lets an
 * editor apply the draft to its ranking. Applying is the only way generated
 * text reaches a public page, and a draft whose QA failed needs an explicit
 * acknowledgement.
 */
final class ContentDraftAdmin {

	public const ACTION = 'lexranked_apply_draft';

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action(
			'add_meta_boxes_' . ContentDraft::SLUG,
			function (): void {
				add_meta_box( 'lexranked-draft-qa', 'Quality check & apply', array( $this, 'render' ), ContentDraft::SLUG, 'normal', 'high' );
				add_meta_box( 'lexranked-draft-facts', 'Facts the model was allowed to use', array( $this, 'render_facts' ), ContentDraft::SLUG, 'normal', 'low' );
			}
		);
		add_action( 'admin_post_' . self::ACTION, array( $this, 'apply' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * QA box.
	 *
	 * @param \WP_Post $post Draft.
	 */
	public function render( \WP_Post $post ): void {
		$fields = $this->services->entities->record( $post, $this->services->content_draft )['fields'];
		$issues = json_decode( (string) ( $fields['qa_report'] ?? '[]' ), true );
		$issues = is_array( $issues ) ? $issues : array();
		$target = $this->applier()->target( $fields );
		$labels = array(
			ContentDraft::QA_READY        => '✅ Ready for review - automated checks passed',
			ContentDraft::QA_NEEDS_REVIEW => '⚠️ Needs review - automated checks found problems',
			ContentDraft::QA_APPLIED      => '✔ Applied',
		);
		printf( '<p><strong>%s</strong></p>', esc_html( $labels[ (string) $fields['qa_status'] ] ?? (string) $fields['qa_status'] ) );
		printf(
			'<p>Type: <code>%s</code> · Target: %s · Model: <code>%s</code> · Prompt: <code>%s</code> · Job #%d</p>',
			esc_html( (string) $fields['content_type'] ),
			null === $target ? '-' : '<a href="' . esc_url( $target['edit'] ) . '">' . esc_html( $target['label'] ) . '</a>',
			esc_html( (string) $fields['model'] ),
			esc_html( (string) $fields['prompt_version'] ),
			(int) $fields['job_id']
		);
		echo '<p class="description">Generated from the numbered facts below only. Automated QA checks every number, name and ranking position against those facts; it cannot judge tone or usefulness - that is your review.</p>';

		if ( array() !== $issues ) {
			echo '<table class="widefat striped"><thead><tr><th>Severity</th><th>Check</th><th>Problem</th><th>Excerpt</th></tr></thead><tbody>';
			foreach ( $issues as $issue ) {
				printf(
					'<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
					esc_html( (string) ( $issue['severity'] ?? '' ) ),
					esc_html( (string) ( $issue['code'] ?? '' ) ),
					esc_html( (string) ( $issue['message'] ?? '' ) ),
					esc_html( (string) ( $issue['excerpt'] ?? '' ) )
				);
			}
			echo '</tbody></table>';
		}

		if ( ContentDraft::QA_APPLIED === $fields['qa_status'] ) {
			printf( '<p>Applied on %s UTC.</p>', esc_html( str_replace( array( 'T', 'Z' ), array( ' ', '' ), (string) $fields['applied_at'] ) ) );
			if ( null !== $fields['article_id'] ) {
				printf( '<p><a class="button" href="%s">Open the article draft</a></p>', esc_url( (string) get_edit_post_link( (int) $fields['article_id'] ) ) );
			}
			return;
		}
		if ( ! $this->applier()->can_apply( $fields ) ) {
			return;
		}
		// A form inside the post form is invalid HTML; the button submits a separate form via the form attribute.
		$form_id = 'lexranked-apply-draft';
		add_action(
			'admin_footer',
			static function () use ( $post, $form_id ): void {
				printf( '<form id="%s" method="post" action="%s">', esc_attr( $form_id ), esc_url( admin_url( 'admin-post.php' ) ) );
				wp_nonce_field( self::ACTION . '_' . $post->ID );
				printf( '<input type="hidden" name="action" value="%s"><input type="hidden" name="draft" value="%d"></form>', esc_attr( self::ACTION ), (int) $post->ID );
			}
		);
		if ( ContentDraft::QA_NEEDS_REVIEW === $fields['qa_status'] ) {
			printf( '<p><label><input type="checkbox" name="acknowledge" value="1" form="%s"> I have checked every problem listed above and corrected the text.</label></p>', esc_attr( $form_id ) );
		}
		$actions = array(
			'ranking_content' => array( 'Apply to ranking', 'Replaces the ranking\'s summary, body and FAQ (save your edits here first). The previous body stays in the ranking\'s revisions.' ),
			'hub_content'     => array( 'Apply to page', 'Replaces the summary, guide and FAQ of this location / practice-area page (save your edits here first).' ),
			'profile_summary' => array( 'Apply to profile', 'Sets the profile summary shown at the top of the profile.' ),
			'article'         => array( 'Create article draft', 'Creates a WordPress Post in DRAFT status with this text. Review and publish it like any post.' ),
		);
		$action  = $actions[ (string) $fields['content_type'] ] ?? $actions['ranking_content'];
		printf(
			'<p><button type="submit" class="button button-primary" form="%s">%s</button> <span class="description">%s</span></p>',
			esc_attr( $form_id ),
			esc_html( $action[0] ),
			esc_html( $action[1] )
		);
	}

	/**
	 * Facts box.
	 *
	 * @param \WP_Post $post Draft.
	 */
	public function render_facts( \WP_Post $post ): void {
		$fields = $this->services->entities->record( $post, $this->services->content_draft )['fields'];
		$facts  = json_decode( (string) ( $fields['facts'] ?? '[]' ), true );
		if ( ! is_array( $facts ) || array() === $facts ) {
			echo '<p>-</p>';
			return;
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( $facts as $fact ) {
			printf(
				'<tr><td style="width:50px"><code>%s</code></td><td style="width:30%%">%s</td><td>%s</td><td style="width:18%%">%s</td></tr>',
				esc_html( (string) ( $fact['id'] ?? '' ) ),
				esc_html( (string) ( $fact['label'] ?? '' ) ),
				esc_html( (string) ( $fact['value'] ?? '' ) ),
				esc_html( trim( (string) ( $fact['status'] ?? '' ) . ( empty( $fact['origin'] ) ? '' : ' · ' . $fact['origin'] ), ' ·' ) )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Apply a draft to its ranking.
	 */
	public function apply(): void {
		$id = isset( $_POST['draft'] ) ? absint( $_POST['draft'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $id );
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ContentDraft::SLUG !== $post->post_type ) {
			wp_die( esc_html__( 'Draft not found.', 'lexranked-core' ), 404 );
		}
		$fields = $this->services->entities->record( $post, $this->services->content_draft )['fields'];
		if ( ! $this->applier()->can_apply( $fields ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'lexranked-core' ), 403 );
		}
		$back = (string) get_edit_post_link( $id, 'url' );
		try {
			$result = $this->applier()->apply( $id, ! empty( $_POST['acknowledge'] ) );
		} catch ( \RuntimeException $e ) {
			wp_die( esc_html__( 'Could not create the article draft.', 'lexranked-core' ), 500 );
		}
		if ( null !== $result['article_id'] ) {
			wp_safe_redirect( (string) get_edit_post_link( $result['article_id'], 'url' ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'lexranked_draft', $result['status'], $back ) );
		exit;
	}

	/**
	 * Shared apply implementation.
	 */
	private function applier(): DraftApplier {
		return new DraftApplier( $this->services );
	}

	/**
	 * Result notice.
	 */
	public function notice(): void {
		// Display-only flag set by our own redirect; no state change.
		$state    = isset( $_GET['lexranked_draft'] ) ? sanitize_key( wp_unslash( $_GET['lexranked_draft'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'applied' => array( 'success', 'Draft applied to the ranking. The public page updates within a few minutes.' ),
			'partial' => array( 'warning', 'Draft applied, but some fields were invalid and were skipped - check the ranking.' ),
			'ack'     => array( 'error', 'This draft failed automated QA. Confirm that you reviewed every problem before applying it.' ),
			'already' => array( 'info', 'This draft was already applied.' ),
		);
		if ( isset( $messages[ $state ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $state ][0] ), esc_html( $messages[ $state ][1] ) );
		}
	}
}
