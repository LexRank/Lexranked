<?php
/**
 * Practice area taxonomy.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Taxonomies;

/**
 * Practice areas (flat), e.g. "Personal Injury".
 */
final class PracticeArea {

	public const SLUG = 'lr_practice_area';

	public const CATALOG_OPTION  = 'lexranked_practice_catalog';
	public const CATALOG_VERSION = '1';

	/**
	 * The practice areas LexRanked ranks, by slug. Research can only assign
	 * areas that exist; empty areas stay hidden from the public API
	 * (hide_empty), so seeding them adds no thin pages.
	 */
	public const CATALOG = array(
		'personal-injury'      => 'Personal Injury',
		'criminal-defense'     => 'Criminal Defense',
		'family-law'           => 'Family Law',
		'divorce'              => 'Divorce',
		'immigration'          => 'Immigration',
		'bankruptcy'           => 'Bankruptcy',
		'employment-law'       => 'Employment Law',
		'medical-malpractice'  => 'Medical Malpractice',
		'workers-compensation' => "Workers' Compensation",
		'estate-planning'      => 'Estate Planning',
		'real-estate'          => 'Real Estate',
		'business-law'         => 'Business Law',
		'dui'                  => 'DUI',
		'wrongful-death'       => 'Wrongful Death',
		'tax-law'              => 'Tax Law',
		'elder-law'            => 'Elder Law',
	);

	/**
	 * Register the taxonomy.
	 *
	 * @param array<int, string> $object_types Post types using the taxonomy.
	 */
	public static function register( array $object_types ): void {
		register_taxonomy(
			self::SLUG,
			$object_types,
			array(
				'labels'            => array(
					'name'          => 'Practice Areas',
					'singular_name' => 'Practice Area',
					'menu_name'     => 'Practice Areas',
					'add_new_item'  => 'Add New Practice Area',
					'edit_item'     => 'Edit Practice Area',
					'search_items'  => 'Search Practice Areas',
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'hierarchical'      => true,
				'rewrite'           => false,
				'query_var'         => false,
			)
		);
	}

	/**
	 * Add catalog areas that do not exist yet (idempotent; existing terms,
	 * including renamed ones, are left alone). Runs once per catalog version.
	 */
	public static function seed_catalog(): void {
		if ( get_option( self::CATALOG_OPTION ) === self::CATALOG_VERSION || ! taxonomy_exists( self::SLUG ) ) {
			return;
		}
		foreach ( self::CATALOG as $slug => $name ) {
			if ( ! get_term_by( 'slug', $slug, self::SLUG ) instanceof \WP_Term ) {
				wp_insert_term( $name, self::SLUG, array( 'slug' => $slug ) );
			}
		}
		update_option( self::CATALOG_OPTION, self::CATALOG_VERSION, false );
	}
}
