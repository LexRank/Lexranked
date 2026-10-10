<?php
/**
 * Statewide figures for the data pages (Etap J).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Market;

/**
 * Pure aggregation of the published lawyers of one state: counts by city and
 * practice area, board certifications, languages, law schools and years in
 * practice. Every figure carries its sample (the lawyers with that fact), so a
 * page never presents a share of an unknown base. Nothing about a single
 * lawyer is returned.
 */
final class StateData {

	public const VERSION = 'sd-1.0';

	/** Groups smaller than this are folded out of top lists. */
	public const TOP = 25;

	private const CERT_PREFIX = 'Board Certified in ';

	/**
	 * Aggregate.
	 *
	 * @param array<int, array{city_slug: ?string, city_name: ?string, areas: array<int, array{0: string, 1: string}>, years: ?int, languages: array<int, string>, awards: array<int, string>, schools: array<int, string>}> $people One row per lawyer.
	 * @param string                                                                                                                                                                                                         $now    Calculation time (UTC, ISO 8601).
	 * @return array<string, mixed>
	 */
	public static function compute( array $people, string $now ): array {
		$cities  = array();
		$areas   = array();
		$langs   = array();
		$schools = array();
		$certs   = array();
		$years   = array();
		$counts  = array(
			'certified' => 0,
			'multi'     => 0,
			'spanish'   => 0,
		);
		$with    = array(
			'languages' => 0,
			'schools'   => 0,
		);
		foreach ( $people as $p ) {
			$spoken  = array_values( array_unique( array_filter( array_map( 'trim', $p['languages'] ) ) ) );
			$spanish = in_array( 'Spanish', $spoken, true );
			$own     = array_values( array_unique( array_filter( array_map( array( self::class, 'certification' ), $p['awards'] ) ) ) );
			$cert    = array() !== $own;
			if ( null !== $p['years'] ) {
				$years[] = $p['years'];
			}
			$counts['certified'] += $cert ? 1 : 0;
			$counts['multi']     += count( $own ) > 1 ? 1 : 0;
			$counts['spanish']   += $spanish ? 1 : 0;
			if ( array() !== $spoken ) {
				++$with['languages'];
			}
			foreach ( $spoken as $lang ) {
				$langs[ $lang ] = ( $langs[ $lang ] ?? 0 ) + 1;
			}
			$own_schools = array_values( array_unique( array_filter( array_map( 'trim', $p['schools'] ) ) ) );
			if ( array() !== $own_schools ) {
				++$with['schools'];
			}
			foreach ( $own_schools as $school ) {
				$schools[ $school ] = ( $schools[ $school ] ?? 0 ) + 1;
			}
			foreach ( $own as $cert ) {
				$certs[ $cert ] = ( $certs[ $cert ] ?? 0 ) + 1;
			}

			if ( null !== $p['city_slug'] ) {
				$c                         = $cities[ $p['city_slug'] ] ?? array(
					'slug'      => $p['city_slug'],
					'name'      => (string) $p['city_name'],
					'lawyers'   => 0,
					'spanish'   => 0,
					'certified' => 0,
					'years'     => array(),
					'areas'     => array(),
				);
				$c['lawyers']             += 1;
				$c['spanish']             += $spanish ? 1 : 0;
				$c['certified']           += $cert ? 1 : 0;
				$c['years']                = null === $p['years'] ? $c['years'] : array_merge( $c['years'], array( $p['years'] ) );
				$cities[ $p['city_slug'] ] = $c;
			}
			foreach ( $p['areas'] as [$slug, $name] ) {
				$a               = $areas[ $slug ] ?? array(
					'slug'      => $slug,
					'name'      => $name,
					'lawyers'   => 0,
					'spanish'   => 0,
					'certified' => 0,
					'years'     => array(),
					'cities'    => array(),
				);
				$a['lawyers']   += 1;
				$a['spanish']   += $spanish ? 1 : 0;
				$a['certified'] += $cert ? 1 : 0;
				$a['years']      = null === $p['years'] ? $a['years'] : array_merge( $a['years'], array( $p['years'] ) );
				if ( null !== $p['city_slug'] ) {
					$a['cities'][ $p['city_slug'] ]              = true;
					$cities[ $p['city_slug'] ]['areas'][ $slug ] = array(
						'slug'  => $slug,
						'name'  => $name,
						'count' => ( $cities[ $p['city_slug'] ]['areas'][ $slug ]['count'] ?? 0 ) + 1,
					);
				}
				$areas[ $slug ] = $a;
			}//end foreach
		}//end foreach

		$city_rows = array_map(
			static function ( array $c ): array {
				$list = array_values( $c['areas'] );
				usort( $list, array( self::class, 'by_count' ) );
				return array(
					'slug'       => $c['slug'],
					'name'       => $c['name'],
					'lawyers'    => $c['lawyers'],
					'spanish'    => $c['spanish'],
					'certified'  => $c['certified'],
					'experience' => self::spread( $c['years'] ),
					'areas'      => $list,
				);
			},
			array_values( $cities )
		);
		usort( $city_rows, static fn( array $a, array $b ): int => array( $b['lawyers'], $a['name'] ) <=> array( $a['lawyers'], $b['name'] ) );

		$area_rows = array_map(
			static fn( array $a ): array => array(
				'slug'       => $a['slug'],
				'name'       => $a['name'],
				'lawyers'    => $a['lawyers'],
				'spanish'    => $a['spanish'],
				'certified'  => $a['certified'],
				'cities'     => count( $a['cities'] ),
				'experience' => self::spread( $a['years'] ),
			),
			array_values( $areas )
		);
		usort( $area_rows, static fn( array $a, array $b ): int => array( $b['lawyers'], $a['name'] ) <=> array( $a['lawyers'], $b['name'] ) );

		return array(
			'version'        => self::VERSION,
			'lawyers'        => count( $people ),
			'certified'      => $counts['certified'],
			'multiCertified' => $counts['multi'],
			'spanish'        => $counts['spanish'],
			'cities'         => $city_rows,
			'practiceAreas'  => $area_rows,
			'certifications' => self::top( $certs, PHP_INT_MAX ),
			'languages'      => array(
				'sample' => $with['languages'],
				'items'  => self::top( $langs, PHP_INT_MAX ),
			),
			'schools'        => array(
				'sample' => $with['schools'],
				'items'  => self::top( $schools, self::TOP ),
			),
			'experience'     => self::spread( $years ) + array( 'buckets' => self::buckets( $years ) ),
			'calculatedAt'   => $now,
		);
	}

