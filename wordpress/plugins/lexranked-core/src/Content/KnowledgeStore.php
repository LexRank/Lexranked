<?php
/**
 * Knowledge added after release: practice areas and cities stored in the
 * database on top of the knowledge pack shipped with the plugin.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Content;

/**
 * The shipped pack (src/Content/knowledge/{STATE}.json) stays the base. An
 * editor can add a practice area or a city, or replace a practice area, over
 * the API without a plugin update; each entry must pass the same
 * completeness rules as the shipped pack, so generated pages keep the same
 * standard. A stored city adds to the shipped one key by key, so it never
 * drops data such as crash figures.
 */
final class KnowledgeStore {

	public const OPTION_PREFIX = 'lexranked_knowledge_';

	/**
	 * Stored additions for a state.
	 *
	 * @param string $code Two-letter state code.
	 * @return array{areas: array<string, array<string, mixed>>, cities: array<string, array<string, string>>}
	 */
	public static function additions( string $code ): array {
		$empty = array(
			'areas'  => array(),
			'cities' => array(),
		);
		if ( ! function_exists( 'get_option' ) || 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
			return $empty;
		}
		$stored = get_option( self::OPTION_PREFIX . $code, array() );
		if ( ! is_array( $stored ) ) {
			return $empty;
		}
		return array(
			'areas'  => is_array( $stored['areas'] ?? null ) ? $stored['areas'] : array(),
			'cities' => is_array( $stored['cities'] ?? null ) ? $stored['cities'] : array(),
		);
	}

	/**
	 * The shipped pack with stored additions applied. Entries that no longer
	 * pass validation are ignored, so a bad row can never reach a page.
	 *
	 * @param array<string, mixed>                                                                            $pack      Shipped pack.
	 * @param array{areas: array<string, array<string, mixed>>, cities: array<string, array<string, string>>} $additions Stored additions.
	 * @return array<string, mixed>
	 */
	public static function merge( array $pack, array $additions ): array {
		foreach ( $additions['areas'] as $slug => $area ) {
			if ( self::valid_slug( (string) $slug ) && is_array( $area ) && array() === self::area_errors( $area ) ) {
				$pack['areas'][ $slug ] = $area;
			}
		}
		foreach ( $additions['cities'] as $slug => $city ) {
			if ( self::valid_slug( (string) $slug ) && is_array( $city ) && array() === self::city_errors( $city ) ) {
				$pack['cities'][ $slug ] = array_merge( (array) ( $pack['cities'][ $slug ] ?? array() ), $city );
			}
		}
		return $pack;
	}

	/**
	 * Store a practice area (adds or replaces).
	 *
	 * @param string               $code State code.
	 * @param string               $slug Practice area slug.
	 * @param array<string, mixed> $area Area entry.
	 * @return array<int, string> Errors (empty when stored).
	 */
	public static function save_area( string $code, string $slug, array $area ): array {
		$area   = self::clean_area( $area );
		$errors = self::valid_slug( $slug ) ? self::area_errors( $area ) : array( 'slug: lowercase words joined by hyphens' );
		if ( array() === $errors ) {
			self::put( $code, 'areas', $slug, $area );
		}
		return $errors;
	}

	/**
	 * Store a city (adds, or adds keys to a shipped city).
	 *
	 * @param string               $code State code.
	 * @param string               $slug City slug.
	 * @param array<string, mixed> $city City entry.
	 * @return array<int, string> Errors (empty when stored).
	 */
	public static function save_city( string $code, string $slug, array $city ): array {
		$city   = array(
			'county'  => trim( (string) ( $city['county'] ?? '' ) ),
			'circuit' => trim( (string) ( $city['circuit'] ?? '' ) ),
		);
		$errors = self::valid_slug( $slug ) ? self::city_errors( $city ) : array( 'slug: lowercase words joined by hyphens' );
		if ( array() === $errors ) {
			self::put( $code, 'cities', $slug, $city );
		}
		return $errors;
	}

