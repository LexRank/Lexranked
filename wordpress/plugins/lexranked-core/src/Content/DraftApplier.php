<?php
/**
 * Apply an AI content draft to its target.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Content;

use LexRanked\Core\PostTypes\Article;
use LexRanked\Core\PostTypes\ContentDraft;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\Security\AuditLog;
use LexRanked\Core\Services;

/**
 * One implementation of "Apply draft", used by the admin button and the
 * editorial API. Applying is an editor's decision: the caller must hold the
 * same capability as for editing the target, and a draft that failed QA is
 * applied only when the editor acknowledges it.
 */
final class DraftApplier {

	public const APPLIED   = 'applied';
	public const PARTIAL   = 'partial';
	public const ALREADY   = 'already';
	public const NEEDS_ACK = 'ack';

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Draft fields, or null when the post is not a content draft.
	 *
	 * @param int $draft_id Draft post ID.
	 * @return array<string, mixed>|null
	 */
	public function fields( int $draft_id ): ?array {
		$post = get_post( $draft_id );
		if ( ! $post instanceof \WP_Post || ContentDraft::SLUG !== $post->post_type ) {
			return null;
		}
		return $this->services->entities->record( $post, $this->services->content_draft )['fields'];
	}

	/**
	 * The page a draft changes.
	 *
	 * @param array<string, mixed> $fields Draft fields.
	 * @return array{label: string, edit: string}|null
	 */
	public function target( array $fields ): ?array {
		if ( 'hub_content' === $fields['content_type'] ) {
			$term = get_term( (int) $fields['target_term'], (string) $fields['target_taxonomy'] );
			return $term instanceof \WP_Term ? array(
				'label' => $term->name,
				'edit'  => (string) get_edit_term_link( $term->term_id, $term->taxonomy ),
			) : null;
		}
		$post = null === $fields['target_id'] ? null : get_post( (int) $fields['target_id'] );
		return $post instanceof \WP_Post ? array(
			'label' => get_the_title( $post ),
			'edit'  => (string) get_edit_post_link( $post->ID, 'url' ),
		) : null;
	}

	/**
	 * Whether the current user may apply this draft.
	 *
	 * @param array<string, mixed> $fields Draft fields.
	 */
	public function can_apply( array $fields ): bool {
		$target = $this->target( $fields );
		return match ( (string) $fields['content_type'] ) {
			'hub_content' => null !== $target && current_user_can( 'manage_categories' ),
			'article'     => current_user_can( 'edit_posts' ),
			default       => null !== $target && current_user_can( 'edit_post', (int) $fields['target_id'] ),
		};
	}

	/**
	 * Apply the draft. The caller checks can_apply() first.
	 *
	 * @param int  $draft_id    Draft post ID.
	 * @param bool $acknowledge The editor reviewed every QA problem of a draft that needs review.
	 * @return array{status: string, errors: array<string, string>, article_id: int|null}
	 * @throws \RuntimeException When an article draft cannot be created.
	 */
	public function apply( int $draft_id, bool $acknowledge ): array {
		$post   = get_post( $draft_id );
		$fields = (array) $this->fields( $draft_id );
		$result = array(
			'status'     => self::APPLIED,
			'errors'     => array(),
			'article_id' => null,
		);
		if ( ContentDraft::QA_APPLIED === $fields['qa_status'] ) {
			$result['status'] = self::ALREADY;
			return $result;
		}
		if ( ContentDraft::QA_NEEDS_REVIEW === $fields['qa_status'] && ! $acknowledge ) {
			$result['status'] = self::NEEDS_ACK;
			return $result;
		}

		$faq     = is_array( $fields['faq'] ) ? $fields['faq'] : array();
		$errors  = array();
		$changes = array(
			'qa_status'  => ContentDraft::QA_APPLIED,
			'applied_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
		switch ( (string) $fields['content_type'] ) {
			case 'hub_content':
				$errors = TermContent::store(
					(int) $fields['target_term'],
					array(
						'summary' => (string) $fields['summary'],
						'body'    => (string) $post->post_content,
						'faq'     => $faq,
					)
				);
				HubContentService::release( (int) $fields['target_term'] );
				$this->services->revalidator->on_term();
				break;
			case 'profile_summary':
				$entity = get_post( (int) $fields['target_id'] );
				$def    = $entity instanceof \WP_Post && LawFirm::SLUG === $entity->post_type ? $this->services->law_firm : $this->services->lawyer;
				$errors = $this->services->entities->save_fields( (int) $fields['target_id'], $def, array( 'summary' => $fields['summary'] ) );
				break;
			case 'article':
				$article = wp_insert_post(
					wp_slash(
						array(
							'post_type'    => Article::SLUG,
							'post_status'  => 'draft',
							'post_title'   => (string) preg_replace( '/^AI draft: (.*) \(\d{4}-\d{2}-\d{2}\)$/', '$1', (string) $post->post_title ),
							'post_content' => (string) $post->post_content,
							'post_excerpt' => (string) $fields['summary'],
						)
					),
					true
				);
				if ( is_wp_error( $article ) ) {
					throw new \RuntimeException( 'Could not create the article draft.' );
				}
				if ( null !== $fields['target_id'] ) {
					$this->services->entities->save_fields( (int) $article, $this->services->article, array( 'related_ranking' => (int) $fields['target_id'] ) );
				}
				$changes['article_id'] = (int) $article;
				$result['article_id']  = (int) $article;
				break;
			default:
				$errors = $this->services->entities->save_fields(
					(int) $fields['target_id'],
					$this->services->ranking,
					array(
						'summary' => $fields['summary'],
						'faq'     => $faq,
					)
				);
				wp_update_post(
					wp_slash(
						array(
							'ID'           => (int) $fields['target_id'],
							'post_content' => (string) $post->post_content,
						)
					)
				);
				// Reviewed text replaces generated text and is no longer regenerated.
				$this->services->ranking_content->release( (int) $fields['target_id'] );
		}//end switch
		$this->services->entities->save_fields( $draft_id, $this->services->content_draft, $changes, true );
		AuditLog::log(
			'content_draft.applied',
			ContentDraft::SLUG,
			$draft_id,
			array(
				'content_type' => $fields['content_type'],
				'target'       => $this->target( $fields )['label'] ?? null,
				'acknowledged' => $acknowledge,
				'invalid'      => array_keys( $errors ),
			)
		);
		$result['errors'] = $errors;
		$result['status'] = array() === $errors ? self::APPLIED : self::PARTIAL;
		return $result;
	}
}