	/**
	 * "Civil Trial Law" from "Board Certified in Civil Trial Law".
	 *
	 * @param string $award Award name.
	 */
	public static function certification( string $award ): string {
		$award = trim( $award );
		return str_starts_with( $award, self::CERT_PREFIX ) ? substr( $award, strlen( self::CERT_PREFIX ) ) : '';
	}

	/**
	 * Median and range, with the sample.
	 *
	 * @param array<int, int> $values Values.
	 * @return array{sample: int, min: ?int, median: ?float, max: ?int}
	 */
	public static function spread( array $values ): array {
		if ( array() === $values ) {
			return array(
				'sample' => 0,
				'min'    => null,
				'median' => null,
				'max'    => null,
			);
		}
		sort( $values );
		$n   = count( $values );
		$mid = intdiv( $n, 2 );
		return array(
			'sample' => $n,
			'min'    => $values[0],
			'median' => 0 === $n % 2 ? ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2.0 : (float) $values[ $mid ],
			'max'    => $values[ $n - 1 ],
		);
	}

	/**
	 * Lawyers per decade of practice.
	 *
	 * @param array<int, int> $years Years in practice.
	 * @return array<int, array{label: string, count: int}>
	 */
	private static function buckets( array $years ): array {
		$out = array();
		foreach ( array( array( 0, 9, 'Under 10 years' ), array( 10, 19, '10 to 19 years' ), array( 20, 29, '20 to 29 years' ), array( 30, 39, '30 to 39 years' ), array( 40, PHP_INT_MAX, '40 years or more' ) ) as [$lo, $hi, $label] ) {
			$out[] = array(
				'label' => $label,
				'count' => count( array_filter( $years, static fn( int $y ): bool => $y >= $lo && $y <= $hi ) ),
			);
		}
		return $out;
	}

	/**
	 * Counted names, most common first, ties by name.
	 *
	 * @param array<string, int> $counts Counts.
	 * @param int                $limit  Maximum rows.
	 * @return array<int, array{name: string, count: int}>
	 */
	private static function top( array $counts, int $limit ): array {
		$rows = array();
		foreach ( $counts as $name => $count ) {
			$rows[] = array(
				'name'  => (string) $name,
				'count' => $count,
			);
		}
		usort( $rows, array( self::class, 'by_count' ) );
		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Sort by count descending, then name.
	 *
	 * @param array<string, mixed> $a Row.
	 * @param array<string, mixed> $b Row.
	 */
	private static function by_count( array $a, array $b ): int {
		return array( $b['count'], $a['name'] ) <=> array( $a['count'], $b['name'] );
	}
}