	/**
	 * Remove a stored entry (the shipped pack is never changed).
	 *
	 * @param string $code State code.
	 * @param string $kind "areas" or "cities".
	 * @param string $slug Slug.
	 * @return bool Whether an entry was removed.
	 */
	public static function remove( string $code, string $kind, string $slug ): bool {
		$stored = self::additions( $code );
		if ( ! isset( $stored[ $kind ][ $slug ] ) ) {
			return false;
		}
		unset( $stored[ $kind ][ $slug ] );
		update_option( self::OPTION_PREFIX . $code, $stored, false );
		return true;
	}

	/**
	 * What is missing or wrong in a practice area entry; the rules match the
	 * shipped pack: a certification, a lead, rule rows, primary sources over
	 * HTTPS, FAQ with answers, and internal guide links.
	 *
	 * @param array<string, mixed> $area Area entry.
	 * @return array<int, string>
	 */
	public static function area_errors( array $area ): array {
		$errors = array();
		foreach ( array( 'cert', 'certName', 'lead' ) as $key ) {
			if ( ! is_string( $area[ $key ] ?? null ) || '' === trim( $area[ $key ] ) ) {
				$errors[] = $key . ': required';
			}
		}
		if ( array() === $errors && ! str_contains( (string) $area['certName'], (string) $area['cert'] ) ) {
			$errors[] = 'certName: must contain cert';
		}
		$pairs = array(
			'rows' => array( 1, 10 ),
			'src'  => array( 1, 12 ),
			'faq'  => array( 1, 10 ),
		);
		foreach ( $pairs as $key => [$min, $max] ) {
			if ( ! self::pairs( $area[ $key ] ?? null, $min, $max ) ) {
				$errors[] = sprintf( '%s: %d to %d [text, text] pairs', $key, $min, $max );
			}
		}
		if ( ! self::pairs( $area['guides'] ?? array(), 0, 10 ) ) {
			$errors[] = 'guides: up to 10 [path, title] pairs';
		}
		foreach ( (array) ( $area['src'] ?? array() ) as $src ) {
			if ( is_array( $src ) && ( ! is_string( $src[0] ?? null ) || 1 !== preg_match( '#^https://[^\s<>"]+$#', $src[0] ) ) ) {
				$errors[] = 'src: every source must be an https:// URL';
				break;
			}
		}
		if ( array_key_exists( 'hub', $area ) ) {
			$errors = array_merge( $errors, self::hub_errors( $area['hub'] ) );
		}
		foreach ( (array) ( $area['guides'] ?? array() ) as $guide ) {
			if ( is_array( $guide ) && ( ! is_string( $guide[0] ?? null ) || 1 !== preg_match( '#^/[a-z0-9/-]+/$#', $guide[0] ) ) ) {
				$errors[] = 'guides: every link must be a site path such as /articles/slug/';
				break;
			}
		}
		return $errors;
	}

	/**
	 * What is missing in a city entry.
	 *
	 * @param array<string, mixed> $city City entry.
	 * @return array<int, string>
	 */
	public static function city_errors( array $city ): array {
		$errors = array();
		foreach ( array( 'county', 'circuit' ) as $key ) {
			if ( ! is_string( $city[ $key ] ?? null ) || '' === trim( $city[ $key ] ) || strlen( $city[ $key ] ) > 60 ) {
				$errors[] = $key . ': required, at most 60 characters';
			}
		}
		return $errors;
	}

	/**
	 * Keep only the known keys, with trimmed text.
	 *
	 * @param array<string, mixed> $area Area entry.
	 * @return array<string, mixed>
	 */
	private static function clean_area( array $area ): array {
		$text  = static fn( mixed $v ): mixed => is_string( $v ) ? trim( $v ) : $v;
		$pairs = static fn( mixed $items ): mixed => is_array( $items ) ? array_values( array_map( static fn( mixed $p ): mixed => is_array( $p ) ? array_map( $text, array_values( $p ) ) : $p, $items ) ) : $items;
		$clean = array(
			'cert'     => $text( $area['cert'] ?? null ),
			'lead'     => $text( $area['lead'] ?? null ),
			'rows'     => $pairs( $area['rows'] ?? null ),
			'src'      => $pairs( $area['src'] ?? null ),
			'faq'      => $pairs( $area['faq'] ?? null ),
			'guides'   => $pairs( $area['guides'] ?? array() ),
			'certName' => $text( $area['certName'] ?? null ),
		);
		if ( isset( $area['hub'] ) ) {
			$hub          = is_array( $area['hub'] ) ? $area['hub'] : array();
			$list         = static fn( mixed $items ): mixed => is_array( $items ) ? array_values( array_map( $text, $items ) ) : $items;
			$clean['hub'] = array(
				'what'      => $text( $hub['what'] ?? null ),
				'matters'   => $list( $hub['matters'] ?? null ),
				'whenLead'  => $text( $hub['whenLead'] ?? null ),
				'when'      => $list( $hub['when'] ?? null ),
				'cost'      => $text( $hub['cost'] ?? null ),
				'questions' => $list( $hub['questions'] ?? null ),
			);
			if ( isset( $hub['costSrc'] ) ) {
				$clean['hub']['costSrc'] = $pairs( $hub['costSrc'] );
			}
		}
		return $clean;
	}

