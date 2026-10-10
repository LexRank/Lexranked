<?php
/**
 * Ranking calculation meta box.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Admin;

use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\Services;

/**
 * Shows the latest calculation of a ranking and lets editors recalculate it.
 * Positions can only change through the engine - never by hand.
 */
final class RankingCalculation {

	private const ACTION = 'lexranked_recalculate_ranking';

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
			'add_meta_boxes_' . Ranking::SLUG,
			fn() => add_meta_box( 'lexranked-calculation', 'Calculation', array( $this, 'render' ), Ranking::SLUG, 'side', 'high' )
		);
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Render the box.
	 *
	 * @param \WP_Post $post Ranking.
	 */
	public function render( \WP_Post $post ): void {
		$runs = $this->services->snapshots->run_ids( (int) $post->ID, 1 );
		$rows = array() === $runs ? array() : $this->services->snapshots->run_rows( $runs[0] );
		if ( array() === $rows ) {
			echo '<p>Not calculated yet.</p>';
		} else {
			printf(
				'<p>Last calculated <strong>%s UTC</strong><br>%d entries · %s</p>',
				esc_html( str_replace( array( 'T', 'Z' ), array( ' ', '' ), (string) $rows[0]['calculated_at'] ) ),
				count( $rows ),
				esc_html( (string) $rows[0]['score_version'] )
			);
		}
		if ( 'publish' !== $post->post_status ) {
			echo '<p class="description">Publish the ranking to calculate it.</p>';
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&ranking=' . (int) $post->ID ), self::ACTION . '_' . (int) $post->ID );
		printf( '<p><a class="button" href="%s">Recalculate now</a></p>', esc_url( $url ) );
		echo '<p class="description">Recalculation also runs daily and shortly after edits.</p>';
	}

	/**
	 * Handle the recalculation request.
	 */
	public function handle(): void {
		$ranking_id = isset( $_GET['ranking'] ) ? absint( $_GET['ranking'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $ranking_id );
		if ( ! current_user_can( 'edit_post', $ranking_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'lexranked-core' ), 403 );
		}
		$this->services->runner->score_entities( 'lr_lawyer' );
		$this->services->runner->score_entities( 'lr_law_firm' );
		$result = $this->services->runner->run_ranking( $ranking_id );
		wp_safe_redirect( add_query_arg( 'lexranked_recalculated', (int) $result['entries'], (string) get_edit_post_link( $ranking_id, 'url' ) ) );
		exit;
	}

	/**
	 * Confirmation notice.
	 */
	public function notice(): void {
		// Display-only flag set by our own redirect; no state change.
		if ( ! isset( $_GET['lexranked_recalculated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$count = absint( $_GET['lexranked_recalculated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf( '<div class="notice notice-success is-dismissible"><p>Ranking recalculated: %d entries.</p></div>', (int) $count );
	}
}
