<?php
/**
 * AI content draft review screen.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Admin;

use LexRanked\Core\PostTypes\ContentDraft;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\Security\AuditLog;
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
		$target = null === $fields['target_id'] ? null : get_post( (int) $fields['target_id'] );

		$labels = array(
			ContentDraft::QA_READY        => '✅ Ready for review — automated checks passed',
			ContentDraft::QA_NEEDS_REVIEW => '⚠️ Needs review — automated checks found problems',
			ContentDraft::QA_APPLIED      => '✔ Applied',
		);
		printf( '<p><strong>%s</strong></p>', esc_html( $labels[ (string) $fields['qa_status'] ] ?? (string) $fields['qa_status'] ) );
		printf(
			'<p>Target: %s · Model: <code>%s</code> · Prompt: <code>%s</code> · Job #%d</p>',
			$target instanceof \WP_Post ? '<a href="' . esc_url( (string) get_edit_post_link( $target->ID ) ) . '">' . esc_html( get_the_title( $target ) ) . '</a>' : '—',
			esc_html( (string) $fields['model'] ),
			esc_html( (string) $fields['prompt_version'] ),
			(int) $fields['job_id']
		);
		echo '<p class="description">Generated from the numbered facts below only. Automated QA checks every number, name and ranking position against those facts; it cannot judge tone or usefulness — that is your review.</p>';

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
			return;
		}
		if ( ! $target instanceof \WP_Post || ! current_user_can( 'edit_post', $target->ID ) ) {
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
		printf(
			'<p><button type="submit" class="button button-primary" form="%s">Apply to ranking</button> <span class="description">Replaces the ranking\'s summary, body and FAQ with this draft (save your edits here first). The previous body stays in the ranking\'s revisions.</span></p>',
			esc_attr( $form_id )
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
			echo '<p>—</p>';
			return;
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( $facts as $fact ) {
			printf( '<tr><td style="width:50px"><code>%s</code></td><td style="width:35%%">%s</td><td>%s</td></tr>', esc_html( (string) ( $fact['id'] ?? '' ) ), esc_html( (string) ( $fact['label'] ?? '' ) ), esc_html( (string) ( $fact['value'] ?? '' ) ) );
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
		$type   = $this->services->content_draft;
		$fields = $this->services->entities->record( $post, $type )['fields'];
		$target = get_post( (int) $fields['target_id'] );
		if ( ! $target instanceof \WP_Post || Ranking::SLUG !== $target->post_type || ! current_user_can( 'edit_post', $target->ID ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'lexranked-core' ), 403 );
		}
		$back = (string) get_edit_post_link( $id, 'url' );
		if ( ContentDraft::QA_APPLIED === $fields['qa_status'] ) {
			wp_safe_redirect( add_query_arg( 'lexranked_draft', 'already', $back ) );
			exit;
		}
		if ( ContentDraft::QA_NEEDS_REVIEW === $fields['qa_status'] && empty( $_POST['acknowledge'] ) ) {
			wp_safe_redirect( add_query_arg( 'lexranked_draft', 'ack', $back ) );
			exit;
		}

		$faq    = is_array( $fields['faq'] ) ? $fields['faq'] : array();
		$errors = $this->services->entities->save_fields(
			$target->ID,
			$this->services->ranking,
			array(
				'summary' => $fields['summary'],
				'faq'     => $faq,
			)
		);
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $target->ID,
					'post_content' => (string) $post->post_content,
				)
			)
		);
		$this->services->entities->save_fields(
			$id,
			$type,
			array(
				'qa_status'  => ContentDraft::QA_APPLIED,
				'applied_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			),
			true
		);
		AuditLog::log(
			'content_draft.applied',
			ContentDraft::SLUG,
			$id,
			array(
				'ranking_id'   => $target->ID,
				'acknowledged' => ! empty( $_POST['acknowledge'] ),
				'invalid'      => array_keys( $errors ),
			)
		);
		wp_safe_redirect( add_query_arg( 'lexranked_draft', array() === $errors ? 'applied' : 'partial', $back ) );
		exit;
	}

	/**
	 * Result notice.
	 */
	public function notice(): void {
		// Display-only flag set by our own redirect; no state change.
		$state    = isset( $_GET['lexranked_draft'] ) ? sanitize_key( wp_unslash( $_GET['lexranked_draft'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'applied' => array( 'success', 'Draft applied to the ranking. The public page updates within a few minutes.' ),
			'partial' => array( 'warning', 'Draft applied, but some fields were invalid and were skipped — check the ranking.' ),
			'ack'     => array( 'error', 'This draft failed automated QA. Confirm that you reviewed every problem before applying it.' ),
			'already' => array( 'info', 'This draft was already applied.' ),
		);
		if ( isset( $messages[ $state ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $state ][0] ), esc_html( $messages[ $state ][1] ) );
		}
	}
}
