<?php
/**
 * Location DTO mapping.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST\DTO;

use LexRanked\Core\Domain\UsStates;

/**
 * Resolves assigned location terms (state → city) into a location DTO.
 */
final class LocationMapper {

	/**
	 * Build {city, citySlug, state, stateSlug, stateCode} from plain term arrays.
	 *
	 * The most specific assigned term wins (a city beats a state). Terms are
	 * processed in ID order so the result is deterministic.
	 *
	 * @param array<int, array{id: int, slug: string, name: string, parent: int, state_code: string|null}> $terms Assigned terms plus their parents.
	 * @return array{city: string|null, citySlug: string|null, state: string|null, stateSlug: string|null, stateCode: string|null}|null
	 */
	public static function from_terms( array $terms ): ?array {
		if ( array() === $terms ) {
			return null;
		}
		$by_id = array();
		foreach ( $terms as $term ) {
			$by_id[ $term['id'] ] = $term;
		}
		ksort( $by_id );

		$city  = null;
		$state = null;
		foreach ( $by_id as $term ) {
			if ( 0 !== $term['parent'] && null === $city ) {
				$city  = $term;
				$state = $by_id[ $term['parent'] ] ?? null;
			}
		}
		if ( null === $state ) {
			foreach ( $by_id as $term ) {
				if ( 0 === $term['parent'] ) {
					$state = $term;
					break;
				}
			}
		}

		return self::build( $city, $state );
	}

	/**
	 * Build a DTO from a city and/or state term.
	 *
	 * @param array<string, mixed>|null $city  City term.
	 * @param array<string, mixed>|null $state State term.
	 * @return array{city: string|null, citySlug: string|null, state: string|null, stateSlug: string|null, stateCode: string|null}
	 */
	public static function build( ?array $city, ?array $state ): array {
		$code = $state['state_code'] ?? null;
		return array(
			'city'      => $city['name'] ?? null,
			'citySlug'  => $city['slug'] ?? null,
			'state'     => null !== $state ? ( null !== $code ? UsStates::name( $code ) ?? $state['name'] : $state['name'] ) : null,
			'stateSlug' => $state['slug'] ?? null,
			'stateCode' => $code,
		);
	}
}
