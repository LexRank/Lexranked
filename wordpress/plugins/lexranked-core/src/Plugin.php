<?php
/**
 * Plugin bootstrap.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core;

use LexRanked\Core\REST\StatusController;

/**
 * Wires plugin services into WordPress hooks.
 *
 * Phase 1 only registers the public status endpoint. Post types, taxonomies,
 * the full REST API and admin screens are added in Phase 2.
 */
final class Plugin {

	/** Custom REST namespace; every LexRanked endpoint lives under it. */
	public const REST_NAMESPACE = 'lexranked/v1';

	/** Version of the public API contract (DTO shapes), independent of plugin version. */
	public const API_VERSION = '1.0.0';

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Register hooks. Idempotent.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		$status = new StatusController( LEXRANKED_CORE_VERSION );
		add_action( 'rest_api_init', array( $status, 'register_routes' ) );
	}

	/**
	 * Activation hook: refuse to activate on unsupported PHP versions.
	 */
	public static function activate(): void {
		if ( ! self::meets_php_requirement( PHP_VERSION ) ) {
			deactivate_plugins( plugin_basename( LEXRANKED_CORE_FILE ) );
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'LexRanked Core requires PHP %1$s or newer. This server runs PHP %2$s.', 'lexranked-core' ),
						LEXRANKED_CORE_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
		flush_rewrite_rules();
	}

	/**
	 * Deactivation hook. Data is intentionally kept; removal belongs in uninstall.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Check a PHP version string against the plugin minimum.
	 *
	 * @param string $version PHP version to check.
	 */
	public static function meets_php_requirement( string $version ): bool {
		return version_compare( $version, LEXRANKED_CORE_MIN_PHP, '>=' );
	}
}
