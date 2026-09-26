<?php
/**
 * Admin menu, dashboard and settings screen.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Admin;

use LexRanked\Core\Domain\VerificationType;
use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\Security\AuditLog;
use LexRanked\Core\Services;
use LexRanked\Core\Settings\Settings;
use LexRanked\Core\Sources\SourceTiers;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * "LexRanked" top-level menu.
 */
final class Menu {

	public const SLUG          = 'lexranked';
	public const SETTINGS_SLUG = 'lexranked-settings';

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
		add_action( 'admin_menu', array( $this, 'menu' ), 9 );
		add_action( 'admin_menu', array( $this, 'taxonomy_menus' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'parent_file', array( $this, 'highlight_parent' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'settings_changed' ) );
	}

	/**
	 * Top-level page + dashboard + settings.
	 */
	public function menu(): void {
		add_menu_page( 'LexRanked', 'LexRanked', 'edit_posts', self::SLUG, array( $this, 'render_dashboard' ), 'dashicons-chart-bar', 3 );
		add_submenu_page( self::SLUG, 'LexRanked Overview', 'Overview', 'edit_posts', self::SLUG, array( $this, 'render_dashboard' ) );
	}

	/**
	 * Taxonomy and settings submenus (after CPT submenus).
	 */
	public function taxonomy_menus(): void {
		add_submenu_page( self::SLUG, 'Locations', 'Locations', 'manage_categories', 'edit-tags.php?taxonomy=' . Location::SLUG . '&post_type=' . Lawyer::SLUG );
		add_submenu_page( self::SLUG, 'Practice Areas', 'Practice Areas', 'manage_categories', 'edit-tags.php?taxonomy=' . PracticeArea::SLUG . '&post_type=' . Lawyer::SLUG );
		add_submenu_page( self::SLUG, 'LexRanked Settings', 'Settings', 'manage_options', self::SETTINGS_SLUG, array( $this, 'render_settings' ) );
	}

	/**
	 * Keep the LexRanked menu open on taxonomy screens.
	 *
	 * @param string $parent_file Parent file.
	 */
	public function highlight_parent( string $parent_file ): string {
		$screen = get_current_screen();
		if ( null !== $screen && in_array( $screen->taxonomy, array( Location::SLUG, PracticeArea::SLUG ), true ) ) {
			return self::SLUG;
		}
		return $parent_file;
	}

	/**
	 * Settings API registration.
	 */
	public function register_settings(): void {
		register_setting(
			'lexranked',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Audit + cache reset on change.
	 */
	public function settings_changed(): void {
		$this->services->settings->reset();
		AuditLog::log( 'settings.updated', 'option', 0 );
	}

	/**
	 * Overview dashboard.
	 */
	public function render_dashboard(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'lexranked-core' ) );
		}
		echo '<div class="wrap"><h1>LexRanked — Overview</h1>';

		echo '<h2>Content</h2><table class="widefat striped" style="max-width:720px"><thead><tr><th>Entity</th><th>Published</th><th>Draft / pending</th><th></th></tr></thead><tbody>';
		foreach ( $this->services->post_types() as $type ) {
			$object = get_post_type_object( $type->slug() );
			if ( null === $object || ! current_user_can( $object->cap->edit_posts ) ) {
				continue;
			}
			$counts = wp_count_posts( $type->slug() );
			printf(
				'<tr><td>%s</td><td>%d</td><td>%d</td><td><a href="%s">Manage</a></td></tr>',
				esc_html( $type->plural() ),
				(int) ( $counts->publish ?? 0 ),
				(int) ( $counts->draft ?? 0 ) + (int) ( $counts->pending ?? 0 ),
				esc_url( admin_url( 'edit.php?post_type=' . $type->slug() ) )
			);
		}
		echo '</tbody></table>';

