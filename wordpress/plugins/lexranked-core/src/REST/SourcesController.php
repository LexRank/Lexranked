<?php
/**
 * Sources and verifications endpoints.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Domain\VerificationStatus;
use LexRanked\Core\Domain\VerificationType;
use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\Source;
use LexRanked\Core\PostTypes\VerificationRecord;
use LexRanked\Core\REST\DTO\EntityMapper;
use LexRanked\Core\REST\DTO\SourceMapper;
use LexRanked\Core\Services;
use LexRanked\Core\Verification\VerificationPolicy;

/**
 * GET /sources, /verifications
 *
 * Only public metadata: internal notes and reviewer identities are never returned.
 */
final class SourcesController extends RestController {

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
		$entity_arg = array(
			'type'              => 'integer',
			'minimum'           => 1,
			'description'       => 'Lawyer or law firm ID.',
			'validate_callback' => 'rest_validate_request_arg',
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/sources',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'sources' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => $this->collection_args(
					array( 'name' ),
					array(
						'entity_id'   => $entity_arg,
						'source_type' => array(
							'type'              => 'string',
							'enum'              => $this->services->settings->source_tiers()->types(),
							'validate_callback' => 'rest_validate_request_arg',
						),
					)
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/verifications',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'verifications' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => $this->collection_args(
					array( 'verified_at' ),
					array(
						'entity_id'         => $entity_arg,
						'verification_type' => array(
							'type'              => 'string',
							'enum'              => VerificationType::values(),
							'validate_callback' => 'rest_validate_request_arg',
						),
						'status'            => array(
							'type'              => 'string',
							'enum'              => VerificationStatus::values(),
							'validate_callback' => 'rest_validate_request_arg',
						),
					)
				),
			)
		);
	}

	/**
	 * Source registry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sources( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$args = array(
			'post_type'        => Source::SLUG,
			'post_status'      => 'publish',
			'posts_per_page'   => (int) $request['per_page'],
			'paged'            => (int) $request['page'],
			'orderby'          => array(
				'title' => 'asc' === $request['order'] ? 'ASC' : 'DESC',
				'ID'    => 'ASC',
			),
			'suppress_filters' => false,
		);
		if ( ! empty( $request['entity_id'] ) ) {
			$ids = $this->services->claims->source_ids_for_entity( (int) $request['entity_id'] );
			if ( array() === $ids ) {
				return $this->collection_response( array(), 0, (int) $request['per_page'] );
			}
			$args['post__in'] = $ids;
		}
		if ( ! empty( $request['source_type'] ) ) {
			$args['meta_query'] = array(
				array(
					'key'   => $this->services->source->field( 'source_type' )?->meta_key(),
					'value' => (string) $request['source_type'],
				),
			);
		}
		$query = new \WP_Query( $args );
		$tiers = $this->services->settings->source_tiers();
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$items[] = SourceMapper::source( $this->services->entities->record( $post, $this->services->source ), $tiers );
			}
		}
		return $this->collection_response( $items, (int) $query->found_posts, (int) $request['per_page'] );
	}

	/**
	 * Verification records for published entities.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function verifications( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$type       = $this->services->verification;
		$meta_query = array( 'relation' => 'AND' );
		if ( ! empty( $request['entity_id'] ) ) {
			$meta_query[] = array(
				'key'   => $type->field( 'entity_id' )?->meta_key(),
				'value' => (int) $request['entity_id'],
				'type'  => 'NUMERIC',
			);
		}
		if ( ! empty( $request['verification_type'] ) ) {
			$meta_query[] = array(
				'key'   => $type->field( 'verification_type' )?->meta_key(),
				'value' => (string) $request['verification_type'],
			);
		}

		// Effective status (expiry) and entity visibility are evaluated in PHP,
		// so load the (small) matching set and paginate afterwards.
		$posts = get_posts(
			array(
				'post_type'        => VerificationRecord::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => 1000,
				'no_found_rows'    => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'meta_query'       => $meta_query,
			)
		);

		$now      = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$items    = array();
		$entities = array();
		$sources  = array();
		foreach ( $posts as $post ) {
			$f         = $this->services->entities->record( $post, $type )['fields'];
			$entity_id = (int) $f['entity_id'];
			if ( ! array_key_exists( $entity_id, $entities ) ) {
				$entity                 = get_post( $entity_id );
				$entities[ $entity_id ] = ( $entity instanceof \WP_Post && 'publish' === $entity->post_status && in_array( $entity->post_type, array( Lawyer::SLUG, LawFirm::SLUG ), true ) ) ? $entity : null;
			}
			$entity = $entities[ $entity_id ];
			if ( null === $entity ) {
				continue;
			}
			$status = VerificationPolicy::effective_status(
				array(
					'status'     => (string) $f['status'],
					'expires_at' => $f['expires_at'],
				),
				$now
			);
			if ( ! empty( $request['status'] ) && $status !== $request['status'] ) {
				continue;
			}
			$source = null;
			if ( null !== $f['source_id'] ) {
				if ( ! array_key_exists( $f['source_id'], $sources ) ) {
					$source_post                = get_post( (int) $f['source_id'] );
					$sources[ $f['source_id'] ] = ( $source_post instanceof \WP_Post && 'publish' === $source_post->post_status )
						? SourceMapper::source( $this->services->entities->record( $source_post, $this->services->source ), $this->services->settings->source_tiers() )
						: null;
				}
				$source = $sources[ $f['source_id'] ];
			}
			$is_lawyer = Lawyer::SLUG === $entity->post_type;
			$items[]   = array(
				'id'         => (int) $post->ID,
				'entity'     => array( 'type' => $is_lawyer ? 'lawyer' : 'law_firm' ) + EntityMapper::reference(
					array(
						'id'    => (int) $entity->ID,
						'slug'  => $entity->post_name,
						'title' => get_the_title( $entity ),
					),
					$is_lawyer ? '/lawyers/' : '/law-firms/'
				),
				'type'       => $f['verification_type'],
				'status'     => $status,
				'verifiedAt' => $f['verified_at'],
				'expiresAt'  => $f['expires_at'],
				'source'     => $source,
				'sourceUrl'  => $f['source_url'],
				'isDemo'     => (bool) $f['is_demo'],
			);
		}//end foreach

		usort(
			$items,
			static fn( array $a, array $b ): int => 'asc' === $request['order']
				? array( (string) $a['verifiedAt'], $a['id'] ) <=> array( (string) $b['verifiedAt'], $b['id'] )
				: array( (string) $b['verifiedAt'], $a['id'] ) <=> array( (string) $a['verifiedAt'], $b['id'] )
		);
		$per_page = (int) $request['per_page'];
		return $this->collection_response( array_slice( $items, ( (int) $request['page'] - 1 ) * $per_page, $per_page ), count( $items ), $per_page );
	}
}
