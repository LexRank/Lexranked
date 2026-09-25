<?php
/**
 * Structured-field meta boxes.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Admin;

use LexRanked\Core\Domain\ResearchJobStatus;
use LexRanked\Core\PostTypes\PostType;
use LexRanked\Core\PostTypes\ResearchJob;
use LexRanked\Core\PostTypes\VerificationRecord;
use LexRanked\Core\Schema\Field;
use LexRanked\Core\Schema\MetaCodec;
use LexRanked\Core\Security\AuditLog;
use LexRanked\Core\Services;

/**
 * One "Structured data" meta box per LexRanked post type, generated from the
 * field schema, with nonce + capability checks, validation and audit logging.
 */
final class MetaBoxes {

	private const NONCE_ACTION = 'lexranked_save_fields';
	private const NONCE_NAME   = 'lexranked_fields_nonce';
	private const NOTICE_KEY   = 'lexranked_field_errors_';

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		foreach ( $this->services->post_types() as $type ) {
			add_action( 'save_post_' . $type->slug(), fn( int $post_id, \WP_Post $post ) => $this->save( $post_id, $post, $type ), 10, 2 );
		}
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'default_titles' ), 10, 2 );
	}

	/**
	 * Add meta boxes.
	 */
	public function add(): void {
		foreach ( $this->services->post_types() as $type ) {
			add_meta_box(
				'lexranked-fields',
				'Structured data',
				fn( \WP_Post $post ) => $this->render( $post, $type ),
				$type->slug(),
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Post.
	 * @param PostType $type Post type.
	 */
	public function render( \WP_Post $post, PostType $type ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		echo '<p>Facts are stored as structured fields. Leave a field empty when no reliable source exists — never guess.</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $type->fields() as $field ) {
			FieldRenderer::row( $field, MetaCodec::decode( $field, get_post_meta( $post->ID, $field->meta_key(), true ) ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Save handler.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @param PostType $type    Post type.
	 */
	public function save( int $post_id, \WP_Post $post, PostType $type ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Raw values are sanitized per field type by FieldSanitizer inside save_fields().
		$input = isset( $_POST[ FieldRenderer::INPUT_NAME ] ) && is_array( $_POST[ FieldRenderer::INPUT_NAME ] )
			? wp_unslash( $_POST[ FieldRenderer::INPUT_NAME ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field by FieldSanitizer.
			: array();

		$errors = array();
		if ( $type instanceof ResearchJob ) {
			$errors = $this->apply_job_transition( $post_id, $type, $input );
		}

		$errors = array_merge( $errors, $this->services->entities->save_fields( $post_id, $type, $input ) );

		if ( array() !== $errors ) {
			set_transient( self::NOTICE_KEY . get_current_user_id(), $errors, 60 );
		}
		AuditLog::log(
			$type->slug() . '.saved',
			$type->slug(),
			$post_id,
			array(
				'status'  => $post->post_status,
				'fields'  => array_keys( array_intersect_key( $input, array_flip( array_map( static fn( Field $f ): string => $f->key, $type->fields() ) ) ) ),
				'invalid' => array_keys( $errors ),
			)
		);
	}

	/**
	 * Enforce the research job lifecycle and stamp start/completion times.
	 *
	 * @param int                  $post_id Post ID.
	 * @param ResearchJob          $type    Type.
	 * @param array<string, mixed> $input   Input (modified in place when the transition is invalid).
	 * @return array<string, string> Errors.
	 */
	private function apply_job_transition( int $post_id, ResearchJob $type, array &$input ): array {
		$field    = $type->field( 'status' );
		$current  = ResearchJobStatus::tryFrom( (string) get_post_meta( $post_id, (string) $field?->meta_key(), true ) );
		$next_raw = isset( $input['status'] ) && is_string( $input['status'] ) ? $input['status'] : '';
		$next     = ResearchJobStatus::tryFrom( $next_raw );

		if ( null === $current ) {
			// New job: may only start as pending.
			if ( null !== $next && ResearchJobStatus::Pending !== $next ) {
				$input['status'] = ResearchJobStatus::Pending->value;
				return array( 'status' => 'New research jobs start as "pending".' );
			}
			return array();
		}
		if ( null === $next || $next === $current ) {
			return array();
		}
		if ( ! $current->can_transition_to( $next ) ) {
			unset( $input['status'] );
			return array( 'status' => sprintf( 'Research job cannot move from "%s" to "%s".', $current->value, $next->value ) );
		}

		$now    = gmdate( 'Y-m-d\TH:i:s\Z' );
		$stamps = array();
		if ( ResearchJobStatus::Running === $next ) {
			$stamps['started_at'] = $now;
		}
		if ( in_array( $next, array( ResearchJobStatus::Completed, ResearchJobStatus::Failed, ResearchJobStatus::Cancelled ), true ) ) {
			$stamps['completed_at'] = $now;
		}
		if ( ResearchJobStatus::Failed === $current && ResearchJobStatus::Pending === $next ) {
			$stamps['retry_count']  = (int) get_post_meta( $post_id, (string) $type->field( 'retry_count' )?->meta_key(), true ) + 1;
			$stamps['completed_at'] = null;
		}
		$this->services->entities->save_fields( $post_id, $type, $stamps, true );
		return array();
	}

	/**
	 * Auto-generate titles for records whose title is not meaningful to editors.
	 *
	 * @param array<string, mixed> $data    Post data.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public function default_titles( array $data, array $postarr ): array {
		if ( '' !== trim( (string) ( $data['post_title'] ?? '' ) ) || ! isset( $data['post_type'] ) ) {
			return $data;
		}
		// Nonce is verified in save(); here we only derive a display title.
		$input = isset( $postarr[ FieldRenderer::INPUT_NAME ] ) && is_array( $postarr[ FieldRenderer::INPUT_NAME ] ) ? $postarr[ FieldRenderer::INPUT_NAME ] : array();
		if ( VerificationRecord::SLUG === $data['post_type'] ) {
			$entity = isset( $input['entity_id'] ) ? get_post( (int) $input['entity_id'] ) : null;
			$vtype  = isset( $input['verification_type'] ) ? sanitize_key( (string) $input['verification_type'] ) : 'check';
			if ( $entity instanceof \WP_Post ) {
				$data['post_title'] = sprintf( '%s — %s', $entity->post_title, $vtype );
			}
		} elseif ( ResearchJob::SLUG === $data['post_type'] ) {
			$job                = isset( $input['job_type'] ) ? sanitize_key( (string) $input['job_type'] ) : 'job';
			$data['post_title'] = sprintf( 'Research: %s (%s)', $job, gmdate( 'Y-m-d H:i' ) );
		}
		return $data;
	}

	/**
	 * Show validation errors after redirect.
	 */
	public function notices(): void {
		$key    = self::NOTICE_KEY . get_current_user_id();
		$errors = get_transient( $key );
		if ( ! is_array( $errors ) || array() === $errors ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-error"><p><strong>Some fields were not saved:</strong></p><ul>';
		foreach ( $errors as $message ) {
			echo '<li>' . esc_html( (string) $message ) . '</li>';
		}
		echo '</ul></div>';
	}
}
