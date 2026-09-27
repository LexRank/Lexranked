<?php
/**
 * Lookup index for candidate matching.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

use LexRanked\Core\Entity\EntityRegistry;
use LexRanked\Core\Entity\EntityType;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\Repository\EntityRepository;
use LexRanked\Core\Schema\MetaCodec;

/**
 * Keeps normalized name / name key / website domain in post meta for every
 * lawyer and firm (any status), so matching a candidate is one indexed
 * meta query instead of a scan.
 */
final class EntityIndex {

	public const META_NAME   = '_lr_norm_name';
	public const META_KEY    = '_lr_name_key';
	public const META_DOMAIN = '_lr_domain';

	/** Statuses a candidate may match (trash excluded). */
	public const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Constructor.
	 *
	 * @param EntityRepository $entities Entity repository.
	 * @param Lawyer           $lawyer   Lawyer type.
	 * @param LawFirm          $law_firm Law firm type.
	 * @param EntityRegistry   $registry Entity registry (former names).
	 */
	public function __construct(
		private readonly EntityRepository $entities,
		private readonly Lawyer $lawyer,
		private readonly LawFirm $law_firm,
		private readonly EntityRegistry $registry
	) {
	}

	/**
	 * Hook into saves.
	 */
	public function register(): void {
		add_action( 'save_post_' . Lawyer::SLUG, array( $this, 'index' ), 20 );
		add_action( 'save_post_' . LawFirm::SLUG, array( $this, 'index' ), 20 );
	}

	/**
	 * (Re)index one post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function index( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$type = $this->entity_type( $post );
		if ( null === $type ) {
			return;
		}
		$name = CandidateNormalizer::name( (string) $post->post_title, $type );
		update_post_meta( $post_id, self::META_NAME, $name );
		update_post_meta( $post_id, self::META_KEY, CandidateMatcher::name_key( $name ) );

		$definition = 'law_firm' === $type ? $this->law_firm : $this->lawyer;
		$field      = $definition->field( 'website' );
		$website    = null === $field ? null : MetaCodec::decode( $field, get_post_meta( $post_id, $field->meta_key(), true ) );
		$domain     = CandidateNormalizer::domain( is_string( $website ) ? $website : null );
		if ( null === $domain ) {
			delete_post_meta( $post_id, self::META_DOMAIN );
		} else {
			update_post_meta( $post_id, self::META_DOMAIN, $domain );
		}
	}

	/**
	 * Index every lawyer and firm.
	 *
	 * @return int Posts indexed.
	 */
	public function reindex_all(): int {
		$count = 0;
		$page  = 1;
		do {
			$ids = get_posts(
				array(
					'post_type'        => array( Lawyer::SLUG, LawFirm::SLUG ),
					'post_status'      => self::STATUSES,
					'posts_per_page'   => 200,
					'paged'            => $page,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => false,
				)
			);
			foreach ( $ids as $id ) {
				$this->index( (int) $id );
				++$count;
			}
			++$page;
			$batch = count( $ids );
		} while ( 200 === $batch );
		return $count;
	}

	/**
	 * Existing entities that could match a candidate, in the matcher's shape.
	 *
	 * @param array{entity_type: string, normalized_name: string, domain: string|null} $candidate Candidate.
	 * @return array<int, array{id: int, status: string, normalized_name: string, cities: array<int, string>, states: array<int, string>, domain: string|null, aliases: array<int, string>}>
	 */
	public function candidates_for( array $candidate ): array {
		$or = array(
			'relation' => 'OR',
			array(
				'key'   => self::META_NAME,
				'value' => $candidate['normalized_name'],
			),
			array(
				'key'   => self::META_KEY,
				'value' => CandidateMatcher::name_key( $candidate['normalized_name'] ),
			),
		);
		if ( null !== $candidate['domain'] ) {
			$or[] = array(
				'key'   => self::META_DOMAIN,
				'value' => $candidate['domain'],
			);
		}
		$type  = 'law_firm' === $candidate['entity_type'] ? EntityType::LawFirm : EntityType::Lawyer;
		$posts = get_posts(
			array(
				'post_type'        => $type->wp_kind(),
				'post_status'      => self::STATUSES,
				'posts_per_page'   => 50,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => $or, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed lookup, max 50 rows.
			)
		);

		// Entities that were known under this name before a rename.
		$known = array_map( static fn( \WP_Post $p ): int => (int) $p->ID, $posts );
		foreach ( array_diff( $this->registry->wp_ids_by_name( $type, array( $candidate['normalized_name'] ) ), $known ) as $wp_id ) {
			$post = get_post( $wp_id );
			if ( $post instanceof \WP_Post && in_array( $post->post_status, self::STATUSES, true ) ) {
				$posts[] = $post;
			}
		}
		$aliases = $this->registry->names_for( $type, array_map( static fn( \WP_Post $p ): int => (int) $p->ID, $posts ) );

		$out = array();
		foreach ( $posts as $post ) {
			$cities = array();
			$states = array();
			foreach ( $this->entities->location_terms( (int) $post->ID ) as $term ) {
				if ( null !== $term['state_code'] ) {
					$states[] = $term['state_code'];
				} elseif ( $term['parent'] > 0 ) {
					$cities[] = $term['name'];
				}
			}
			$out[] = array(
				'id'              => (int) $post->ID,
				'status'          => (string) $post->post_status,
				'normalized_name' => (string) get_post_meta( $post->ID, self::META_NAME, true ),
				'cities'          => $cities,
				'states'          => array_values( array_unique( $states ) ),
				'domain'          => ( '' === (string) get_post_meta( $post->ID, self::META_DOMAIN, true ) ) ? null : (string) get_post_meta( $post->ID, self::META_DOMAIN, true ),
				'aliases'         => array_values( array_unique( $aliases[ (int) $post->ID ] ?? array() ) ),
			);
		}
		return $out;
	}

	/**
	 * Entity type of a post, or null.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function entity_type( \WP_Post $post ): ?string {
		return match ( $post->post_type ) {
			Lawyer::SLUG  => 'lawyer',
			LawFirm::SLUG => 'law_firm',
			default       => null,
		};
	}
}