	/**
	 * What is missing in the optional hub text of an area (what the lawyer
	 * does, when you need one, cost and questions to ask).
	 *
	 * @param mixed $hub Hub entry.
	 * @return array<int, string>
	 */
	public static function hub_errors( mixed $hub ): array {
		if ( ! is_array( $hub ) ) {
			return array( 'hub: an object' );
		}
		$errors = array();
		foreach ( array( 'what', 'whenLead', 'cost' ) as $key ) {
			if ( ! is_string( $hub[ $key ] ?? null ) || '' === trim( $hub[ $key ] ) || strlen( $hub[ $key ] ) > 1000 ) {
				$errors[] = 'hub.' . $key . ': required, at most 1000 characters';
			}
		}
		$lists = array(
			'matters'   => array( 1, 10 ),
			'when'      => array( 1, 8 ),
			'questions' => array( 1, 8 ),
		);
		foreach ( $lists as $key => [$min, $max] ) {
			$items = $hub[ $key ] ?? null;
			$valid = is_array( $items ) && array_is_list( $items ) && count( $items ) >= $min && count( $items ) <= $max;
			foreach ( $valid ? $items : array() as $item ) {
				$valid = $valid && is_string( $item ) && '' !== trim( $item ) && strlen( $item ) <= 300;
			}
			if ( ! $valid ) {
				$errors[] = sprintf( 'hub.%s: %d to %d short texts', $key, $min, $max );
			}
		}
		if ( isset( $hub['costSrc'] ) && ! self::pairs( $hub['costSrc'], 1, 3 ) ) {
			$errors[] = 'hub.costSrc: 1 to 3 [https URL, label] pairs';
		}
		foreach ( (array) ( $hub['costSrc'] ?? array() ) as $src ) {
			if ( is_array( $src ) && ( ! is_string( $src[0] ?? null ) || 1 !== preg_match( '#^https://[^\s<>"]+$#', $src[0] ) ) ) {
				$errors[] = 'hub.costSrc: every source must be an https:// URL';
				break;
			}
		}
		return $errors;
	}

	/**
	 * A list of [text, text] pairs with non-empty strings.
	 *
	 * @param mixed $items Value.
	 * @param int   $min  Minimum count.
	 * @param int   $max  Maximum count.
	 */
	private static function pairs( mixed $items, int $min, int $max ): bool {
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) < $min || count( $items ) > $max ) {
			return false;
		}
		foreach ( $items as $pair ) {
			if ( ! is_array( $pair ) || 2 !== count( $pair ) || ! array_is_list( $pair ) ) {
				return false;
			}
			foreach ( $pair as $text ) {
				if ( ! is_string( $text ) || '' === trim( $text ) || strlen( $text ) > 2000 ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Lowercase words joined by hyphens.
	 *
	 * @param string $slug Slug.
	 */
	private static function valid_slug( string $slug ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug );
	}

	/**
	 * Write one entry.
	 *
	 * @param string               $code  State code.
	 * @param string               $kind  "areas" or "cities".
	 * @param string               $slug  Slug.
	 * @param array<string, mixed> $entry Entry.
	 */
	private static function put( string $code, string $kind, string $slug, array $entry ): void {
		$stored                   = self::additions( $code );
		$stored[ $kind ][ $slug ] = $entry;
		update_option( self::OPTION_PREFIX . $code, $stored, false );
	}
}
