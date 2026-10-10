<?php
/**
 * Entity registry endpoints.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Eligibility\PageEligibility;
use LexRanked\Core\Attribute\Attributes;
use LexRanked\Core\Entity\EntityNames;
use LexRanked\Core\Entity\EntityType;
use LexRanked\Core\Plugin;
use LexRanked\Core\Quality\DataQuality;
use LexRanked\Core\Services;

/**
 * GET /entities/{entity_id}       - a public entity by its stable ID.
 * GET /entities/resolve?type&slug - the current entity for a current or
 *   former slug (renames, merges). Used by the frontend to redirect old URLs.
 *
 * GET /page-eligibility          - the page eligibility rules (Etap G).
 * GET /data-quality              - the Data Quality model (dimensions, weights) and site-wide summary.
 * GET /attributes                - the data dictionary: every fact and derived
 *   metric an entity can have, its type, unit, layer and freshness rule.
 *
 * Only active (published) entities are returned; drafts and archived
 * entities are 404, merged entities resolve to the surviving one.
 */
final class EntityController extends RestController {

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
			'/attributes',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn(): \WP_REST_Response => $this->item_response( array( 'attributes' => Attributes::dictionary() ) ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/page-eligibility',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn(): \WP_REST_Response => $this->item_response( PageEligibility::model() ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/data-quality',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn(): \WP_REST_Response => $this->item_response( DataQuality::model() + array( 'summary' => $this->services->quality->summary() ) ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/entities/resolve',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'resolve' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => array(
					'type' => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => EntityType::values(),
						'validate_callback' => 'rest_validate_request_arg',
					),
					'slug' => array_merge( $this->slug_arg( 'Current or former slug.' ), array( 'required' => true ) ),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/entities/(?P<entity_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Entity by ID.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$row = $this->services->registry->find( (int) $request['entity_id'] );
		for ( $hops = 0; null !== $row && EntityNames::MERGED === $row['status'] && null !== $row['merged_into'] && $hops < 5; $hops++ ) {
			$row = $this->services->registry->find( (int) $row['merged_into'] );
		}
		return $this->respond( $row );
	}

	/**
	 * Entity by current or former slug.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function resolve( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		return $this->respond( $this->services->registry->resolve( EntityType::from( (string) $request['type'] ), (string) $request['slug'] ) );
	}

	/**
	 * Public DTO or 404.
	 *
	 * @param array<string, mixed>|null $row Row.
	 */
	private function respond( ?array $row ): \WP_REST_Response|\WP_Error {
		if ( null === $row || EntityNames::ACTIVE !== $row['status'] ) {
			return $this->not_found( 'Entity' );
		}
		return $this->item_response( $this->services->registry->dto( $row ) );
	}
}
