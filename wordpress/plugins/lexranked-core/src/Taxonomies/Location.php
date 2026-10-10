<?php
/**
 * Location taxonomy (state → city).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Taxonomies;

use LexRanked\Core\Domain\UsStates;

/**
 * Hierarchical locations: top-level terms are states, children are cities.
 * State terms carry a validated `state_code` term meta (USPS code).
 */
final class Location {

	public const SLUG          = 'lr_location';
	public const META_STATE    = '_lr_state_code';
	public const LEVEL_STATE   = 'state';
	public const LEVEL_CITY    = 'city';
	private const NONCE_ACTION = 'lexranked_location_meta';
	private const NONCE_FIELD  = 'lexranked_location_nonce';
	private const STATE_FIELD  = 'lexranked_state_code';

	/**
	 * Register taxonomy + term meta and admin hooks.
	 *
	 * @param array<int, string> $object_types Post types using the taxonomy.
	 */
	public static function register( array $object_types ): void {
		register_taxonomy(
			self::SLUG,
			$object_types,
			array(
				'labels'            => array(
					'name'          => 'Locations',
					'singular_name' => 'Location',
					'menu_name'     => 'Locations',
					'add_new_item'  => 'Add New Location',
					'edit_item'     => 'Edit Location',
					'parent_item'   => 'State',
					'search_items'  => 'Search Locations',
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
		register_term_meta(
			self::SLUG,
			self::META_STATE,
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => false,
			)
		);

		add_action( self::SLUG . '_add_form_fields', array( self::class, 'render_add_field' ) );
		add_action( self::SLUG . '_edit_form_fields', array( self::class, 'render_edit_field' ) );
		add_action( 'created_' . self::SLUG, array( self::class, 'save_meta' ) );
		add_action( 'edited_' . self::SLUG, array( self::class, 'save_meta' ) );
	}

	/**
	 * Normalize and validate a state code.
	 *
	 * @param string $raw Raw input.
	 */
	public static function normalize_state_code( string $raw ): ?string {
		$code = strtoupper( trim( $raw ) );
		return in_array( $code, UsStates::codes(), true ) ? $code : null;
	}

	/**
	 * Level of a term by position in the hierarchy.
	 *
	 * @param int $parent_id Parent term ID.
	 */
	public static function level_for_parent( int $parent_id ): string {
		return 0 === $parent_id ? self::LEVEL_STATE : self::LEVEL_CITY;
	}

	/**
	 * State-code select for the "add term" form.
	 */
	public static function render_add_field(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<div class="form-field"><label for="' . esc_attr( self::STATE_FIELD ) . '">State code</label>';
		self::render_select( '' );
		echo '<p>Required for states (top-level terms). Leave empty for cities.</p></div>';
	}

	/**
	 * State-code select for the "edit term" form.
	 *
	 * @param \WP_Term $term Term.
	 */
	public static function render_edit_field( \WP_Term $term ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<tr class="form-field"><th scope="row"><label for="' . esc_attr( self::STATE_FIELD ) . '">State code</label></th><td>';
		self::render_select( (string) get_term_meta( $term->term_id, self::META_STATE, true ) );
		echo '<p class="description">Required for states (top-level terms). Leave empty for cities.</p></td></tr>';
	}

	/**
	 * Render the state select.
	 *
	 * @param string $current Current code.
	 */
	private static function render_select( string $current ): void {
		echo '<select name="' . esc_attr( self::STATE_FIELD ) . '" id="' . esc_attr( self::STATE_FIELD ) . '"><option value="">-</option>';
		foreach ( UsStates::ALL as $code => $name ) {
			printf( '<option value="%1$s"%2$s>%1$s - %3$s</option>', esc_attr( $code ), selected( $current, $code, false ), esc_html( $name ) );
		}
		echo '</select>';
	}

	/**
	 * Persist the state code (only for top-level terms).
	 *
	 * @param int $term_id Term ID.
	 */
	public static function save_meta( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$term = get_term( $term_id, self::SLUG );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}
		$code = isset( $_POST[ self::STATE_FIELD ] ) ? self::normalize_state_code( sanitize_text_field( wp_unslash( $_POST[ self::STATE_FIELD ] ) ) ) : null;
		if ( self::LEVEL_STATE === self::level_for_parent( (int) $term->parent ) && null !== $code ) {
			update_term_meta( $term_id, self::META_STATE, $code );
		} else {
			delete_term_meta( $term_id, self::META_STATE );
		}
	}
}
