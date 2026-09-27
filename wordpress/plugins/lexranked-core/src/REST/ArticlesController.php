<?php
/**
 * Editorial articles API.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST;

use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\Article;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\REST\DTO\ArticleMapper;
use LexRanked\Core\REST\DTO\RankingMapper;
use LexRanked\Core\Services;

/**
 * GET /articles and /articles/{id|slug}: published, non-password posts.
 */
final class ArticlesController extends RestController {

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
			'/articles',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => $this->collection_args(
					array( 'date', 'modified', 'title' ),
					array(
						'category' => $this->slug_arg( 'Category slug.' ),
					)
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/articles/' . $this->id_pattern(),
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'public_read_permission' ),
				'args'                => $this->context_arg(),
			)
		);
	}

	/**
	 * List.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$error = $this->reject_unknown_params( $request );
		if ( null !== $error ) {
			return $error;
		}
		$args = array(
			'post_type'        => Article::SLUG,
			'post_status'      => 'publish',
			'has_password'     => false,
			'posts_per_page'   => (int) $request['per_page'],
			'paged'            => (int) $request['page'],
			'orderby'          => array(
				(string) $request['orderby'] => strtoupper( (string) $request['order'] ),
				'ID'                         => 'ASC',
			),
			'suppress_filters' => false,
		);
		if ( ! empty( $request['category'] ) ) {
			$args['category_name'] = (string) $request['category'];
		}
		$query = new \WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$items[] = $this->summary( $post );
			}
		}
		return $this->collection_response( $items, (int) $query->found_posts, (int) $request['per_page'] );
	}

	/**
	 * Detail.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->services->entities->find_published( $this->services->article, (string) $request['id'] );
		if ( null === $post || post_password_required( $post ) || '' !== $post->post_password ) {
			return $this->not_found( 'Article' );
		}
		$summary = $this->summary( $post );
		$related = null;
		$record  = $this->services->entities->record( $post, $this->services->article );
		$ranking = null === $record['fields']['related_ranking'] ? null : get_post( (int) $record['fields']['related_ranking'] );
		if ( $ranking instanceof \WP_Post && Ranking::SLUG === $ranking->post_type && 'publish' === $ranking->post_status ) {
			$rrec    = $this->services->entities->record( $ranking, $this->services->ranking );
			$related = array(
				'id'    => (int) $ranking->ID,
				'title' => $rrec['title'],
				'path'  => RankingMapper::record_path( $rrec ),
			);
		}
		return $this->item_response( ArticleMapper::detail( $summary, $this->body( $post ), $related ) );
	}

	/**
	 * Summary DTO for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private function summary( \WP_Post $post ): array {
		$record     = $this->services->entities->record( $post, $this->services->article );
		$categories = array();
		foreach ( get_the_category( $post->ID ) as $term ) {
			if ( 'uncategorized' !== $term->slug ) {
				$categories[] = array(
					'slug' => (string) $term->slug,
					'name' => (string) $term->name,
				);
			}
		}
		$image    = null;
		$thumb_id = (int) get_post_thumbnail_id( $post );
		if ( $thumb_id > 0 ) {
			$src = wp_get_attachment_image_src( $thumb_id, 'large' );
			if ( is_array( $src ) ) {
				$image = array(
					'url'    => (string) $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
					'alt'    => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
				);
			}
		}
		$name   = (string) get_the_author_meta( 'display_name', (int) $post->post_author );
		$author = array( 'name' => '' === $name ? 'LexRanked Editorial Team' : $name );
		return ArticleMapper::summary( $record, (string) $post->post_excerpt, $this->body( $post ), $author, $image, $categories );
	}

	/**
	 * Sanitized body HTML (blocks rendered, no theme filters).
	 *
	 * @param \WP_Post $post Post.
	 */
	private function body( \WP_Post $post ): string {
		$content = (string) $post->post_content;
		$content = has_blocks( $content ) ? do_blocks( $content ) : wpautop( $content );
		return '' === trim( $content ) ? '' : wp_kses_post( $content );
	}
}
