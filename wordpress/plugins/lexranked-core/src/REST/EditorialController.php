<?php
/**
 * Private editorial API: page text for rankings, hubs and profiles.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Content\DraftApplier;
use LexRanked\Core\Content\TermContent;
use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\ContentDraft;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\Security\AuditLog;
use LexRanked\Core\Services;
use LexRanked\Core\Support\Text;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * What an editor does in wp-admin, over the API: the summary, guide and FAQ
 * of rankings and hubs, profile summaries, the ranking title and slug, and
 * applying AI content drafts. Each route checks the same capability as the
 * admin screen and every change is audit-logged.
 *
 * It never touches scores, positions, facts, evidence or verification: those
 * stay with the ranking engine and the research pipeline.
 */
final class EditorialController extends RestController {

	/** Hub taxonomies by their URL segment. */
	private const TAXONOMIES = array(
		'location'      => Location::SLUG,
		'practice-area' => PracticeArea::SLUG,
	);

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
		$perm = static fn(): bool => current_user_can( 'edit_posts' );
		$text = array(
			'summary'     => array(
				'type'      => 'string',
				'maxLength' => 1000,
			),
			'body'        => array(
				'type'      => 'string',
				'maxLength' => 20000,
			),
			'faq'         => array(
				'type'     => 'array',
				'maxItems' => 20,
				'items'    => array(
					'type'                 => 'object',
					'required'             => array( 'question', 'answer' ),
					'additionalProperties' => false,
					'properties'           => array(
						'question' => array(
							'type'      => 'string',
							'minLength' => 3,
							'maxLength' => 300,
						),
						'answer'   => array(
							'type'      => 'string',
							'minLength' => 3,
							'maxLength' => 2000,
						),
					),
				),
			),
			'reviewed_by' => array(
				'type'      => 'string',
				'maxLength' => 100,
			),
			'reviewed_at' => array(
				'type'    => 'string',
				'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
			),
		);

