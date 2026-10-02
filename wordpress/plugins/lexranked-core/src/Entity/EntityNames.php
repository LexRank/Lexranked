<?php
/**
 * Entity identity rules.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Entity;

use LexRanked\Core\Research\CandidateNormalizer;

/**
 * Pure rules: status mapping, name normalisation and which aliases a
 * rename produces. Identity is the entity_id, never the name: a rename keeps
 * the entity and remembers the former name and slug.
 */
final class EntityNames {

	public const ACTIVE   = 'active';
	public const DRAFT    = 'draft';
	public const ARCHIVED = 'archived';
	public const MERGED   = 'merged';

	public const ALIAS_NAME = 'name';
	public const ALIAS_SLUG = 'slug';

	/**
	 * Entity status for a WordPress post status.
	 *
	 * @param string $post_status Post status.
	 */
	public static function status_for_post( string $post_status ): string {
		return match ( $post_status ) {
			'publish' => self::ACTIVE,
			'trash'   => self::ARCHIVED,
			default   => self::DRAFT,
		};
	}

	/**
	 * Normalised form used for matching names.
	 *
	 * @param EntityType $type Type.
	 * @param string     $name Name.
	 */
	public static function normalize( EntityType $type, string $name ): string {
		if ( EntityType::Lawyer === $type || EntityType::LawFirm === $type ) {
			return CandidateNormalizer::name( $name, $type->value );
		}
		return trim( (string) preg_replace( '/\s+/', ' ', strtolower( $name ) ) );
	}

	/**
	 * Aliases to record for a (new or changed) entity.
	 *
	 * @param EntityType $type Type.
	 * @param string     $name Current name.
	 * @param string     $slug Current slug.
	 * @return array<int, array{alias_type: string, value: string, normalized: string}>
	 */
	public static function aliases( EntityType $type, string $name, string $slug ): array {
		$out  = array();
		$norm = self::normalize( $type, $name );
		if ( '' !== $norm ) {
			$out[] = array(
				'alias_type' => self::ALIAS_NAME,
				'value'      => mb_substr( $name, 0, 255 ),
				'normalized' => mb_substr( $norm, 0, 255 ),
			);
		}
		if ( '' !== $slug ) {
			$out[] = array(
				'alias_type' => self::ALIAS_SLUG,
				'value'      => $slug,
				'normalized' => strtolower( $slug ),
			);
		}
		return $out;
	}

	/**
	 * Whether a stored row needs an update.
	 *
	 * @param array<string, mixed> $row    Stored row.
	 * @param string               $name   Name.
	 * @param string               $slug   Slug.
	 * @param string               $status Status.
	 */
	public static function changed( array $row, string $name, string $slug, string $status ): bool {
		return (string) $row['canonical_name'] !== $name || (string) $row['slug'] !== $slug || ( (string) $row['status'] !== $status && self::MERGED !== $row['status'] );
	}
}
