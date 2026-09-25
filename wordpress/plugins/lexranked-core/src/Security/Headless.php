<?php
/**
 * Headless mode.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Security;

use LexRanked\Core\Settings\Settings;

/**
 * WordPress is CMS/API only (ADR-001):
 * - front-end responses from WordPress are always `noindex`;
 * - optionally, front-end visits are redirected to the Next.js site.
 */
final class Headless {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle_frontend' ), 0 );
		add_filter( 'wp_robots', array( $this, 'noindex' ) );
	}

	/**
	 * Send noindex and optionally redirect.
	 */
	public function handle_frontend(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_preview() ) {
			return;
		}
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		$frontend = (string) $this->settings->get( 'frontend_url' );
		if ( $this->settings->get( 'headless_redirect' ) && '' !== $frontend && ! is_user_logged_in() ) {
			wp_redirect( $frontend . '/', 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Target is an admin-configured, validated URL on another host.
			exit;
		}
	}

	/**
	 * Add noindex to the robots meta tag.
	 *
	 * @param array<string, bool|string> $robots Robots directives.
	 * @return array<string, bool|string>
	 */
	public function noindex( array $robots ): array {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		return $robots;
	}
}
