<?php
/**
 * Verification record queries.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Repository;

use LexRanked\Core\PostTypes\VerificationRecord;

/**
 * Loads published verification records for a batch of entities in one query.
 */
final class VerificationRepository {

	/**
	 * Constructor.
	 *
	 * @param EntityRepository   $entities Entity repository.
	 * @param VerificationRecord $type     Verification post type.
	 */
	public function __construct(
		private readonly EntityRepository $entities,
		private readonly VerificationRecord $type
	) {
	}

	/**
	 * Records grouped by entity ID.
	 *
	 * @param array<int, int> $entity_ids Entity IDs.
	 * @return array<int, array<int, array<string, mixed>>> entity_id => list of {id, type, status, verified_at, expires_at, source_id, source_url}
	 */
	public function for_entities( array $entity_ids ): array {
		$entity_ids = array_values( array_unique( array_filter( array_map( 'intval', $entity_ids ) ) ) );
		if ( array() === $entity_ids ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'        => VerificationRecord::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => 1000,
				'no_found_rows'    => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'meta_query'       => array(
					array(
						'key'     => $this->type->field( 'entity_id' )?->meta_key(),
						'value'   => $entity_ids,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$grouped = array();
		foreach ( $posts as $post ) {
			$fields = $this->entities->record( $post, $this->type )['fields'];
			if ( null === $fields['entity_id'] || null === $fields['verification_type'] || null === $fields['status'] ) {
				continue;
			}
			$grouped[ $fields['entity_id'] ][] = array(
				'id'          => (int) $post->ID,
				'type'        => $fields['verification_type'],
				'status'      => $fields['status'],
				'verified_at' => $fields['verified_at'],
				'expires_at'  => $fields['expires_at'],
				'source_id'   => $fields['source_id'],
				'source_url'  => $fields['source_url'],
			);
		}
		return $grouped;
	}
}
