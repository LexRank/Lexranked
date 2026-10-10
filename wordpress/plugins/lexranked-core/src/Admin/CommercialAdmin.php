<?php
/**
 * Claims and placements screens.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Admin;

use LexRanked\Core\Commercial\ClaimSignals;
use LexRanked\Core\Commercial\ClaimStatus;
use LexRanked\Core\Commercial\CommercialException;
use LexRanked\Core\Commercial\PlacementPolicy;
use LexRanked\Core\Commercial\Product;
use LexRanked\Core\Schema\ValidationException;
use LexRanked\Core\Security\Capabilities;
use LexRanked\Core\Services;

/**
 * LexRanked → Profile claims (editors): confirm identity, approve or reject.
 * LexRanked → Placements (administrators): premium, featured, sponsored.
 *
 * Claimant contact details are shown only here.
 */
final class CommercialAdmin {

	public const CLAIMS_PAGE    = 'lexranked-claims';
	public const PLACEMENT_PAGE = 'lexranked-placements';
	public const CLAIM_ACT      = 'lexranked_profile_claim';
	public const PLACEMENT_ACT  = 'lexranked_placement';

	private const SIGNAL_TEXT = array(
		'match'     => '✅ matches the profile',
		'mismatch'  => '❌ does not match the profile',
		'unknown'   => '- cannot compare',
		'no_match'  => '⚠️ different domain from the website',
		'free_mail' => '⚠️ free email provider',
	);

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
		add_action( 'admin_menu', array( $this, 'menu' ), 16 );
		add_action( 'admin_post_' . self::CLAIM_ACT, array( $this, 'handle_claim' ) );
		add_action( 'admin_post_' . self::PLACEMENT_ACT, array( $this, 'handle_placement' ) );
	}

	/**
	 * Submenus.
	 */
	public function menu(): void {
		$counts = $this->services->commercial->claims->counts();
		$badge  = $counts[ ClaimStatus::PendingReview->value ] > 0 ? ' <span class="awaiting-mod">' . (int) $counts[ ClaimStatus::PendingReview->value ] . '</span>' : '';
		add_submenu_page( Menu::SLUG, 'Profile claims', 'Profile claims' . $badge, Capabilities::REVIEW_CLAIMS, self::CLAIMS_PAGE, array( $this, 'render_claims' ) );
		add_submenu_page( Menu::SLUG, 'Placements', 'Placements', Capabilities::MANAGE_COMMERCIAL, self::PLACEMENT_PAGE, array( $this, 'render_placements' ) );
	}

	// ---------------------------------------------------------------------
	// Claims.
	// ---------------------------------------------------------------------

	/**
	 * Claims screen.
	 */
	public function render_claims(): void {
		if ( ! current_user_can( Capabilities::REVIEW_CLAIMS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to review claims.', 'lexranked-core' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view parameters.
		$claim_id = isset( $_GET['claim'] ) ? absint( $_GET['claim'] ) : 0;
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ClaimStatus::PendingReview->value;
		// phpcs:enable
		echo '<div class="wrap"><h1>Profile claims</h1>';
		$this->notice();
		echo '<p>Lawyers and firms claim their profile from the public site and confirm their email. <strong>Check the claimant\'s identity yourself</strong> (state bar website, a call to the listed number, a firm email) before approving. An approved claim only shows "Claimed" on the profile and makes it eligible for paid products; it never changes a score or position.</p>';

		if ( $claim_id > 0 ) {
			$row = $this->services->commercial->claims->find( $claim_id );
			if ( null !== $row ) {
				$this->render_claim( $row );
				echo '</div>';
				return;
			}
		}

		$counts = $this->services->commercial->claims->counts();
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( ClaimStatus::values() as $s ) {
			$links[] = sprintf( '<li><a href="%s"%s>%s <span class="count">(%d)</span></a></li>', esc_url( admin_url( 'admin.php?page=' . self::CLAIMS_PAGE . '&status=' . $s ) ), $s === $status ? ' class="current"' : '', esc_html( ucfirst( str_replace( '_', ' ', $s ) ) ), (int) $counts[ $s ] );
		}
		echo implode( ' | ', $links ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.

		$rows = $this->services->commercial->claims->list( in_array( $status, ClaimStatus::values(), true ) ? $status : null, 100 );
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Profile</th><th>Claimant</th><th>Role</th><th>Submitted</th><th>Status</th></tr></thead><tbody>';
		if ( array() === $rows ) {
			echo '<tr><td colspan="6">No claims.</td></tr>';
		}
		foreach ( $rows as $row ) {
			printf(
				'<tr><td><a href="%s">#%d</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( admin_url( 'admin.php?page=' . self::CLAIMS_PAGE . '&claim=' . (int) $row['claim_id'] ) ),
				(int) $row['claim_id'],
				esc_html( get_the_title( (int) $row['entity_id'] ) ),
				esc_html( '1' === (string) $row['personal_data_purged'] ? '(erased)' : (string) $row['claimant_name'] ),
				esc_html( str_replace( '_', ' ', (string) $row['claimant_role'] ) ),
				esc_html( (string) $row['created_at'] ),
				esc_html( str_replace( '_', ' ', (string) $row['status'] ) )
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * One claim with signals and actions.
	 *
	 * @param array<string, mixed> $row Claim row.
	 */
	private function render_claim( array $row ): void {
		$signals = $this->services->commercial->signals( $row );
		$status  = ClaimStatus::from( (string) $row['status'] );
		printf( '<p><a href="%s">&larr; All claims</a></p>', esc_url( admin_url( 'admin.php?page=' . self::CLAIMS_PAGE ) ) );
		printf( '<h2>Claim #%d: %s</h2>', (int) $row['claim_id'], esc_html( get_the_title( (int) $row['entity_id'] ) ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		$line = static function ( string $label, string $value ): void {
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callers escape.
		};
		$line( 'Status', esc_html( str_replace( '_', ' ', $status->value ) ) . ( $signals['already_claimed'] ? ' <strong style="color:#b32d2e">- this profile already has an approved claim</strong>' : '' ) );
		$line( 'Profile', sprintf( '<a href="%s">Edit profile</a>', esc_url( (string) get_edit_post_link( (int) $row['entity_id'] ) ) ) );
		$line( 'Claimant', esc_html( (string) $row['claimant_name'] ) . ' · ' . esc_html( str_replace( '_', ' ', (string) $row['claimant_role'] ) ) );
		$line( 'Email', esc_html( (string) $row['claimant_email'] ) . ( null !== $row['email_verified_at'] ? ' (confirmed ' . esc_html( (string) $row['email_verified_at'] ) . ' UTC) ' : ' (not confirmed) ' ) . esc_html( self::SIGNAL_TEXT[ $signals['email_domain'] ] ?? '' ) . ( '' !== $signals['website'] ? ' - website ' . esc_html( $signals['website'] ) : '' ) );
		$line( 'Phone', esc_html( (string) $row['claimant_phone'] ) );
		if ( 'lawyer' === $row['entity_type'] ) {
			$line( 'Bar number', esc_html( trim( $row['bar_state'] . ' ' . $row['bar_number'] ) ) . ' ' . esc_html( self::SIGNAL_TEXT[ $signals['bar'] ] ?? '' ) . ( '' !== $signals['profile_bar'] ? ' (profile: ' . esc_html( $signals['profile_bar'] ) . ')' : '' ) );
		}
		$line( 'Message', nl2br( esc_html( (string) $row['message'] ) ) );
		if ( '' !== (string) $row['identity_method'] ) {
			$line( 'Identity checked', esc_html( ClaimSignals::IDENTITY_METHODS[ $row['identity_method'] ] ?? (string) $row['identity_method'] ) );
		}
		if ( '' !== (string) $row['review_note'] ) {
			$line( 'Reviewer note', esc_html( (string) $row['review_note'] ) );
		}
		echo '</tbody></table>';

		if ( ! $status->can_become( ClaimStatus::Approved ) && ! $status->can_become( ClaimStatus::Rejected ) ) {
			return;
		}
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::CLAIM_ACT . '_' . (int) $row['claim_id'] );
		printf( '<input type="hidden" name="action" value="%s"><input type="hidden" name="claim" value="%d">', esc_attr( self::CLAIM_ACT ), (int) $row['claim_id'] );
		if ( $status->can_become( ClaimStatus::Approved ) ) {
			echo '<h3>Approve</h3><p><label for="lr-identity">How did you check the claimant\'s identity?</label><br><select id="lr-identity" name="identity_method"><option value="">- choose -</option>';
			foreach ( ClaimSignals::IDENTITY_METHODS as $key => $label ) {
				printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $label ) );
			}
			echo '</select></p>';
		}
		echo '<p><label for="lr-note">Private note (optional)</label><br><textarea id="lr-note" name="note" rows="3" cols="60" maxlength="1000"></textarea></p><p>';
		if ( $status->can_become( ClaimStatus::Approved ) ) {
			echo '<button class="button button-primary" name="decision" value="approve">Approve claim</button> ';
		}
		printf( '<button class="button" name="decision" value="reject">%s</button></p></form>', ClaimStatus::Approved === $status ? 'Revoke claim' : 'Reject claim' );
	}

	/**
	 * Approve / reject.
	 */
	public function handle_claim(): void {
		if ( ! current_user_can( Capabilities::REVIEW_CLAIMS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to review claims.', 'lexranked-core' ), 403 );
		}
		$id = isset( $_POST['claim'] ) ? absint( $_POST['claim'] ) : 0;
		check_admin_referer( self::CLAIM_ACT . '_' . $id );
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$note     = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		try {
			if ( 'approve' === $decision ) {
				$this->services->commercial->approve( $id, isset( $_POST['identity_method'] ) ? sanitize_key( wp_unslash( $_POST['identity_method'] ) ) : '', $note );
				$message = 'Claim approved. The profile now shows "Claimed".';
			} else {
				$this->services->commercial->reject( $id, $note );
				$message = 'Claim rejected.';
			}
		} catch ( CommercialException $e ) {
			$message = $e->getMessage();
		}
		$this->redirect( self::CLAIMS_PAGE . '&claim=' . $id, $message );
	}

	// ---------------------------------------------------------------------
	// Placements.
	// ---------------------------------------------------------------------

	/**
	 * Placements screen.
	 */
	public function render_placements(): void {
		if ( ! current_user_can( Capabilities::MANAGE_COMMERCIAL ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage placements.', 'lexranked-core' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter.
		$edit = isset( $_GET['edit'] ) ? $this->services->commercial->placements->find( absint( $_GET['edit'] ) ) : null;
		echo '<div class="wrap"><h1>Placements</h1>';
		$this->notice();
		echo '<p>Paid products, recorded after payment is arranged outside WordPress. They are <strong>always labelled</strong> on the site and <strong>never change a score or ranking position</strong>. Only published profiles with an approved claim, in good bar standing and in the page\'s location and practice area, can be placed.</p>';
		echo '<ul style="list-style:disc;margin-left:1.5em"><li><strong>Premium</strong>: a labelled message and call-to-action button on the profile page.</li><li><strong>Featured</strong>: a labelled card in the "Featured" block of one state, city or practice-area page (max ' . (int) $this->services->settings->get( 'max_featured_per_page' ) . ' per page).</li><li><strong>Sponsored</strong>: a labelled card in the "Sponsored" block of one ranking page, below and separate from the ranked list (max ' . (int) $this->services->settings->get( 'max_sponsored_per_ranking' ) . ' per ranking).</li></ul>';

		$rows = $this->services->commercial->placements->list( true, 200 );
		$now  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Product</th><th>Profile</th><th>Page</th><th>Period (UTC)</th><th>Status</th><th>Order</th><th></th></tr></thead><tbody>';
		if ( array() === $rows ) {
			echo '<tr><td colspan="8">No placements yet.</td></tr>';
		}
		foreach ( $rows as $row ) {
			$live = PlacementPolicy::is_live( $row, $now );
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s - %s</td><td>%s</td><td>%s</td><td><a href="%s">Edit</a></td></tr>',
				(int) $row['placement_id'],
				esc_html( Product::from( (string) $row['product'] )->label() ),
				esc_html( get_the_title( (int) $row['entity_id'] ) . ' (#' . (int) $row['entity_id'] . ')' ),
				esc_html( $this->scope_label( $row ) ),
				esc_html( substr( (string) $row['starts_at'], 0, 10 ) ),
				esc_html( substr( (string) $row['ends_at'], 0, 10 ) ),
				esc_html( $live ? 'live' : (string) $row['status'] ),
				esc_html( (string) $row['order_ref'] ),
				esc_url( admin_url( 'admin.php?page=' . self::PLACEMENT_PAGE . '&edit=' . (int) $row['placement_id'] ) )
			);
		}
		echo '</tbody></table>';
		$this->render_placement_form( $edit );
		echo '</div>';
	}

	/**
	 * Human label for where a placement appears.
	 *
	 * @param array<string, mixed> $row Placement row.
	 */
	private function scope_label( array $row ): string {
		if ( (int) $row['ranking_id'] > 0 ) {
			return 'Ranking: ' . get_the_title( (int) $row['ranking_id'] );
		}
		foreach ( array( 'location_term_id', 'practice_area_term_id' ) as $key ) {
			if ( (int) $row[ $key ] > 0 ) {
				$term = get_term( (int) $row[ $key ] );
				return $term instanceof \WP_Term ? $term->name : '#' . (int) $row[ $key ];
			}
		}
		return 'Profile page';
	}

	/**
	 * Add / edit form.
	 *
	 * @param array<string, mixed>|null $row Placement being edited.
	 */
	private function render_placement_form( ?array $row ): void {
		$v = $row ?? array(
			'placement_id'          => 0,
			'product'               => 'sponsored',
			'entity_type'           => 'lawyer',
			'entity_id'             => '',
			'ranking_id'            => '',
			'location_term_id'      => '',
			'practice_area_term_id' => '',
			'starts_at'             => gmdate( 'Y-m-d' ),
			'ends_at'               => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
			'status'                => 'active',
			'premium_message'       => '',
			'cta_url'               => '',
			'order_ref'             => '',
			'notes'                 => '',
		);
		printf( '<h2>%s</h2>', null === $row ? 'Add placement' : 'Edit placement #' . (int) $v['placement_id'] );
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::PLACEMENT_ACT );
		printf( '<input type="hidden" name="action" value="%s"><input type="hidden" name="placement" value="%d">', esc_attr( self::PLACEMENT_ACT ), (int) $v['placement_id'] );
		echo '<table class="form-table" role="presentation"><tbody>';
		$select = static function ( string $name, string $label, array $options, string $current ): void {
			printf( '<tr><th scope="row"><label for="lr-%1$s">%2$s</label></th><td><select id="lr-%1$s" name="%1$s">', esc_attr( $name ), esc_html( $label ) );
			foreach ( $options as $value => $text ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $value ), selected( $current, (string) $value, false ), esc_html( $text ) );
			}
			echo '</select></td></tr>';
		};
		$input  = static function ( string $name, string $label, string $value, string $type = 'text', string $help = '' ): void {
			printf( '<tr><th scope="row"><label for="lr-%1$s">%2$s</label></th><td><input id="lr-%1$s" name="%1$s" type="%3$s" value="%4$s" class="regular-text">%5$s</td></tr>', esc_attr( $name ), esc_html( $label ), esc_attr( $type ), esc_attr( $value ), '' === $help ? '' : '<p class="description">' . esc_html( $help ) . '</p>' );
		};
		$select( 'product', 'Product', array_combine( Product::values(), array_map( static fn( Product $p ): string => $p->label(), Product::cases() ) ), (string) $v['product'] );
		$select(
			'entity_type',
			'Profile type',
			array(
				'lawyer'   => 'Lawyer',
				'law_firm' => 'Law firm',
			),
			(string) $v['entity_type']
		);
		$input( 'entity_id', 'Profile ID', (string) $v['entity_id'], 'number', 'The post ID of the lawyer or firm (shown in the profile\'s edit URL).' );
		$input( 'ranking_id', 'Ranking ID (sponsored)', (string) $v['ranking_id'], 'number' );
		$input( 'location_term_id', 'Location term ID (featured)', (string) $v['location_term_id'], 'number', 'A state or city. Leave empty for a practice-area page.' );
		$input( 'practice_area_term_id', 'Practice area term ID (featured)', (string) $v['practice_area_term_id'], 'number' );
		$input( 'starts_at', 'Starts (UTC date)', substr( (string) $v['starts_at'], 0, 10 ), 'date' );
		$input( 'ends_at', 'Ends (UTC date, exclusive)', substr( (string) $v['ends_at'], 0, 10 ), 'date' );
		$select(
			'status',
			'Status',
			array(
				'active'    => 'Active',
				'paused'    => 'Paused',
				'cancelled' => 'Cancelled',
			),
			(string) $v['status']
		);
		printf( '<tr><th scope="row"><label for="lr-premium_message">Premium message</label></th><td><textarea id="lr-premium_message" name="premium_message" rows="4" cols="60" maxlength="%d">%s</textarea><p class="description">Premium only. Plain text from the profile owner, no links, no claims you cannot verify ("best", "guaranteed"). Shown labelled as paid content.</p></td></tr>', (int) PlacementPolicy::MAX_MESSAGE, esc_textarea( (string) $v['premium_message'] ) );
		$input( 'cta_url', 'Call-to-action URL (premium)', (string) $v['cta_url'], 'url', 'https only. Rendered with rel="sponsored".' );
		$input( 'order_ref', 'Order reference (private)', (string) $v['order_ref'] );
		printf( '<tr><th scope="row"><label for="lr-notes">Notes (private)</label></th><td><textarea id="lr-notes" name="notes" rows="2" cols="60">%s</textarea></td></tr>', esc_textarea( (string) $v['notes'] ) );
		echo '</tbody></table>';
		submit_button( null === $row ? 'Add placement' : 'Save placement' );
		echo '</form>';
	}

	/**
	 * Save a placement.
	 */
	public function handle_placement(): void {
		if ( ! current_user_can( Capabilities::MANAGE_COMMERCIAL ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage placements.', 'lexranked-core' ), 403 );
		}
		check_admin_referer( self::PLACEMENT_ACT );
		$id    = isset( $_POST['placement'] ) ? absint( $_POST['placement'] ) : 0;
		$input = array();
		foreach ( array( 'product', 'entity_type', 'entity_id', 'ranking_id', 'location_term_id', 'practice_area_term_id', 'starts_at', 'ends_at', 'status', 'cta_url', 'order_ref' ) as $key ) {
			$input[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		foreach ( array( 'premium_message', 'notes' ) as $key ) {
			$input[ $key ] = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		try {
			$id      = $this->services->commercial->save_placement( $input, $id );
			$message = 'Placement saved.';
		} catch ( ValidationException $e ) {
			$message = 'Not saved: ' . str_replace( '_', ' ', $e->field_key ) . ' ' . $e->reason . '.';
		} catch ( CommercialException $e ) {
			$message = 'Not saved. ' . $e->getMessage();
		}
		$this->redirect( self::PLACEMENT_PAGE . ( $id > 0 ? '&edit=' . $id : '' ), $message );
	}

	// ---------------------------------------------------------------------

	/**
	 * Redirect with a one-time notice.
	 *
	 * @param string $page    Page query (after page=).
	 * @param string $message Notice.
	 */
	private function redirect( string $page, string $message ): void {
		set_transient( 'lexranked_commercial_notice_' . get_current_user_id(), $message, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	/**
	 * Print and clear the notice.
	 */
	private function notice(): void {
		$key     = 'lexranked_commercial_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( $key );
			printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
	}
}
