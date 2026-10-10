<?php
/**
 * Research admin screens.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Admin;

use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\ResearchJob;
use LexRanked\Core\Repository\ClaimRepository;
use LexRanked\Core\Research\CandidateRepository;
use LexRanked\Core\Research\JobException;
use LexRanked\Core\Research\ResearchIngest;
use LexRanked\Core\Security\AuditLog;
use LexRanked\Core\Services;

/**
 * - Research job screen: progress, statistics and the job log.
 * - "Research review" page: candidates the matcher could not decide, and
 *   evidence about published entities waiting for an editor. Nothing from
 *   research becomes public until someone acts here (or publishes a draft).
 */
final class ResearchAdmin {

	public const PAGE          = 'lexranked-research';
	public const CANDIDATE_ACT = 'lexranked_candidate';
	public const CLAIM_ACT     = 'lexranked_claim_review';
	public const CAPABILITY    = 'edit_others_posts';

	/** Fields "approve & apply" may write directly (simple meta fields). */
	private const NON_META_FIELDS = array( 'name', 'city', 'state', 'practice_areas' );

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
		add_action( 'admin_menu', array( $this, 'menu' ), 15 );
		add_action(
			'add_meta_boxes_' . ResearchJob::SLUG,
			fn() => add_meta_box( 'lexranked-research-progress', 'Progress & log', array( $this, 'render_job' ), ResearchJob::SLUG, 'normal', 'default' )
		);
		add_action( 'admin_post_' . self::CANDIDATE_ACT, array( $this, 'handle_candidate' ) );
		add_action( 'admin_post_' . self::CLAIM_ACT, array( $this, 'handle_claim' ) );
	}

	/**
	 * Submenu.
	 */
	public function menu(): void {
		add_submenu_page( Menu::SLUG, 'Research review', 'Research review', self::CAPABILITY, self::PAGE, array( $this, 'render_page' ) );
	}

	/**
	 * Job progress box.
	 *
	 * @param \WP_Post $post Job post.
	 */
	public function render_job( \WP_Post $post ): void {
		try {
			$job = $this->services->jobs->view( (int) $post->ID );
		} catch ( JobException $e ) {
			echo '<p>Save the job first.</p>';
			return;
		}
		$rows = array(
			'Status'            => (string) $job['status'],
			'Worker'            => (string) ( $job['worker'] ?? '-' ),
			'Processed records' => (string) $job['processedCount'],
			'Cursor'            => (string) ( $job['cursor'] ?? '-' ),
			'Retries used'      => $job['retryCount'] . ' / ' . (int) $this->services->settings->get( 'research_max_retries' ),
			'Lease expires'     => (string) ( $job['lockedUntil'] ?? '-' ),
			'Next retry'        => (string) ( $job['nextRetryAt'] ?? '-' ),
			'Last error'        => (string) ( $job['error'] ?? '-' ),
		);
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th style="width:180px">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		if ( is_array( $job['stats'] ) && array() !== $job['stats'] ) {
			$parts = array();
			foreach ( $job['stats'] as $key => $value ) {
				$parts[] = $key . ': ' . $value;
			}
			printf( '<tr><th>Statistics</th><td><code>%s</code></td></tr>', esc_html( implode( ' · ', $parts ) ) );
		}
		$counts = array_filter( $this->services->candidates->counts( (int) $post->ID ) );
		if ( array() !== $counts ) {
			$parts = array();
			foreach ( $counts as $status => $n ) {
				$parts[] = $status . ': ' . $n;
			}
			printf( '<tr><th>Candidates</th><td>%s</td></tr>', esc_html( implode( ' · ', $parts ) ) );
		}
		echo '</tbody></table>';

		$log = $this->services->research_log->for_job( (int) $post->ID, 100 );
		echo '<h4>Log (latest 100)</h4>';
		if ( array() === $log ) {
			echo '<p>No entries yet.</p>';
			return;
		}
		echo '<div style="max-height:360px;overflow:auto"><table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Level</th><th>Stage</th><th>Message</th></tr></thead><tbody>';
		foreach ( array_reverse( $log ) as $entry ) {
			printf(
				'<tr><td style="white-space:nowrap">%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $entry['createdAt'] ) ),
				esc_html( $entry['level'] ),
				esc_html( $entry['stage'] ),
				esc_html( $entry['message'] )
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Review page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'lexranked-core' ) );
		}
		echo '<div class="wrap"><h1>Research review</h1>';
		$this->notice();
		echo '<p>Research never publishes by itself. New lawyers and firms arrive as <strong>drafts</strong>; evidence about published profiles waits here until an editor approves it.</p>';

		$counts = $this->services->candidates->counts();
		printf(
			'<p>Candidates - matched: %d · drafted: %d · needs review: %d · rejected: %d. <a href="%s">Research drafts: lawyers</a> · <a href="%s">law firms</a></p>',
			(int) $counts['matched'],
			(int) $counts['created'],
			(int) $counts['needs_review'],
			(int) $counts['rejected'],
			esc_url( admin_url( 'edit.php?post_status=draft&post_type=' . Lawyer::SLUG ) ),
			esc_url( admin_url( 'edit.php?post_status=draft&post_type=' . LawFirm::SLUG ) )
		);

		$this->render_candidates();
		$this->render_claims();
		echo '</div>';
	}

	/**
	 * Candidates needing a decision.
	 */
	private function render_candidates(): void {
		$items = $this->services->candidates->list( CandidateRepository::STATUS_NEEDS_REVIEW, 50 );
		echo '<h2>Candidates needing a decision</h2>';
		if ( array() === $items ) {
			echo '<p>None.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Candidate</th><th>Location</th><th>Source</th><th>Why</th><th>Decision</th></tr></thead><tbody>';
		foreach ( $items as $c ) {
			$suggested = '';
			if ( is_array( $c['aiNote'] ) ) {
				$verdicts   = array(
					'same'      => 'probably the same',
					'different' => 'probably different',
					'unsure'    => 'unsure',
				);
				$suggested .= sprintf(
					'<br><em>AI suggestion (advisory): %s, %d%% - %s</em>',
					esc_html( $verdicts[ (string) $c['aiNote']['verdict'] ] ?? '' ),
					(int) round( 100 * (float) $c['aiNote']['confidence'] ),
					esc_html( (string) $c['aiNote']['reason'] )
				);
			}
			if ( null !== $c['entityId'] ) {
				$suggested .= sprintf( '<br>Possible match: <a href="%s">%s (#%d)</a>', esc_url( (string) get_edit_post_link( $c['entityId'] ) ), esc_html( get_the_title( $c['entityId'] ) ), (int) $c['entityId'] );
			}
			printf(
				'<tr><td><strong>%s</strong><br>%s</td><td>%s</td><td><a href="%s" rel="noopener noreferrer" target="_blank">%s</a><br><small>%s</small></td><td>%s%s</td><td>',
				esc_html( $c['name'] ),
				esc_html( 'law_firm' === $c['entityType'] ? 'Law firm' : 'Lawyer' ),
				esc_html( trim( ( $c['city'] ?? '' ) . ', ' . ( $c['state'] ?? '' ), ', ' ) ),
				esc_url( $c['sourceUrl'] ),
				esc_html( (string) wp_parse_url( $c['sourceUrl'], PHP_URL_HOST ) ),
				esc_html( $c['sourceType'] ),
				esc_html( (string) $c['reason'] ),
				$suggested // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
			);
			$this->candidate_form( $c['id'], 'match', 'Same as #', $c['entityId'] );
			$this->candidate_form( $c['id'], 'create', 'Create draft' );
			$this->candidate_form( $c['id'], 'reject', 'Reject' );
			echo '</td></tr>';
		}//end foreach
		echo '</tbody></table>';
	}

	/**
	 * One decision button (with entity ID input for "match").
	 *
	 * @param int      $candidate_id Candidate ID.
	 * @param string   $decision     Decision.
	 * @param string   $label        Button label.
	 * @param int|null $entity_id    Prefilled entity ID.
	 */
	private function candidate_form( int $candidate_id, string $decision, string $label, ?int $entity_id = null ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 4px 0">';
		wp_nonce_field( self::CANDIDATE_ACT . '_' . $candidate_id );
		printf( '<input type="hidden" name="action" value="%s"><input type="hidden" name="candidate" value="%d"><input type="hidden" name="decision" value="%s">', esc_attr( self::CANDIDATE_ACT ), (int) $candidate_id, esc_attr( $decision ) );
		if ( 'match' === $decision ) {
			printf( '<button class="button">%s</button><input type="number" min="1" name="entity_id" value="%s" style="width:90px">', esc_html( $label ), null === $entity_id ? '' : (int) $entity_id );
		} else {
			printf( '<button class="button%s">%s</button>', 'create' === $decision ? ' button-primary' : '', esc_html( $label ) );
		}
		echo '</form>';
	}

	/**
	 * Evidence about published entities awaiting review.
	 */
	private function render_claims(): void {
		$claims = $this->services->claims->pending_review( 100 );
		$tiers  = $this->services->settings->source_tiers();
		echo '<h2>New evidence for published profiles</h2>';
		if ( array() === $claims ) {
			echo '<p>None.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Profile</th><th>Field</th><th>Current</th><th>Evidence</th><th>Source</th><th>Decision</th></tr></thead><tbody>';
		foreach ( $claims as $claim ) {
			$post    = get_post( $claim['entity_id'] );
			$type    = 'law_firm' === $claim['entity_type'] ? $this->services->law_firm : $this->services->lawyer;
			$current = '-';
			if ( $post instanceof \WP_Post ) {
				$record  = $this->services->entities->record( $post, $type );
				$current = 'name' === $claim['field_name'] ? $record['title'] : self::display( $record['fields'][ $claim['field_name'] ] ?? null );
			}
			printf(
				'<tr><td><a href="%s">%s</a></td><td><code>%s</code></td><td>%s</td><td><strong>%s</strong></td><td><a href="%s" rel="noopener noreferrer" target="_blank">%s</a><br><small>%s · tier %d · %s</small></td><td>',
				esc_url( (string) get_edit_post_link( $claim['entity_id'] ) ),
				esc_html( get_the_title( $claim['entity_id'] ) ),
				esc_html( $claim['field_name'] ),
				esc_html( $current ),
				esc_html( self::display( $claim['value'] ) ),
				esc_url( $claim['source_url'] ),
				esc_html( (string) wp_parse_url( $claim['source_url'], PHP_URL_HOST ) ),
				esc_html( $claim['source_type'] ),
				(int) $tiers->tier_for( $claim['source_type'] ),
				esc_html( substr( $claim['retrieved_at'], 0, 10 ) )
			);
			$decisions = array( 'approve' => 'Approve evidence' );
			if ( ! in_array( $claim['field_name'], self::NON_META_FIELDS, true ) ) {
				$decisions['apply'] = 'Approve & apply value';
			}
			$decisions['reject'] = 'Reject';
			foreach ( $decisions as $decision => $label ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 4px 0">';
				wp_nonce_field( self::CLAIM_ACT . '_' . $claim['claim_id'] );
				printf(
					'<input type="hidden" name="action" value="%s"><input type="hidden" name="claim" value="%d"><input type="hidden" name="decision" value="%s"><button class="button%s">%s</button></form>',
					esc_attr( self::CLAIM_ACT ),
					(int) $claim['claim_id'],
					esc_attr( $decision ),
					'apply' === $decision ? ' button-primary' : '',
					esc_html( $label )
				);
			}
			echo '</td></tr>';
		}//end foreach
		echo '</tbody></table>';
	}

	/**
	 * Candidate decision handler.
	 */
	public function handle_candidate(): void {
		$id = isset( $_POST['candidate'] ) ? absint( $_POST['candidate'] ) : 0;
		check_admin_referer( self::CANDIDATE_ACT . '_' . $id );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'lexranked-core' ), 403 );
		}
		$decision  = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$entity_id = isset( $_POST['entity_id'] ) && '' !== $_POST['entity_id'] ? absint( $_POST['entity_id'] ) : null;
		try {
			$result  = $this->services->ingest->resolve_candidate( $id, $decision, $entity_id, 'editor decision' );
			$message = 'Candidate ' . $result['status'] . ( null !== $result['entityId'] ? ' → #' . $result['entityId'] : '' ) . '.';
		} catch ( JobException $e ) {
			$message = $e->getMessage();
		}
		$this->back( $message );
	}

	/**
	 * Claim review handler.
	 */
	public function handle_claim(): void {
		$id = isset( $_POST['claim'] ) ? absint( $_POST['claim'] ) : 0;
		check_admin_referer( self::CLAIM_ACT . '_' . $id );
		$claim = $this->services->claims->find( $id );
		if ( null === $claim || ! current_user_can( 'edit_post', $claim['entity_id'] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'lexranked-core' ), 403 );
		}
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$message  = 'Evidence rejected.';
		if ( 'reject' === $decision ) {
			$this->services->claims->set_review_status( $id, ClaimRepository::REVIEW_REJECTED );
		} elseif ( in_array( $decision, array( 'approve', 'apply' ), true ) ) {
			$this->services->claims->set_review_status( $id, ClaimRepository::REVIEW_APPROVED );
			$message = 'Evidence approved.';
			if ( 'apply' === $decision && ! in_array( $claim['field_name'], self::NON_META_FIELDS, true ) ) {
				$type    = 'law_firm' === $claim['entity_type'] ? $this->services->law_firm : $this->services->lawyer;
				$errors  = $this->services->entities->save_fields( $claim['entity_id'], $type, array( $claim['field_name'] => $claim['value'] ) );
				$message = array() === $errors ? 'Evidence approved and value applied.' : 'Evidence approved; value not applied: ' . implode( ' ', $errors );
			}
			$this->services->runner->schedule_soon();
		}
		if ( array() === array_filter( $this->services->claims->pending_review( 500 ), static fn( array $c ): bool => $c['entity_id'] === $claim['entity_id'] ) ) {
			delete_post_meta( $claim['entity_id'], ResearchIngest::META_REVIEW );
		}
		AuditLog::log( 'research.claim_' . $decision, 'lr_claim', $id, array( 'entity_id' => $claim['entity_id'] ) );
		$this->back( $message );
	}

	/**
	 * Redirect back to the review page with a message.
	 *
	 * @param string $message Message.
	 */
	private function back( string $message ): void {
		set_transient( 'lexranked_research_notice_' . get_current_user_id(), $message, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Flash message.
	 */
	private function notice(): void {
		$key     = 'lexranked_research_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( $key );
			printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
	}

	/**
	 * Human-readable value.
	 *
	 * @param mixed $value Value.
	 */
	private static function display( mixed $value ): string {
		if ( null === $value || '' === $value ) {
			return '-';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return (string) wp_json_encode( $value );
	}
}
