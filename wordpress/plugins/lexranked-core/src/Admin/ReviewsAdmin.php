<?php
/**
 * Client review moderation screen.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Admin;

use LexRanked\Core\Reviews\ReviewException;
use LexRanked\Core\Reviews\ReviewStatus;
use LexRanked\Core\Services;

/**
 * LexRanked → Client reviews: approve or reject reviews whose email was
 * confirmed. Only approved reviews are published and counted.
 */
final class ReviewsAdmin {

	public const PAGE = 'lexranked-reviews';
	public const ACT  = 'lexranked_client_review';

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 17 );
		add_action( 'admin_post_' . self::ACT, array( $this, 'handle' ) );
	}

	/**
	 * Submenu with the pending count.
	 */
	public function menu(): void {
		$pending = $this->services->reviews->reviews->counts()[ ReviewStatus::PendingReview->value ] ?? 0;
		$badge   = $pending > 0 ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '';
		add_submenu_page( Menu::SLUG, 'Client reviews', 'Client reviews' . $badge, 'edit_posts', self::PAGE, array( $this, 'render' ) );
	}

	/**
	 * Screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to moderate reviews.', 'lexranked-core' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ReviewStatus::PendingReview->value;
		$status = null === ReviewStatus::tryFrom( $status ) ? ReviewStatus::PendingReview->value : $status;
		$counts = $this->services->reviews->reviews->counts();
		echo '<div class="wrap"><h1>Client reviews</h1>';
		echo '<p>Reviews are published only after the reviewer confirmed their email and an editor approved them here. Approve reviews that describe a real client experience; reject spam, conflicts of interest, personal attacks, confidential details or reviews about someone else.</p>';
		echo '<ul class="subsubsub">';
		foreach ( ReviewStatus::cases() as $case ) {
			printf(
				'<li><a href="%s"%s>%s (%d)</a> | </li>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&status=' . $case->value ) ),
				$case->value === $status ? ' class="current"' : '',
				esc_html( ucfirst( str_replace( '_', ' ', $case->value ) ) ),
				(int) ( $counts[ $case->value ] ?? 0 )
			);
		}
		echo '</ul><br class="clear">';
		$rows = $this->services->reviews->reviews->by_status( $status );
		if ( array() === $rows ) {
			echo '<p>No reviews here.</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Profile</th><th>Rating</th><th>Review</th><th>Reviewer</th><th>Submitted</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			printf( '<td><a href="%s">%s</a></td>', esc_url( (string) get_edit_post_link( (int) $r['entity_id'] ) ), esc_html( get_the_title( (int) $r['entity_id'] ) ) );
			printf( '<td>%s</td>', esc_html( str_repeat( '★', (int) $r['rating'] ) . str_repeat( '☆', 5 - (int) $r['rating'] ) ) );
			printf( '<td>%s%s</td>', '' === (string) $r['title'] ? '' : '<strong>' . esc_html( (string) $r['title'] ) . '</strong><br>', nl2br( esc_html( (string) $r['body'] ) ) );
			printf( '<td>%s<br><span class="description">client in %d</span></td>', esc_html( (string) $r['display_name'] ), (int) $r['service_year'] );
			printf( '<td>%s</td>', esc_html( (string) $r['created_at'] ) );
			echo '<td>';
			foreach ( array( 'approve', 'reject' ) as $action ) {
				$to = 'approve' === $action ? ReviewStatus::Approved : ReviewStatus::Rejected;
				if ( ! ReviewStatus::from( (string) $r['status'] )->can_become( $to ) || ( 'approve' === $action && ReviewStatus::PendingEmail->value === $r['status'] ) ) {
					continue;
				}
				printf(
					'<form method="post" action="%s" style="display:inline-block;margin:0 4px 4px 0">%s<input type="hidden" name="action" value="%s"><input type="hidden" name="review" value="%d"><input type="hidden" name="decision" value="%s"><button class="button%s">%s</button></form>',
					esc_url( admin_url( 'admin-post.php' ) ),
					wp_nonce_field( self::ACT . '_' . (int) $r['review_id'], '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-generated nonce field markup.
					esc_attr( self::ACT ),
					(int) $r['review_id'],
					esc_attr( $action ),
					'approve' === $action ? ' button-primary' : '',
					esc_html( ucfirst( $action ) )
				);
			}
			echo '</td></tr>';
		}//end foreach
		echo '</tbody></table></div>';
	}

	/**
	 * Approve or reject.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to moderate reviews.', 'lexranked-core' ) );
		}
		$id = isset( $_POST['review'] ) ? absint( $_POST['review'] ) : 0;
		check_admin_referer( self::ACT . '_' . $id );
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$message  = 'approve' === $decision ? 'approved' : 'rejected';
		try {
			$this->services->reviews->moderate( $id, 'approve' === $decision );
		} catch ( ReviewException $e ) {
			$message = 'error';
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&done=' . $message ) );
		exit;
	}
}