		register_rest_route(
			$ns,
			'/editorial/rankings/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show_ranking' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_ranking' ),
					'permission_callback' => $perm,
					'args'                => $text + array(
						'title' => array(
							'type'      => 'string',
							'minLength' => 3,
							'maxLength' => 200,
						),
						'slug'  => array(
							'type'    => 'string',
							'pattern' => '^[a-z0-9]+(-[a-z0-9]+)*$',
						),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/editorial/rankings/(?P<id>\d+)/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_ranking' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			'/editorial/terms/(?P<taxonomy>location|practice-area)/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show_term' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_term' ),
					'permission_callback' => $perm,
					'args'                => $text,
				),
			)
		);
		register_rest_route(
			$ns,
			'/editorial/profiles/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_profile' ),
				'permission_callback' => $perm,
				'args'                => array( 'summary' => $text['summary'] + array( 'required' => true ) ),
			)
		);
		register_rest_route(
			$ns,
			'/editorial/drafts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'drafts' ),
				'permission_callback' => $perm,
				'args'                => array(
					'qa_status' => array(
						'type' => 'string',
						'enum' => ContentDraft::QA_STATUSES,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/editorial/drafts/(?P<id>\d+)/apply',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_draft' ),
				'permission_callback' => $perm,
				'args'                => array(
					'acknowledge' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * GET /editorial/rankings/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function show_ranking( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->ranking( (int) $request['id'] );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}
		return $this->item_response( $this->ranking_view( $post ), true );
	}

	/**
	 * POST /editorial/rankings/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function update_ranking( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->ranking( (int) $request['id'] );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}
		$params = $request->get_params();
		$fields = array();
		foreach ( array( 'summary', 'faq', 'reviewed_by', 'reviewed_at' ) as $key ) {
			if ( array_key_exists( $key, $params ) ) {
				$fields[ $key ] = '' === $params[ $key ] ? null : $params[ $key ];
			}
		}
		$errors = array() === $fields ? array() : $this->services->entities->save_fields( $post->ID, $this->services->ranking, $fields );
		if ( array() !== $errors ) {
			return new \WP_Error(
				'lexranked_invalid_param',
				'Invalid editorial fields.',
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}
		$update = array( 'ID' => $post->ID );
		if ( isset( $params['title'] ) ) {
			$update['post_title'] = sanitize_text_field( (string) $params['title'] );
		}
		if ( isset( $params['slug'] ) ) {
			$update['post_name'] = (string) $params['slug'];
		}
		if ( array_key_exists( 'body', $params ) ) {
			$update['post_content'] = wp_kses_post( (string) $params['body'] );
		}
		// Always save the post: it runs the hooks that refresh the public page.
		$saved = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $saved ) ) {
			return new \WP_Error( 'lexranked_not_saved', 'The ranking could not be saved.', array( 'status' => 500 ) );
		}
		if ( array() !== array_intersect_key( $params, array_flip( array( 'summary', 'body', 'faq' ) ) ) ) {
			// Text written by an editor is kept: it is no longer regenerated after recalculations.
			$this->services->ranking_content->release( $post->ID );
		}
		AuditLog::log( 'editorial.ranking_updated', Ranking::SLUG, $post->ID, array( 'fields' => array_keys( array_intersect_key( $params, $this->changeable() ) ) ) );
		$fresh = get_post( $post->ID );
		return $this->item_response( $this->ranking_view( $fresh instanceof \WP_Post ? $fresh : $post ), true );
	}

	/**
	 * POST /editorial/rankings/{id}/generate: replace the text with generated
	 * text from the ranking's facts, kept in step after every recalculation.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function generate_ranking( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->ranking( (int) $request['id'] );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}
		$content = $this->services->ranking_content->generate( $post->ID );
		if ( null === $content ) {
			return new \WP_Error( 'lexranked_no_content', 'Complete text cannot be generated for this ranking (no calculation yet, or no verified knowledge for its state, practice area or city).', array( 'status' => 422 ) );
		}
		$this->services->ranking_content->apply( $post->ID, $content );
		AuditLog::log( 'editorial.ranking_generated', Ranking::SLUG, $post->ID, array( 'version' => \LexRanked\Core\Content\RankingContentBuilder::VERSION ) );
		$fresh = get_post( $post->ID );
		return $this->item_response( $this->ranking_view( $fresh instanceof \WP_Post ? $fresh : $post ) + array( 'generated' => true ), true );
	}

	/**
	 * GET /editorial/terms/{taxonomy}/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function show_term( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$term = $this->term( (string) $request['taxonomy'], (int) $request['id'] );
		if ( $term instanceof \WP_Error ) {
			return $term;
		}
		return $this->item_response( $this->term_view( $term ), true );
	}

	/**
	 * POST /editorial/terms/{taxonomy}/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function update_term( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$term = $this->term( (string) $request['taxonomy'], (int) $request['id'] );
		if ( $term instanceof \WP_Error ) {
			return $term;
		}
		$input  = array_intersect_key( $request->get_params(), array_flip( array( 'summary', 'body', 'faq', 'reviewed_by', 'reviewed_at' ) ) );
		$errors = TermContent::store( $term->term_id, $input );
		if ( array() !== $errors ) {
			return new \WP_Error(
				'lexranked_invalid_param',
				'Invalid editorial fields.',
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}
		$this->services->revalidator->on_term();
		AuditLog::log( 'editorial.term_updated', $term->taxonomy, $term->term_id, array( 'fields' => array_keys( $input ) ) );
		return $this->item_response( $this->term_view( $term ), true );
	}

	/**
	 * POST /editorial/profiles/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function update_profile( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( Lawyer::SLUG, LawFirm::SLUG ), true ) || 'trash' === $post->post_status ) {
			return new \WP_Error( 'lexranked_not_found', 'Profile not found.', array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->forbidden();
		}
		$type   = LawFirm::SLUG === $post->post_type ? $this->services->law_firm : $this->services->lawyer;
		$errors = $this->services->entities->save_fields( $id, $type, array( 'summary' => '' === $request['summary'] ? null : (string) $request['summary'] ) );
		if ( array() !== $errors ) {
			return new \WP_Error(
				'lexranked_invalid_param',
				'Invalid summary.',
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}
		wp_update_post( array( 'ID' => $id ) );
		AuditLog::log( 'editorial.profile_updated', $post->post_type, $id, array( 'fields' => array( 'summary' ) ) );
		return $this->item_response(
			array(
				'id'      => $id,
				'name'    => Text::title( $id ),
				'summary' => $this->services->entities->record( $post, $type )['fields']['summary'],
			),
			true
		);
	}

	/**
	 * GET /editorial/drafts
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function drafts( \WP_REST_Request $request ): \WP_REST_Response {
		$applier = new DraftApplier( $this->services );
		$items   = array();
		$posts   = get_posts(
			array(
				'post_type'        => ContentDraft::SLUG,
				'post_status'      => array( 'draft', 'pending', 'publish', 'private' ),
				'posts_per_page'   => 100,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		foreach ( $posts as $post ) {
			$fields = $this->services->entities->record( $post, $this->services->content_draft )['fields'];
			if ( null !== $request['qa_status'] && $fields['qa_status'] !== $request['qa_status'] ) {
				continue;
			}
			$items[] = array(
				'id'          => (int) $post->ID,
				'title'       => Text::title( $post ),
				'contentType' => $fields['content_type'],
				'qaStatus'    => $fields['qa_status'],
				'target'      => $applier->target( $fields )['label'] ?? null,
				'summary'     => $fields['summary'],
				'faq'         => is_array( $fields['faq'] ) ? $fields['faq'] : array(),
				'body'        => (string) $post->post_content,
				'qaReport'    => json_decode( (string) ( $fields['qa_report'] ?? '[]' ), true ),
				'canApply'    => $applier->can_apply( $fields ),
			);
		}
		return $this->collection_response( $items, count( $items ), 1 );
	}

	/**
	 * POST /editorial/drafts/{id}/apply
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function apply_draft( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id      = (int) $request['id'];
		$applier = new DraftApplier( $this->services );
		$fields  = $applier->fields( $id );
		if ( null === $fields ) {
			return new \WP_Error( 'lexranked_not_found', 'Content draft not found.', array( 'status' => 404 ) );
		}
		if ( ! $applier->can_apply( $fields ) ) {
			return $this->forbidden();
		}
		try {
			$result = $applier->apply( $id, (bool) $request['acknowledge'] );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'lexranked_not_saved', $e->getMessage(), array( 'status' => 500 ) );
		}
		if ( DraftApplier::NEEDS_ACK === $result['status'] ) {
			return new \WP_Error( 'lexranked_needs_review', 'This draft failed automated QA; apply it with acknowledge=true only after reviewing every problem.', array( 'status' => 409 ) );
		}
		return $this->item_response( array( 'id' => $id ) + $result, true );
	}

	/**
	 * Ranking post the current user may edit.
	 *
	 * @param int $id Post ID.
	 */
	private function ranking( int $id ): \WP_Post|\WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || Ranking::SLUG !== $post->post_type || 'trash' === $post->post_status ) {
			return new \WP_Error( 'lexranked_not_found', 'Ranking not found.', array( 'status' => 404 ) );
		}
		return current_user_can( 'edit_post', $id ) ? $post : $this->forbidden();
	}

	/**
	 * Hub term the current user may edit.
	 *
	 * @param string $segment location|practice-area.
	 * @param int    $id      Term ID.
	 */
	private function term( string $segment, int $id ): \WP_Term|\WP_Error {
		$term = get_term( $id, self::TAXONOMIES[ $segment ] ?? '' );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'lexranked_not_found', 'Location or practice area not found.', array( 'status' => 404 ) );
		}
		return current_user_can( 'manage_categories' ) ? $term : $this->forbidden();
	}

	/**
	 * Editorial view of a ranking.
	 *
	 * @param \WP_Post $post Ranking post.
	 * @return array<string, mixed>
	 */
	private function ranking_view( \WP_Post $post ): array {
		$fields = $this->services->entities->record( $post, $this->services->ranking )['fields'];
		return array(
			'id'         => (int) $post->ID,
			'title'      => (string) $post->post_title,
			'slug'       => (string) $post->post_name,
			'status'     => (string) $post->post_status,
			'summary'    => $fields['summary'],
			'body'       => (string) $post->post_content,
			'faq'        => is_array( $fields['faq'] ) ? $fields['faq'] : array(),
			'reviewedBy' => $fields['reviewed_by'],
			'reviewedAt' => $fields['reviewed_at'],
			'generated'  => $this->services->ranking_content->is_generated( (int) $post->ID ),
		);
	}

	/**
	 * Editorial view of a hub term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	private function term_view( \WP_Term $term ): array {
		$raw = TermContent::raw( $term->term_id );
		return array(
			'id'         => (int) $term->term_id,
			'taxonomy'   => $term->taxonomy,
			'name'       => $term->name,
			'slug'       => $term->slug,
			'summary'    => $raw['summary'],
			'body'       => $raw['body'],
			'faq'        => $raw['faq'],
			'reviewedBy' => $raw['reviewed_by'],
			'reviewedAt' => $raw['reviewed_at'],
		);
	}

	/**
	 * Request keys that change a ranking.
	 *
	 * @return array<string, true>
	 */
	private function changeable(): array {
		return array_fill_keys( array( 'title', 'slug', 'summary', 'body', 'faq', 'reviewed_by', 'reviewed_at' ), true );
	}

	/**
	 * 403 error.
	 */
	private function forbidden(): \WP_Error {
		return new \WP_Error( 'lexranked_forbidden', 'Sorry, you are not allowed to do that.', array( 'status' => rest_authorization_required_code() ) );
	}
}
