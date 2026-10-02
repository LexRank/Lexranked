<?php
/**
 * Reads/writes entity posts as plain records.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Repository;

use LexRanked\Core\Entity\EntityRegistry;
use LexRanked\Core\Entity\EntityType;
use LexRanked\Core\PostTypes\PostType;
use LexRanked\Core\Schema\Field;
use LexRanked\Core\Schema\FieldSanitizer;
use LexRanked\Core\Schema\MetaCodec;
use LexRanked\Core\Schema\ValidationException;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * The only place that turns WP_Post + meta + terms into plain arrays.
 *
 * DTO mappers consume these records, so they never touch WordPress APIs and
 * the storage can change (e.g. to PostgreSQL) behind this class.
 */
final class EntityRepository {

	/**
	 * Constructor.
	 *
	 * @param EntityRegistry|null $registry Entity registry (stable entity IDs); null in pure tests.
	 */
	public function __construct( private readonly ?EntityRegistry $registry = null ) {
	}

	/**
	 * Build a record from a post.
	 *
	 * @param \WP_Post $post Post.
	 * @param PostType $type Post type definition.
	 * @return array<string, mixed>
	 */
	public function record( \WP_Post $post, PostType $type ): array {
		$fields = array();
		foreach ( $type->fields() as $field ) {
			$fields[ $field->key ] = MetaCodec::decode( $field, get_post_meta( $post->ID, $field->meta_key(), true ) );
		}

		$entity_type = EntityType::from_wp_kind( $type->slug() );
		return array(
			'id'             => (int) $post->ID,
			'entity_id'      => null === $entity_type || null === $this->registry ? null : $this->registry->id_for( $entity_type, (int) $post->ID ),
			'type'           => $type->slug(),
			'slug'           => (string) $post->post_name,
			'title'          => (string) get_the_title( $post ),
			'content'        => (string) $post->post_content,
			'status'         => (string) $post->post_status,
			'created_at'     => self::iso( (string) $post->post_date_gmt ),
			'updated_at'     => self::iso( (string) $post->post_modified_gmt ),
			'fields'         => $fields,
			'locations'      => in_array( Location::SLUG, $type->taxonomies(), true ) ? $this->location_terms( $post->ID ) : array(),
			'practice_areas' => in_array( PracticeArea::SLUG, $type->taxonomies(), true ) ? $this->practice_terms( $post->ID ) : array(),
		);
	}

	/**
	 * Find a published post by numeric ID or slug.
	 *
	 * @param PostType $type       Post type.
	 * @param string   $identifier ID or slug.
	 */
	public function find_published( PostType $type, string $identifier ): ?\WP_Post {
		if ( ctype_digit( $identifier ) ) {
			$post = get_post( (int) $identifier );
			return ( $post instanceof \WP_Post && $post->post_type === $type->slug() && 'publish' === $post->post_status ) ? $post : null;
		}
		$posts = get_posts(
			array(
				'post_type'        => $type->slug(),
				'name'             => sanitize_title( $identifier ),
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		return $posts[0] ?? null;
	}

	/**
	 * Location terms assigned to a post, plus their parents, as plain arrays.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function location_terms( int $post_id ): array {
		$terms = get_the_terms( $post_id, Location::SLUG );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			$out[ $term->term_id ] = self::location_term( $term );
			if ( $term->parent && ! isset( $out[ $term->parent ] ) ) {
				$parent = get_term( $term->parent, Location::SLUG );
				if ( $parent instanceof \WP_Term ) {
					$out[ $parent->term_id ] = self::location_term( $parent );
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * Plain array for a location term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array{id: int, slug: string, name: string, parent: int, state_code: string|null}
	 */
	public static function location_term( \WP_Term $term ): array {
		$code = (string) get_term_meta( $term->term_id, Location::META_STATE, true );
		return array(
			'id'         => (int) $term->term_id,
			'slug'       => (string) $term->slug,
			'name'       => (string) $term->name,
			'parent'     => (int) $term->parent,
			'state_code' => '' === $code ? null : $code,
		);
	}

	/**
	 * Practice-area terms as plain arrays.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array{id: int, slug: string, name: string}>
	 */
	public function practice_terms( int $post_id ): array {
		$terms = get_the_terms( $post_id, PracticeArea::SLUG );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out = array_map(
			static fn( \WP_Term $t ): array => array(
				'id'     => (int) $t->term_id,
				'slug'   => (string) $t->slug,
				'name'   => (string) $t->name,
				'parent' => (int) $t->parent,
			),
			$terms
		);
		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['name'], $b['name'] ) );
		return $out;
	}

	/**
	 * Sanitize and persist field values. Fields missing from $input are left untouched.
	 *
	 * @param int                  $post_id          Post ID.
	 * @param PostType             $type             Post type.
	 * @param array<string, mixed> $input            Raw values keyed by field key.
	 * @param bool                 $allow_read_only  Allow system-managed fields (engine, seeding).
	 * @return array<string, string> Validation errors keyed by field key (invalid values are not saved).
	 */
	public function save_fields( int $post_id, PostType $type, array $input, bool $allow_read_only = false ): array {
		$errors = array();
		foreach ( $type->fields() as $field ) {
			if ( ! array_key_exists( $field->key, $input ) || ( $field->read_only && ! $allow_read_only ) ) {
				continue;
			}
			try {
				$value = FieldSanitizer::sanitize( $field, $input[ $field->key ] );
				if ( null !== $value && Field::TYPE_POST_REF === $field->type ) {
					$this->assert_reference( $field, (int) $value );
				}
			} catch ( ValidationException $e ) {
				$errors[ $field->key ] = $field->label . ' ' . $e->reason . '.';
				continue;
			}

			if ( null === $value || ( Field::TYPE_BOOL === $field->type && false === $value ) ) {
				if ( $field->required && Field::TYPE_BOOL !== $field->type ) {
					$errors[ $field->key ] = $field->label . ' is required.';
				}
				delete_post_meta( $post_id, $field->meta_key() );
				continue;
			}
			update_post_meta( $post_id, $field->meta_key(), wp_slash( MetaCodec::encode( $field, $value ) ) );
		}//end foreach
		return $errors;
	}

	/**
	 * Ensure a post reference points at an existing post of an allowed type.
	 *
	 * @param Field $field Field.
	 * @param int   $id    Referenced ID.
	 * @throws ValidationException When invalid.
	 */
	private function assert_reference( Field $field, int $id ): void {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ( array() !== $field->ref_types && ! in_array( $post->post_type, $field->ref_types, true ) ) ) {
			throw new ValidationException( $field->key, 'must reference an existing ' . implode( ' or ', $field->ref_types ) . ' record' );
		}
	}

	/**
	 * Convert a MySQL GMT datetime to ISO 8601 UTC.
	 *
	 * @param string $gmt MySQL datetime.
	 */
	public static function iso( string $gmt ): ?string {
		if ( '' === $gmt || str_starts_with( $gmt, '0000' ) ) {
			return null;
		}
		return str_replace( ' ', 'T', $gmt ) . 'Z';
	}
}
