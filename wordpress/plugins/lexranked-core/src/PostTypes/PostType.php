<?php
/**
 * Base class for LexRanked custom post types.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\PostTypes;

use LexRanked\Core\Schema\Field;

/**
 * A custom post type plus its structured fields.
 *
 * CPTs are not exposed through /wp/v2 (show_in_rest = false): the public
 * contract is the lexranked/v1 namespace with stable DTOs (ADR-003, ADR-010).
 */
abstract class PostType {

	/**
	 * Post type key (≤ 20 chars).
	 */
	abstract public function slug(): string;

	/**
	 * Singular label.
	 */
	abstract public function singular(): string;

	/**
	 * Plural label.
	 */
	abstract public function plural(): string;

	/**
	 * Structured fields stored as post meta.
	 *
	 * @return array<int, Field>
	 */
	abstract public function fields(): array;

	/**
	 * Public URL base used for rewrite slugs / frontend paths, or null if not public.
	 */
	public function public_base(): ?string {
		return null;
	}

	/**
	 * Whether posts use the main editor for prose (bio, description).
	 */
	public function supports_editor(): bool {
		return false;
	}

	/**
	 * Taxonomies attached to this post type.
	 *
	 * @return array<int, string>
	 */
	public function taxonomies(): array {
		return array();
	}

	/**
	 * Capability type; "post" lets editors manage the entity.
	 *
	 * @return string|array<int, string>
	 */
	public function capability_type(): string|array {
		return 'post';
	}

	/**
	 * Menu icon (dashicon).
	 */
	public function icon(): string {
		return 'dashicons-admin-post';
	}

	/**
	 * Look up a field by key.
	 *
	 * @param string $key Field key.
	 */
	public function field( string $key ): ?Field {
		foreach ( $this->fields() as $field ) {
			if ( $field->key === $key ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Arguments for register_post_type().
	 *
	 * @return array<string, mixed>
	 */
	public function args(): array {
		$supports = array( 'title', 'revisions' );
		if ( $this->supports_editor() ) {
			$supports[] = 'editor';
		}
		$capability_type = $this->capability_type();

		return array(
			'labels'          => $this->labels(),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'lexranked',
			'show_in_rest'    => false,
			'rewrite'         => false,
			'query_var'       => false,
			'has_archive'     => false,
			'hierarchical'    => false,
			'supports'        => $supports,
			'taxonomies'      => $this->taxonomies(),
			'capability_type' => $capability_type,
			'map_meta_cap'    => true,
			'menu_icon'       => $this->icon(),
		);
	}

	/**
	 * Admin labels.
	 *
	 * @return array<string, string>
	 */
	protected function labels(): array {
		$singular = $this->singular();
		$plural   = $this->plural();
		return array(
			'name'               => $plural,
			'singular_name'      => $singular,
			'menu_name'          => $plural,
			'all_items'          => $plural,
			'add_new'            => 'Add New',
			'add_new_item'       => 'Add New ' . $singular,
			'edit_item'          => 'Edit ' . $singular,
			'new_item'           => 'New ' . $singular,
			'view_item'          => 'View ' . $singular,
			'search_items'       => 'Search ' . $plural,
			'not_found'          => 'No ' . strtolower( $plural ) . ' found.',
			'not_found_in_trash' => 'No ' . strtolower( $plural ) . ' found in Trash.',
		);
	}

	/**
	 * Register the post type and its meta. Hooked to init.
	 */
	public function register(): void {
		register_post_type( $this->slug(), $this->args() );

		foreach ( $this->fields() as $field ) {
			register_post_meta(
				$this->slug(),
				$field->meta_key(),
				array(
					'type'          => $field->is_list() ? 'string' : $field->wp_meta_type(),
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
				)
			);
		}
	}

	/**
	 * Shared field: demo flag. Demo records are clearly labelled everywhere.
	 */
	protected static function demo_field(): Field {
		return new Field(
			'is_demo',
			Field::TYPE_BOOL,
			'Demo / mock data',
			help: 'Marks sample data. Demo records are labelled as such in the API and on the site. Never use for real profiles.'
		);
	}
}
