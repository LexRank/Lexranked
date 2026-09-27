<?php
/**
 * Entity types.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Entity;

use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * The kinds of things LexRanked knows about. Each is stored by WordPress
 * (a post or a term) and registered in lr_entities under a stable entity_id.
 * New kinds are added here plus their WordPress storage.
 */
enum EntityType: string {
	case Lawyer       = 'lawyer';
	case LawFirm      = 'law_firm';
	case Location     = 'location';
	case PracticeArea = 'practice_area';

	/**
	 * WordPress storage kind: "post" or "term".
	 */
	public function wp_object(): string {
		return match ( $this ) {
			self::Lawyer, self::LawFirm => 'post',
			self::Location, self::PracticeArea => 'term',
		};
	}

	/**
	 * Post type or taxonomy slug.
	 */
	public function wp_kind(): string {
		return match ( $this ) {
			self::Lawyer       => Lawyer::SLUG,
			self::LawFirm      => LawFirm::SLUG,
			self::Location     => Location::SLUG,
			self::PracticeArea => PracticeArea::SLUG,
		};
	}

	/**
	 * Type for a post type or taxonomy slug.
	 *
	 * @param string $kind Post type or taxonomy.
	 */
	public static function from_wp_kind( string $kind ): ?self {
		foreach ( self::cases() as $case ) {
			if ( $case->wp_kind() === $kind ) {
				return $case;
			}
		}
		return null;
	}

	/**
	 * Public path of an entity of this type.
	 *
	 * @param string $slug      Slug.
	 * @param bool   $has_parent For locations: a city (has a parent state) or a state.
	 */
	public function path( string $slug, bool $has_parent = false ): string {
		return match ( $this ) {
			self::Lawyer       => '/lawyers/' . $slug . '/',
			self::LawFirm      => '/law-firms/' . $slug . '/',
			self::Location     => ( $has_parent ? '/cities/' : '/states/' ) . $slug . '/',
			self::PracticeArea => '/practice-areas/' . $slug . '/',
		};
	}

	/**
	 * All values.
	 *
	 * @return array<int, string>
	 */
	public static function values(): array {
		return array_map( static fn( self $t ): string => $t->value, self::cases() );
	}
}
