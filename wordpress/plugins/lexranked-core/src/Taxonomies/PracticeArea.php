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
}
