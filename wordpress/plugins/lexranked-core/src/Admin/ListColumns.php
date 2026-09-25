<?php
/**
 * Admin list table columns.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Admin;

use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\VerificationRecord;
use LexRanked\Core\Schema\MetaCodec;
use LexRanked\Core\Services;

/**
 * Adds score / status / demo columns to entity list screens.
 */
final class ListColumns {

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
		$columns = array(
			Lawyer::SLUG             => array(
				'score'   => 'Score',
				'is_demo' => 'Demo',
			),
			LawFirm::SLUG            => array(
				'score'   => 'Score',
				'is_demo' => 'Demo',
			),
			VerificationRecord::SLUG => array(
				'verification_type' => 'Type',
				'status'            => 'Status',
				'expires_at'        => 'Expires',
			),
		);
		foreach ( $columns as $post_type => $cols ) {
			add_filter(
				'manage_' . $post_type . '_posts_columns',
				static function ( array $existing ) use ( $cols ): array {
					$date = $existing['date'] ?? null;
					unset( $existing['date'] );
					foreach ( $cols as $key => $label ) {
						$existing[ 'lr_' . $key ] = $label;
					}
					if ( null !== $date ) {
						$existing['date'] = $date;
					}
					return $existing;
				}
			);
			add_action(
				'manage_' . $post_type . '_posts_custom_column',
				function ( string $column, int $post_id ) use ( $post_type ): void {
					if ( ! str_starts_with( $column, 'lr_' ) ) {
						return;
					}
					$field = $this->services->post_type( $post_type )?->field( substr( $column, 3 ) );
					if ( null === $field ) {
						return;
					}
					echo esc_html( FieldRenderer::display( $field, MetaCodec::decode( $field, get_post_meta( $post_id, $field->meta_key(), true ) ) ) );
				},
				10,
				2
			);
		}//end foreach
	}
}