		$status_url = rest_url( Plugin::REST_NAMESPACE . '/status' );
		echo '<h2>API</h2><ul>';
		printf( '<li>Status endpoint: <a href="%1$s" target="_blank" rel="noopener">%1$s</a></li>', esc_url( $status_url ) );
		printf( '<li>Plugin version: <code>%s</code> · API version: <code>%s</code></li>', esc_html( LEXRANKED_CORE_VERSION ), esc_html( Plugin::API_VERSION ) );
		$frontend = (string) $this->services->settings->get( 'frontend_url' );
		printf( '<li>Frontend: %s</li>', '' === $frontend ? 'not configured (Settings)' : '<a href="' . esc_url( $frontend ) . '" target="_blank" rel="noopener">' . esc_html( $frontend ) . '</a>' );
		echo '</ul>';

		if ( current_user_can( 'manage_options' ) ) {
			echo '<h2>Recent activity</h2><table class="widefat striped" style="max-width:720px"><thead><tr><th>When (UTC)</th><th>User</th><th>Action</th><th>Object</th></tr></thead><tbody>';
			$rows = AuditLog::recent( 15 );
			if ( array() === $rows ) {
				echo '<tr><td colspan="4">No activity yet.</td></tr>';
			}
			foreach ( $rows as $row ) {
				$user = get_userdata( (int) $row->user_id );
				printf(
					'<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
					esc_html( (string) $row->occurred_at ),
					esc_html( $user ? $user->user_login : 'system' ),
					esc_html( (string) $row->action ),
					(int) $row->object_id > 0 ? '<a href="' . esc_url( (string) get_edit_post_link( (int) $row->object_id ) ) . '">#' . (int) $row->object_id . '</a>' : '—'
				);
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/**
	 * Settings screen.
	 */
	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'lexranked-core' ) );
		}
		$s    = $this->services->settings->all();
		$name = Settings::OPTION;

		echo '<div class="wrap"><h1>LexRanked Settings</h1><form method="post" action="options.php">';
		settings_fields( 'lexranked' );
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="lr-frontend">Frontend URL</label></th><td><input type="url" id="lr-frontend" class="regular-text" name="%s[frontend_url]" value="%s" placeholder="https://lexranked.com"><p class="description">Public Next.js site.</p></td></tr>',
			esc_attr( $name ),
			esc_attr( (string) $s['frontend_url'] )
		);
		printf(
			'<tr><th scope="row">Headless redirect</th><td><input type="hidden" name="%1$s[headless_redirect]" value="0"><label><input type="checkbox" name="%1$s[headless_redirect]" value="1"%2$s> Redirect anonymous visitors of WordPress front-end pages to the frontend URL</label></td></tr>',
			esc_attr( $name ),
			checked( (bool) $s['headless_redirect'], true, false )
		);
		printf(
			'<tr><th scope="row"><label for="lr-tiers">Source tiers</label></th><td><textarea id="lr-tiers" rows="8" class="large-text code" name="%s[source_tiers]">%s</textarea><p class="description">One per line: <code>source_type = tier</code> (1 = most authoritative, 5 = least).</p></td></tr>',
			esc_attr( $name ),
			esc_textarea( SourceTiers::format( $s['source_tiers'] ) )
		);
		printf(
			'<tr><th scope="row"><label for="lr-fresh">Freshness rules (days)</label></th><td><textarea id="lr-fresh" rows="5" class="large-text code" name="%s[freshness_rules]">%s</textarea><p class="description">One per line: <code>category = max age in days</code>. <code>profile</code> is the fallback.</p></td></tr>',
			esc_attr( $name ),
			esc_textarea( SourceTiers::format( $s['freshness_rules'] ) )
		);
		foreach ( array(
			'lawyer'   => 'lawyers',
			'law_firm' => 'law firms',
		) as $entity => $label ) {
			printf(
				'<tr><th scope="row"><label for="lr-req-%1$s">Required verifications (%2$s)</label></th><td><input type="text" id="lr-req-%1$s" class="regular-text" name="%3$s[required_verifications][%1$s]" value="%4$s"><p class="description">Comma separated. Available: %5$s</p></td></tr>',
				esc_attr( $entity ),
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( implode( ', ', $s['required_verifications'][ $entity ] ) ),
				esc_html( implode( ', ', VerificationType::values() ) )
			);
		}
		foreach ( array(
			'min_ranking_entities'   => 'Minimum entities for a ranking page',
			'rate_limit_per_minute'  => 'API rate limit (requests/minute/IP)',
			'search_rate_per_minute' => 'Search rate limit (requests/minute/IP)',
			'research_max_retries'   => 'Research job retries (after the first attempt)',
			'research_backoff_base'  => 'Research retry backoff base (seconds, doubles per retry)',
			'research_lease_minutes' => 'Research job lease (minutes without heartbeat before a job is resumed)',
		) as $key => $label ) {
			printf(
				'<tr><th scope="row"><label for="lr-%1$s">%2$s</label></th><td><input type="number" id="lr-%1$s" class="small-text" name="%3$s[%1$s]" value="%4$d"></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name ),
				(int) $s[ $key ]
			);
		}
		echo '<tr><th scope="row"><label for="lr-version">Score version</label></th><td><select id="lr-version" name="' . esc_attr( $name ) . '[score_version]">';
		foreach ( $this->services->versions->all() as $version ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $version->id ), selected( $s['score_version'], $version->id, false ) );
		}
		echo '</select><p class="description">Methodology version used for new calculations. Weights of a version never change; a new version is added instead.</p></td></tr>';
		printf(
			'<tr><th scope="row">XML-RPC</th><td><input type="hidden" name="%1$s[disable_xmlrpc]" value="0"><label><input type="checkbox" name="%1$s[disable_xmlrpc]" value="1"%2$s> Disable XML-RPC (recommended for a headless CMS)</label></td></tr>',
			esc_attr( $name ),
			checked( (bool) $s['disable_xmlrpc'], true, false )
		);
		$rev = \LexRanked\Core\Integration\Revalidator::last_status();
		printf(
			'<tr><th scope="row">Instant page refresh</th><td>%s<p class="description">Needs the frontend URL above and <code>define( \'LEXRANKED_REVALIDATE_SECRET\', \'…\' );</code> in wp-config.php (the same value as REVALIDATE_SECRET on the frontend). Last result: %s</p></td></tr>',
			\LexRanked\Core\Integration\Signature::usable( \LexRanked\Core\Integration\Revalidator::secret() ) ? 'Secret configured.' : '<strong>Secret not configured</strong> — pages refresh within 5 minutes instead of seconds.',
			esc_html( null === $rev ? '—' : $rev['state'] . ' · ' . $rev['message'] . ' · ' . $rev['at'] )
		);
		printf(
			'<tr><th scope="row">AI assistance</th><td><input type="hidden" name="%1$s[ai_enabled]" value="0"><label><input type="checkbox" name="%1$s[ai_enabled]" value="1"%2$s> Accept AI-assisted research and content drafts from workers</label><p class="description">Off by default. AI output never publishes: extracted facts are low-confidence, quote-checked evidence; match suggestions are advisory; generated content arrives as drafts with a QA report.</p></td></tr>',
			esc_attr( $name ),
			checked( (bool) $s['ai_enabled'], true, false )
		);
		printf(
			'<tr><th scope="row">Proxy header</th><td><input type="hidden" name="%1$s[trust_proxy_header]" value="0"><label><input type="checkbox" name="%1$s[trust_proxy_header]" value="1"%2$s> Trust <code>CF-Connecting-IP</code> (enable only when WordPress is reachable exclusively through Cloudflare)</label></td></tr>',
			esc_attr( $name ),
			checked( (bool) $s['trust_proxy_header'], true, false )
		);

		echo '</tbody></table>';
		submit_button();
		echo '</form></div>';
	}
}
