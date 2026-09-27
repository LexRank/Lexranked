<?php
/**
 * Comparison endpoint (Etap E).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Compare\ComparisonEngine;
use LexRanked\Core\Entity\EntityNames;
use LexRanked\Core\Entity\EntityType;
use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\Services;

/**
 * GET /compare?type=lawyer&entities=12,34 — side-by-side comparison of two to
 * four published entities of one type, by stable entity ID (merged IDs follow
 * to the surviving entity). Built by ComparisonEngine from the same public
 * detail DTOs the profiles use, so it can never show more than a profile does.
 */
final class CompareController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/compare',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'compare' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => array(
					'type'     => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( EntityType::Lawyer->value, EntityType::LawFirm->value ),
						'validate_callback' => 'rest_validate_request_arg',
					),
					'entities' => array(
						'description'       => 'Comma-separated entity IDs (' . ComparisonEngine::MIN . '–' . ComparisonEngine::MAX . ').',
						'type'              => 'string',
						'required'          => true,
						'pattern'           => '^[0-9]{1,10}(,[0-9]{1,10}){0,9}$',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	/**
	 * Comparison.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function compare( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$type = EntityType::from( (string) $request['type'] );
		$ids  = array_values( array_unique( array_map( 'intval', explode( ',', (string) $request['entities'] ) ) ) );
		if ( count( $ids ) < ComparisonEngine::MIN || count( $ids ) > ComparisonEngine::MAX ) {
			return new \WP_Error( 'lexranked_invalid_param', 'Compare between ' . ComparisonEngine::MIN . ' and ' . ComparisonEngine::MAX . ' different entities.', array( 'status' => 400 ) );
		}

		$details = array();
		foreach ( $ids as $id ) {
			$post = $this->post_for( $type, $id );
			if ( null === $post ) {
				return $this->not_found( 'Entity' );
			}
			$details[ $post->ID ] = EntityType::Lawyer === $type
				? $this->services->presenter->lawyer_detail( $post, false )
				: $this->services->presenter->firm_detail( $post );
		}
		if ( count( $details ) < ComparisonEngine::MIN ) {
			// Two requested IDs merged into the same entity.
			return new \WP_Error( 'lexranked_invalid_param', 'Compare between ' . ComparisonEngine::MIN . ' and ' . ComparisonEngine::MAX . ' different entities.', array( 'status' => 400 ) );
		}
		return $this->item_response( ComparisonEngine::compare( $type->value, array_values( $details ) ) );
	}

	/**
	 * Published post behind an active entity of the type, following merges.
	 *
	 * @param EntityType $type      Type.
	 * @param int        $entity_id Entity ID.
	 */
	private function post_for( EntityType $type, int $entity_id ): ?\WP_Post {
		$row = $this->services->registry->find( $entity_id );
		for ( $hops = 0; null !== $row && EntityNames::MERGED === $row['status'] && null !== $row['merged_into'] && $hops < 5; $hops++ ) {
			$row = $this->services->registry->find( (int) $row['merged_into'] );
		}
		if ( null === $row || EntityNames::ACTIVE !== $row['status'] || $type->value !== $row['entity_type'] ) {
			return null;
		}
		$post = get_post( (int) $row['wp_id'] );
		$slug = EntityType::Lawyer === $type ? Lawyer::SLUG : LawFirm::SLUG;
		return $post instanceof \WP_Post && 'publish' === $post->post_status && $slug === $post->post_type ? $post : null;
	}
}
