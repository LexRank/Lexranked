<?php
/**
 * Admin form field rendering.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Admin;

use LexRanked\Core\Schema\Field;

/**
 * Renders one Field as an escaped form control.
 */
final class FieldRenderer {

	public const INPUT_NAME = 'lexranked_fields';

	/**
	 * Render a table row for a field.
	 *
	 * @param Field $field Field.
	 * @param mixed $value Current (decoded) value.
	 */
	public static function row( Field $field, mixed $value ): void {
		$id   = 'lexranked-field-' . $field->key;
		$name = self::INPUT_NAME . '[' . $field->key . ']';

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field->label );
		if ( $field->required ) {
			echo ' <span class="required" aria-hidden="true">*</span>';
		}
		echo '</label></th><td>';

		if ( $field->read_only ) {
			echo '<code id="' . esc_attr( $id ) . '">' . esc_html( self::display( $field, $value ) ) . '</code>';
			echo '<p class="description">Set by the system; not editable.</p></td></tr>';
			return;
		}

		switch ( $field->type ) {
			case Field::TYPE_TEXT:
				printf( '<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ) );
				break;
			case Field::TYPE_STRING_LIST:
				printf( '<textarea id="%s" name="%s" rows="3" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( implode( "\n", (array) $value ) ) );
				break;
			case Field::TYPE_OBJECT_LIST:
				$lines = array_map( static fn( array $item ): string => implode( ' | ', array_map( 'strval', $item ) ), (array) $value );
				printf( '<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( implode( "\n", $lines ) ) );
				break;
			case Field::TYPE_BOOL:
				printf( '<input type="hidden" name="%2$s" value="0"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s>', esc_attr( $id ), esc_attr( $name ), checked( (bool) $value, true, false ) );
				break;
			case Field::TYPE_ENUM:
				printf( '<select id="%s" name="%s"><option value="">—</option>', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $field->options as $option ) {
					printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $option ), selected( (string) $value, $option, false ) );
				}
				echo '</select>';
				break;
			case Field::TYPE_POST_REF:
				self::post_select( $field, $id, $name, null === $value ? 0 : (int) $value );
				break;
			default:
				$attrs = self::input_attributes( $field );
				printf(
					'<input type="%s" id="%s" name="%s" value="%s" class="regular-text"%s>',
					esc_attr( $attrs['type'] ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( null === $value ? '' : (string) $value ),
					$attrs['extra'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped values in input_attributes().
				);
		}//end switch

		if ( '' !== $field->help ) {
			echo '<p class="description">' . esc_html( $field->help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * HTML input type + extra attributes.
	 *
	 * @param Field $field Field.
	 * @return array{type: string, extra: string}
	 */
	private static function input_attributes( Field $field ): array {
		$extra = '';
		$type  = match ( $field->type ) {
			Field::TYPE_URL => 'url',
			Field::TYPE_EMAIL => 'email',
			Field::TYPE_PHONE => 'tel',
			Field::TYPE_DATE => 'date',
			Field::TYPE_INT, Field::TYPE_FLOAT => 'number',
			default => 'text',
		};
		if ( Field::TYPE_INT === $field->type || Field::TYPE_FLOAT === $field->type ) {
			$extra .= ' step="' . ( Field::TYPE_FLOAT === $field->type ? '0.01' : '1' ) . '"';
			if ( null !== $field->min ) {
				$extra .= ' min="' . esc_attr( (string) $field->min ) . '"';
			}
			if ( null !== $field->max ) {
				$extra .= ' max="' . esc_attr( (string) $field->max ) . '"';
			}
		}
		if ( Field::TYPE_DATETIME === $field->type ) {
			$extra .= ' placeholder="YYYY-MM-DDTHH:MM:SSZ"';
		}
		if ( $field->required ) {
			$extra .= ' required';
		}
		return array(
			'type'  => $type,
			'extra' => $extra,
		);
	}

	/**
	 * Select for post references.
	 *
	 * @param Field  $field   Field.
	 * @param string $id      Element ID.
	 * @param string $name    Input name.
	 * @param int    $current Current ID.
	 */
	private static function post_select( Field $field, string $id, string $name, int $current ): void {
		$posts = get_posts(
			array(
				'post_type'      => $field->ref_types,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		printf( '<select id="%s" name="%s"><option value="">—</option>', esc_attr( $id ), esc_attr( $name ) );
		foreach ( $posts as $post ) {
			$type_object = get_post_type_object( $post->post_type );
			$label       = get_the_title( $post ) . ( count( $field->ref_types ) > 1 && null !== $type_object ? ' (' . $type_object->labels->singular_name . ')' : '' );
			printf( '<option value="%d"%s>%s</option>', (int) $post->ID, selected( $current, (int) $post->ID, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Plain-text display value.
	 *
	 * @param Field $field Field.
	 * @param mixed $value Value.
	 */
	public static function display( Field $field, mixed $value ): string {
		if ( null === $value || array() === $value ) {
			return '—';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value );
		}
		return (string) $value;
	}
}
