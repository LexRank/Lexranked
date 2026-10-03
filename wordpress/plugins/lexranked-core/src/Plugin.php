<?php
/**
 * Plugin bootstrap.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core;

use LexRanked\Core\Admin\CommercialAdmin;
use LexRanked\Core\Admin\ContentDraftAdmin;
use LexRanked\Core\Admin\ListColumns;
use LexRanked\Core\Admin\MetaBoxes;
use LexRanked\Core\Admin\Menu;
use LexRanked\Core\Admin\RankingCalculation;
use LexRanked\Core\Admin\ResearchAdmin;
use LexRanked\Core\CLI\Command;
use LexRanked\Core\Content\TermContent;
use LexRanked\Core\Database\Installer;
use LexRanked\Core\REST\ArticlesController;
use LexRanked\Core\REST\HealthController;
use LexRanked\Core\REST\CommercialController;
use LexRanked\Core\REST\EntitiesController;
use LexRanked\Core\REST\CompareController;
use LexRanked\Core\REST\MarketController;
use LexRanked\Core\REST\EditorialController;
use LexRanked\Core\REST\EntityController;
use LexRanked\Core\REST\RankingsController;
use LexRanked\Core\REST\ResearchController;
use LexRanked\Core\REST\SearchController;
use LexRanked\Core\REST\SourcesController;
use LexRanked\Core\REST\StatusController;
use LexRanked\Core\REST\TaxonomiesController;
use LexRanked\Core\Security\ApiGuard;
use LexRanked\Core\Security\Hardening;
use LexRanked\Core\Security\Headless;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Wires plugin services into WordPress hooks.
 */
final class Plugin {

	/** Custom REST namespace; every LexRanked endpoint lives under it. */
	public const REST_NAMESPACE = 'lexranked/v1';

	/** Version of the public API contract (DTO shapes), independent of plugin version. */
	public const API_VERSION = '1.18.0';

	/**
	 * Services, available after boot().
	 *
	 * @var Services|null
	 */
	private static ?Services $services = null;

	/**
	 * Register hooks. Idempotent.
	 */
	public static function boot(): void {
		if ( null !== self::$services ) {
			return;
		}
		$services       = new Services();
		self::$services = $services;

		Installer::maybe_upgrade();

		add_action( 'init', array( self::class, 'register_content_model' ) );
		add_action( 'init', array( PracticeArea::class, 'seed_catalog' ), 20 );

		( new ApiGuard( $services->settings ) )->register();
		$services->runner->register();
		$services->jobs->register();
		$services->registry->register();
		$services->facts->register();
		$services->quality->register();
		$services->entity_index->register();
		$services->revalidator->register();
		$services->commercial->register();
		( new Hardening( $services->settings ) )->register();
		( new Headless( $services->settings ) )->register();

		$controllers = array(
			new StatusController( LEXRANKED_CORE_VERSION ),
			new EntitiesController( $services ),
			new RankingsController( $services ),
			new TaxonomiesController( $services->registry, $services->eligibility ),
			new SourcesController( $services ),
			new SearchController( $services ),
			new ResearchController( $services ),
			new ArticlesController( $services ),
			new HealthController( $services ),
			new CommercialController( $services ),
			new EntityController( $services ),
			new CompareController( $services ),
			new MarketController( $services ),
			new EditorialController( $services ),
		);
		foreach ( $controllers as $controller ) {
			add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		}

		if ( is_admin() ) {
			( new Menu( $services ) )->register();
			( new MetaBoxes( $services ) )->register();
			( new ListColumns( $services ) )->register();
			( new RankingCalculation( $services ) )->register();
			( new ResearchAdmin( $services ) )->register();
			( new ContentDraftAdmin( $services ) )->register();
			( new CommercialAdmin( $services ) )->register();
			( new TermContent() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'lexranked', new Command( $services ) );
		}
	}

	/**
	 * Services container.
	 */
	public static function services(): Services {
		if ( null === self::$services ) {
			self::$services = new Services();
		}
		return self::$services;
	}

	/**
	 * Register post types and taxonomies. Hooked to init.
	 */
	public static function register_content_model(): void {
		$services = self::services();
		$located  = array();
		foreach ( $services->post_types() as $type ) {
			$type->register();
			if ( in_array( Location::SLUG, $type->taxonomies(), true ) ) {
				$located[] = $type->slug();
			}
		}
		Location::register( $located );
		PracticeArea::register( $located );
	}

	/**
	 * Activation hook.
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
		Installer::install();
		self::register_content_model();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation hook. Data is intentionally kept.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( \LexRanked\Core\Ranking\RankingRunner::CRON_HOOK );
		wp_clear_scheduled_hook( \LexRanked\Core\Ranking\RankingRunner::CRON_HOOK . '_soon' );
		wp_clear_scheduled_hook( \LexRanked\Core\Research\JobService::INTERNAL_HOOK );
		wp_clear_scheduled_hook( \LexRanked\Core\Research\JobService::INTERNAL_HOOK . '_soon' );
		wp_clear_scheduled_hook( \LexRanked\Core\Integration\Revalidator::RETRY_HOOK );
		wp_clear_scheduled_hook( \LexRanked\Core\Commercial\CommercialService::CRON_HOOK );
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
