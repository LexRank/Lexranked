<?php
/**
 * Research worker API.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\ResearchJob;
use LexRanked\Core\Research\CandidateRepository;
use LexRanked\Core\Research\JobException;
use LexRanked\Core\Research\ResearchIngest;
use LexRanked\Core\Security\Capabilities;
use LexRanked\Core\Services;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Private endpoints for research workers (capability `lexranked_research`).
 *
 * Every write to a job's data requires the lease token from claim, sent in
 * the X-LexRanked-Lease header, so a worker that lost its lease (crash,
 * timeout, cancellation) can never write into a job another worker owns.
 * Responses are never cached.
 */
final class ResearchController extends RestController {

	/** Job types a worker may create itself when autonomous research is on (no AI types). */
	public const CREATABLE_TYPES = array( 'candidate_discovery', 'source_refresh' );

	public const LEASE_HEADER = 'x-lexranked-lease';

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
		$ns   = Plugin::REST_NAMESPACE;
		$perm = array( $this, 'permission' );
		$job  = '/research/jobs/(?P<id>\d+)';

		register_rest_route(
			$ns,
			'/research/jobs/claim',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'claim' ),
				'permission_callback' => $perm,
				'args'                => array(
					'worker' => array(
						'type'      => 'string',
						'required'  => true,
						'pattern'   => '^[A-Za-z0-9_.:@-]{1,100}$',
						'minLength' => 1,
					),
					'types'  => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array(
							'type' => 'string',
							'enum' => ResearchJob::JOB_TYPES,
						),
						'minItems' => 1,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/research/jobs',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $perm,
				'args'                => array(
					'job_type'       => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => self::CREATABLE_TYPES,
					),
					'params'         => array(
						'type'    => 'object',
						'default' => array(),
					),
					'title'          => array(
						'type'      => 'string',
						'maxLength' => 200,
						'default'   => '',
					),
					'locations'      => array(
						'type'    => 'array',
						'items'   => array(
							'type'    => 'string',
							'pattern' => '^[a-z0-9]+(-[a-z0-9]+)*$',
						),
						'default' => array(),
					),
					'practice_areas' => array(
						'type'    => 'array',
						'items'   => array(
							'type'    => 'string',
							'pattern' => '^[a-z0-9]+(-[a-z0-9]+)*$',
						),
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			$job,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			$job . '/log',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'log' ),
				'permission_callback' => $perm,
				'args'                => array(
					'after' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);
		foreach ( array( 'heartbeat', 'complete', 'fail' ) as $action ) {
			register_rest_route(
				$ns,
				$job . '/' . $action,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $action ),
					'permission_callback' => $perm,
					'args'                => $this->progress_args( 'fail' === $action ),
				)
			);
		}
		register_rest_route(
			$ns,
			$job . '/targets',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'targets' ),
				'permission_callback' => $perm,
				'args'                => array(
					'after' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 25,
					),
				),
			)
		);
		foreach ( array( 'candidates', 'sources', 'claims', 'verifications' ) as $collection ) {
			register_rest_route(
				$ns,
				$job . '/' . $collection,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit_' . $collection ),
					'permission_callback' => $perm,
					'args'                => array(
						'items' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'object' ),
							'minItems' => 1,
							'maxItems' => ResearchIngest::MAX_BATCH,
						),
					),
				)
			);
		}
		register_rest_route(
			$ns,
			$job . '/auto-publish',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rerun_auto_publish' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			$job . '/review-candidates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'review_candidates' ),
				'permission_callback' => $perm,
				'args'                => array(
					'after' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 25,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			$job . '/candidate-notes',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit_candidate_notes' ),
				'permission_callback' => $perm,
				'args'                => array(
					'items' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'object' ),
						'minItems' => 1,
						'maxItems' => ResearchIngest::MAX_BATCH,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			$job . '/content-drafts',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit_content_draft' ),
				'permission_callback' => $perm,
				'args'                => array(
					'content_type'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'target_id'       => array(
						'type'    => array( 'integer', 'null' ),
						'minimum' => 1,
					),
					'target_term'     => array(
						'type'    => array( 'integer', 'null' ),
						'minimum' => 1,
					),
					'target_taxonomy' => array(
						'type' => array( 'string', 'null' ),
					),
					'content'         => array(
						'type'     => 'object',
						'required' => true,
					),
					'facts'           => array(
						'type'     => 'array',
						'required' => true,
					),
					'qa'              => array(
						'type'     => 'object',
						'required' => true,
					),
					'model'           => array(
						'type'     => 'string',
						'required' => true,
					),
					'prompt_version'  => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/research/candidates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'candidates' ),
				'permission_callback' => $perm,
				'args'                => array(
					'status'   => array(
						'type' => 'string',
						'enum' => CandidateRepository::STATUSES,
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::MAX_PER_PAGE,
						'default' => self::DEFAULT_PER_PAGE,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/research/candidates/(?P<id>\d+)/resolve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resolve' ),
				'permission_callback' => $perm,
				'args'                => array(
					'action'    => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'match', 'create', 'reject', 'needs_review' ),
					),
					'entity_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'reason'    => array(
						'type'      => 'string',
						'maxLength' => 500,
						'default'   => '',
					),
				),
			)
		);
	}

	/**
	 * Only research workers and administrators.
	 */
	public function permission(): bool|\WP_Error {
		if ( current_user_can( Capabilities::RESEARCH ) ) {
			return true;
		}
		return new \WP_Error( 'lexranked_forbidden', 'Research access requires the lexranked_research capability.', array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * POST /research/jobs/claim
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function claim( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			fn(): array => array( 'job' => $this->services->jobs->claim( (string) $request['worker'], (array) $request['types'] ) )
		);
	}

	/**
	 * POST /research/jobs - only when Settings → "Autonomous research" is on.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->services->settings->get( 'research_autonomy' ) ) {
			return new \WP_Error( 'lexranked_forbidden', 'Creating research jobs through the API requires "Autonomous research" in LexRanked → Settings.', array( 'status' => 403 ) );
		}
		$type   = (string) $request['job_type'];
		$params = (array) $request['params'];
		if ( 'candidate_discovery' === $type && ( ! is_string( $params['dataset'] ?? null ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $params['dataset'] ) ) ) {
			return new \WP_Error( 'lexranked_invalid_param', 'candidate_discovery needs params.dataset (a dataset name: lowercase letters, digits, "-" or "_").', array( 'status' => 400 ) );
		}
		$scope = array();
		foreach ( array(
			'locations'      => Location::SLUG,
			'practice_areas' => PracticeArea::SLUG,
		) as $key => $taxonomy ) {
			$scope[ $key ] = array();
			foreach ( (array) $request[ $key ] as $slug ) {
				$term = get_term_by( 'slug', (string) $slug, $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					return new \WP_Error( 'lexranked_invalid_param', sprintf( 'Unknown %s "%s".', 'locations' === $key ? 'location' : 'practice area', $slug ), array( 'status' => 400 ) );
				}
				$scope[ $key ][] = (int) $term->term_id;
			}
		}
		try {
			$id = $this->services->jobs->create( $type, $params, trim( wp_strip_all_tags( (string) $request['title'] ) ), $scope['locations'], $scope['practice_areas'] );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'lexranked_job_not_created', 'The research job could not be created.', array( 'status' => 500 ) );
		}
		return $this->run( fn(): array => $this->services->jobs->view( $id ) );
	}

	/**
	 * GET /research/jobs/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ): array {
				$id = (int) $request['id'];
				return $this->services->jobs->view( $id ) + array(
					'logCounts'       => $this->services->research_log->counts( $id ),
					'candidateCounts' => $this->services->candidates->counts( $id ),
				);
			}
		);
	}

	/**
	 * GET /research/jobs/{id}/log
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function log( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ): array {
				$this->services->jobs->view( (int) $request['id'] );
				// 404 for unknown jobs.
				return $this->services->research_log->for_job( (int) $request['id'], 200, (int) $request['after'] );
			}
		);
	}

	/**
	 * POST /research/jobs/{id}/heartbeat
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function heartbeat( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn(): array => $this->services->jobs->heartbeat( (int) $request['id'], $this->token( $request ), $this->progress( $request ) ) );
	}

	/**
	 * POST /research/jobs/{id}/complete
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function complete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn(): array => $this->services->jobs->complete( (int) $request['id'], $this->token( $request ), $this->progress( $request ) ) );
	}

	/**
	 * POST /research/jobs/{id}/fail
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function fail( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			fn(): array => $this->services->jobs->fail(
				(int) $request['id'],
				$this->token( $request ),
				(string) $request['error'],
				(bool) $request['retryable'],
				$this->progress( $request )
			)
		);
	}

	/**
	 * GET /research/jobs/{id}/targets
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function targets( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ): array {
				$job = $this->services->jobs->assert_active( (int) $request['id'], $this->token( $request ) );
				return $this->services->ingest->targets( $job, (int) $request['after'], (int) $request['limit'] );
			}
		);
	}

	/**
	 * POST /research/jobs/{id}/candidates
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function submit_candidates( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			fn(): array => array( 'results' => $this->services->ingest->submit_candidates( $this->job_id( $request ), (array) $request['items'] ) )
		);
	}

	/**
	 * POST /research/jobs/{id}/sources
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function submit_sources( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			fn(): array => array( 'results' => $this->services->ingest->sources( $this->job_id( $request ), (array) $request['items'] ) )
		);
	}

	/**
	 * POST /research/jobs/{id}/claims
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function submit_claims( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn(): array => $this->services->ingest->claims( $this->job_id( $request ), (array) $request['items'] ) );
	}

	/**
	 * POST /research/jobs/{id}/verifications
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function submit_verifications( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			fn(): array => array( 'results' => $this->services->ingest->verifications( $this->job_id( $request ), (array) $request['items'] ) )
		);
	}

	/**
	 * POST /research/jobs/{id}/auto-publish: finish automatic publication of
	 * a completed job (idempotent).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rerun_auto_publish( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn(): array => $this->services->jobs->rerun_auto_publish( (int) $request['id'] ) );
	}

	/**
	 * GET /research/jobs/{id}/review-candidates
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function review_candidates( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ): array {
				$this->job_id( $request );
				return $this->services->ingest->review_candidates( (int) $request['after'], (int) $request['limit'] );
			}
		);
	}

	/**
	 * POST /research/jobs/{id}/candidate-notes
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function submit_candidate_notes( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			fn(): array => array( 'results' => $this->services->ingest->ai_notes( $this->job_id( $request ), (array) $request['items'] ) )
		);
	}

	/**
	 * POST /research/jobs/{id}/content-drafts
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function submit_content_draft( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ): array {
				$payload = array();
				foreach ( array( 'content_type', 'target_id', 'target_term', 'target_taxonomy', 'content', 'facts', 'qa', 'model', 'prompt_version' ) as $key ) {
					$payload[ $key ] = $request[ $key ];
				}
				return $this->services->ingest->content_draft( $this->job_id( $request ), $payload );
			}
		);
	}

	/**
	 * GET /research/candidates
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function candidates( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$status   = null === $request['status'] ? null : (string) $request['status'];
		$per_page = (int) $request['per_page'];
		$items    = $this->services->candidates->list( $status, $per_page, ( (int) $request['page'] - 1 ) * $per_page );
		$counts   = $this->services->candidates->counts();
		$total    = null === $status ? array_sum( $counts ) : ( $counts[ $status ] ?? 0 );
		return $this->collection_response( $items, $total, $per_page, true );
	}

	/**
	 * POST /research/candidates/{id}/resolve
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function resolve( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			fn(): array => $this->services->ingest->resolve_candidate(
				(int) $request['id'],
				(string) $request['action'],
				null === $request['entity_id'] ? null : (int) $request['entity_id'],
				(string) $request['reason']
			)
		);
	}

	/**
	 * Execute and translate JobException into WP_Error.
	 *
	 * @param callable $callback Work returning the response body.
	 */
	private function run( callable $callback ): \WP_REST_Response|\WP_Error {
		try {
			return $this->item_response( $callback(), true );
		} catch ( JobException $e ) {
			return new \WP_Error( $e->error_code, $e->getMessage(), array( 'status' => $e->status ) );
		}
	}

	/**
	 * Lease token from the header.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function token( \WP_REST_Request $request ): string {
		return trim( (string) $request->get_header( self::LEASE_HEADER ) );
	}

	/**
	 * Job ID after verifying the lease.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @throws JobException When the lease is not held.
	 */
	private function job_id( \WP_REST_Request $request ): int {
		$id = (int) $request['id'];
		$this->services->jobs->assert_active( $id, $this->token( $request ) );
		return $id;
	}

	/**
	 * Progress payload from the request body.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function progress( \WP_REST_Request $request ): array {
		$out = array();
		foreach ( array( 'cursor', 'processed_count', 'stats', 'logs' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$out[ $key ] = $request[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Args for heartbeat/complete/fail.
	 *
	 * @param bool $is_fail Include error/retryable.
	 * @return array<string, mixed>
	 */
	private function progress_args( bool $is_fail ): array {
		$args = array(
			'cursor'          => array(
				'type'      => array( 'string', 'null' ),
				'maxLength' => 255,
			),
			'processed_count' => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'stats'           => array( 'type' => 'object' ),
			'logs'            => array(
				'type'     => 'array',
				'items'    => array( 'type' => 'object' ),
				'maxItems' => 100,
			),
		);
		if ( $is_fail ) {
			$args['error']     = array(
				'type'      => 'string',
				'required'  => true,
				'maxLength' => 2000,
			);
			$args['retryable'] = array(
				'type'    => 'boolean',
				'default' => true,
			);
		}
		return $args;
	}
}
