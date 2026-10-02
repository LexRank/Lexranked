<?php
/**
 * Entity registry.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Entity;

use LexRanked\Core\Database\Schema;

/**
 * Stable identities for everything LexRanked describes (lr_entities).
 *
 * WordPress keeps the content (posts and terms); the registry gives each
 * one an entity_id that never changes and is never reused: renaming keeps
 * the entity (the former name and slug become aliases), trashing or deleting
 * archives it, and a later merge points it at the surviving entity.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom tables; names are internal and values are prepared.
 */
final class EntityRegistry {

	/**
	 * Per-request cache: "wp_object:wp_id" => entity_id.
	 *
	 * @var array<string, int>
	 */
	private array $ids = array();

	/**
	 * Hooks.
	 */
	public function register(): void {
		foreach ( array( EntityType::Lawyer, EntityType::LawFirm ) as $type ) {
			add_action( 'save_post_' . $type->wp_kind(), array( $this, 'on_save_post' ), 15, 2 );
		}
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ), 10, 2 );
		add_action( 'created_term', array( $this, 'on_term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_delete_term' ), 10, 3 );
	}

	/**
	 * Table names.
	 */
	private function t(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::ENTITIES;
	}

	/**
	 * Alias table.
	 */
	private function a(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::ENTITY_ALIASES;
	}

	/**
	 * Lawyer / firm saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save_post( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->sync_post( $post );
	}

	/**
	 * Lawyer / firm permanently deleted: archive, keep the ID.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 */
	public function on_deleted_post( int $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post && null === EntityType::from_wp_kind( $post->post_type ) ) {
			return;
		}
		$this->archive( 'post', $post_id );
	}

	/**
	 * Location / practice area created or edited.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term( int $term_id, int $tt_id, string $taxonomy ): void {
		unset( $tt_id );
		if ( null === EntityType::from_wp_kind( $taxonomy ) ) {
			return;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( $term instanceof \WP_Term ) {
			$this->sync_term( $term );
		}
	}

	/**
	 * Location / practice area deleted.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_delete_term( int $term_id, int $tt_id, string $taxonomy ): void {
		unset( $tt_id );
		if ( null !== EntityType::from_wp_kind( $taxonomy ) ) {
			$this->archive( 'term', $term_id );
		}
	}

	/**
	 * Register or update a post-backed entity.
	 *
	 * @param \WP_Post $post Post.
	 * @return int|null Entity ID (null for other post types and auto-drafts).
	 */
	public function sync_post( \WP_Post $post ): ?int {
		$type = EntityType::from_wp_kind( $post->post_type );
		if ( null === $type || 'auto-draft' === $post->post_status ) {
			return null;
		}
		$slug = '' !== $post->post_name ? $post->post_name : sanitize_title( $post->post_title );
		// Trashed posts get a "__trashed" suffix on their slug; keep the real one.
		$slug = (string) preg_replace( '/__trashed(-\d+)?$/', '', $slug );
		return $this->upsert( $type, (int) $post->ID, (string) $post->post_title, $slug, EntityNames::status_for_post( $post->post_status ) );
	}

	/**
	 * Register or update a term-backed entity.
	 *
	 * @param \WP_Term $term Term.
	 */
	public function sync_term( \WP_Term $term ): ?int {
		$type = EntityType::from_wp_kind( $term->taxonomy );
		return null === $type ? null : $this->upsert( $type, (int) $term->term_id, (string) $term->name, (string) $term->slug, EntityNames::ACTIVE );
	}

	/**
	 * Insert or update; record name and slug aliases.
	 *
	 * @param EntityType $type   Type.
	 * @param int        $wp_id  Post or term ID.
	 * @param string     $name   Canonical name.
	 * @param string     $slug   Slug.
	 * @param string     $status Status.
	 * @return int Entity ID.
	 */
	public function upsert( EntityType $type, int $wp_id, string $name, string $slug, string $status ): int {
		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$name = mb_substr( trim( $name ), 0, 255 );
		$slug = mb_substr( $slug, 0, 200 );
		$row  = $this->row_for_wp( $type->wp_object(), $wp_id );
		if ( null === $row ) {
			$wpdb->insert(
				$this->t(),
				array(
					'entity_type'    => $type->value,
					'canonical_name' => $name,
					'slug'           => $slug,
					'status'         => $status,
					'wp_object'      => $type->wp_object(),
					'wp_id'          => $wp_id,
					'created_at'     => $now,
					'updated_at'     => $now,
				)
			);
			$id = (int) $wpdb->insert_id;
			if ( 0 === $id ) {
				// A concurrent request registered it first.
				$row = $this->row_for_wp( $type->wp_object(), $wp_id );
				$id  = null === $row ? 0 : (int) $row['entity_id'];
			}
		} else {
			$id = (int) $row['entity_id'];
			if ( EntityNames::changed( $row, $name, $slug, $status ) ) {
				$wpdb->update(
					$this->t(),
					array(
						'canonical_name' => $name,
						'slug'           => $slug,
						'status'         => EntityNames::MERGED === $row['status'] ? EntityNames::MERGED : $status,
						'updated_at'     => $now,
					),
					array( 'entity_id' => $id )
				);
			}
		}//end if
		if ( $id > 0 ) {
			$this->record_aliases( $id, $type, EntityNames::aliases( $type, $name, $slug ), $now );
			$this->ids[ $type->wp_object() . ':' . $wp_id ] = $id;
		}
		return $id;
	}

	/**
	 * Mark current aliases, keep former ones.
	 *
	 * @param int                                                                      $id      Entity ID.
	 * @param EntityType                                                               $type    Type.
	 * @param array<int, array{alias_type: string, value: string, normalized: string}> $aliases Current aliases.
	 * @param string                                                                   $now     Now.
	 */
	private function record_aliases( int $id, EntityType $type, array $aliases, string $now ): void {
		global $wpdb;
		foreach ( array( EntityNames::ALIAS_NAME, EntityNames::ALIAS_SLUG ) as $alias_type ) {
			$current = array_values( array_filter( $aliases, static fn( array $a ): bool => $a['alias_type'] === $alias_type ) );
			$keep    = array_map( static fn( array $a ): string => $a['normalized'], $current );
			$keep    = array() === $keep ? array( '' ) : $keep;
			$in      = implode( ',', array_fill( 0, count( $keep ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->a()} SET is_current = 0 WHERE entity_id = %d AND alias_type = %s AND is_current = 1 AND normalized NOT IN ({$in})", array_merge( array( $id, $alias_type ), $keep ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders built above.
			foreach ( $current as $alias ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$this->a()} (entity_id, entity_type, alias_type, value, normalized, is_current, first_seen, last_seen) VALUES (%d, %s, %s, %s, %s, 1, %s, %s) ON DUPLICATE KEY UPDATE value = VALUES(value), is_current = 1, last_seen = VALUES(last_seen)",
						$id,
						$type->value,
						$alias['alias_type'],
						$alias['value'],
						$alias['normalized'],
						$now,
						$now
					)
				);
			}
		}//end foreach
	}

	/**
	 * Archive an entity whose WordPress object was deleted (the ID stays reserved).
	 *
	 * @param string $wp_object post|term.
	 * @param int    $wp_id     ID.
	 */
	public function archive( string $wp_object, int $wp_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->t()} SET status = %s, updated_at = %s WHERE wp_object = %s AND wp_id = %d AND status <> %s", EntityNames::ARCHIVED, gmdate( 'Y-m-d H:i:s' ), $wp_object, $wp_id, EntityNames::MERGED ) );
	}

	/**
	 * Row by WordPress reference.
	 *
	 * @param string $wp_object post|term.
	 * @param int    $wp_id     ID.
	 * @return array<string, mixed>|null
	 */
	private function row_for_wp( string $wp_object, int $wp_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t()} WHERE wp_object = %s AND wp_id = %d", $wp_object, $wp_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Entity row.
	 *
	 * @param int $entity_id Entity ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $entity_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t()} WHERE entity_id = %d", $entity_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Entity IDs for WordPress objects of one type (batched, cached per request).
	 *
	 * @param EntityType      $type   Type.
	 * @param array<int, int> $wp_ids Post or term IDs.
	 * @return array<int, int> wp_id => entity_id (missing when not registered).
	 */
	public function ids_for( EntityType $type, array $wp_ids ): array {
		global $wpdb;
		$obj     = $type->wp_object();
		$missing = array_values( array_unique( array_filter( array_map( 'intval', $wp_ids ), fn( int $id ): bool => $id > 0 && ! isset( $this->ids[ $obj . ':' . $id ] ) ) ) );
		if ( array() !== $missing ) {
			$in   = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT entity_id, wp_id FROM {$this->t()} WHERE wp_object = %s AND wp_id IN ({$in})", array_merge( array( $obj ), $missing ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$this->ids[ $obj . ':' . (int) $row['wp_id'] ] = (int) $row['entity_id'];
			}
		}
		$out = array();
		foreach ( $wp_ids as $wp_id ) {
			$key = $obj . ':' . (int) $wp_id;
			if ( isset( $this->ids[ $key ] ) ) {
				$out[ (int) $wp_id ] = $this->ids[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Entity ID of one WordPress object.
	 *
	 * @param EntityType $type  Type.
	 * @param int        $wp_id Post or term ID.
	 */
	public function id_for( EntityType $type, int $wp_id ): ?int {
		return $this->ids_for( $type, array( $wp_id ) )[ $wp_id ] ?? null;
	}

	/**
	 * Find an entity by a current or former slug, following merges.
	 *
	 * @param EntityType $type Type.
	 * @param string     $slug Slug.
	 * @return array<string, mixed>|null
	 */
	public function resolve( EntityType $type, string $slug ): ?array {
		global $wpdb;
		$slug = strtolower( $slug );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t()} WHERE entity_type = %s AND slug = %s ORDER BY status = %s DESC, entity_id DESC LIMIT 1", $type->value, $slug, EntityNames::ACTIVE ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			$id  = $wpdb->get_var( $wpdb->prepare( "SELECT entity_id FROM {$this->a()} WHERE entity_type = %s AND alias_type = %s AND normalized = %s ORDER BY last_seen DESC LIMIT 1", $type->value, EntityNames::ALIAS_SLUG, $slug ) );
			$row = null === $id ? null : $this->find( (int) $id );
		}
		for ( $hops = 0; null !== $row && EntityNames::MERGED === $row['status'] && null !== $row['merged_into'] && $hops < 5; $hops++ ) {
			$row = $this->find( (int) $row['merged_into'] );
		}
		return $row;
	}

	/**
	 * Entities of a type that ever had one of these normalised names (research matching).
	 *
	 * @param EntityType         $type  Type.
	 * @param array<int, string> $names Normalised names.
	 * @return array<int, int> WordPress IDs.
	 */
	public function wp_ids_by_name( EntityType $type, array $names ): array {
		global $wpdb;
		$names = array_values( array_unique( array_filter( $names ) ) );
		if ( array() === $names ) {
			return array();
		}
		$in  = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT e.wp_id FROM {$this->a()} a JOIN {$this->t()} e ON e.entity_id = a.entity_id WHERE a.entity_type = %s AND a.alias_type = %s AND a.normalized IN ({$in}) AND e.status <> %s LIMIT 50", array_merge( array( $type->value, EntityNames::ALIAS_NAME ), $names, array( EntityNames::MERGED ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders built above.
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * All normalised names (current and former) per WordPress ID.
	 *
	 * @param EntityType      $type   Type.
	 * @param array<int, int> $wp_ids WordPress IDs.
	 * @return array<int, array<int, string>>
	 */
	public function names_for( EntityType $type, array $wp_ids ): array {
		global $wpdb;
		$wp_ids = array_values( array_unique( array_map( 'intval', $wp_ids ) ) );
		if ( array() === $wp_ids ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $wp_ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.wp_id, a.normalized FROM {$this->a()} a JOIN {$this->t()} e ON e.entity_id = a.entity_id WHERE e.wp_object = %s AND e.wp_id IN ({$in}) AND a.alias_type = %s", array_merge( array( $type->wp_object() ), $wp_ids, array( EntityNames::ALIAS_NAME ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders built above.
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['wp_id'] ][] = (string) $row['normalized'];
		}
		return $out;
	}

	/**
	 * Public DTO (only for active entities; callers check status).
	 *
	 * @param array<string, mixed> $row Entity row.
	 * @return array<string, mixed>
	 */
	public function dto( array $row ): array {
		$type       = EntityType::from( (string) $row['entity_type'] );
		$has_parent = false;
		if ( EntityType::Location === $type ) {
			$term       = get_term( (int) $row['wp_id'], $type->wp_kind() );
			$has_parent = $term instanceof \WP_Term && $term->parent > 0;
		}
		return array(
			'entityId'      => (int) $row['entity_id'],
			'entityType'    => $type->value,
			'canonicalName' => (string) $row['canonical_name'],
			'slug'          => (string) $row['slug'],
			'status'        => (string) $row['status'],
			'path'          => $type->path( (string) $row['slug'], $has_parent ),
			'createdAt'     => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $row['created_at'] . ' UTC' ) ),
			'updatedAt'     => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $row['updated_at'] . ' UTC' ) ),
		);
	}

	/**
	 * Counts per type and status.
	 *
	 * @return array<string, array<string, int>>
	 */
	public function counts(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT entity_type, status, COUNT(*) AS n FROM {$this->t()} GROUP BY entity_type, status", ARRAY_A ) as $row ) {
			$out[ (string) $row['entity_type'] ][ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Register every existing lawyer, firm, location and practice area (idempotent).
	 *
	 * @return int Objects synchronised.
	 */
	public function backfill(): int {
		$count = 0;
		foreach ( array( EntityType::Lawyer, EntityType::LawFirm ) as $type ) {
			$page = 1;
			do {
				$posts = get_posts(
					array(
						'post_type'        => $type->wp_kind(),
						'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
						'posts_per_page'   => 200,
						'paged'            => $page,
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'suppress_filters' => false,
					)
				);
				foreach ( $posts as $post ) {
					$count += null === $this->sync_post( $post ) ? 0 : 1;
				}
				++$page;
				$batch = count( $posts );
			} while ( 200 === $batch );
		}//end foreach
		foreach ( array( EntityType::Location, EntityType::PracticeArea ) as $type ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $type->wp_kind(),
					'hide_empty' => false,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( $term instanceof \WP_Term ) {
					$count += null === $this->sync_term( $term ) ? 0 : 1;
				}
			}
		}
		return $count;
	}
}
