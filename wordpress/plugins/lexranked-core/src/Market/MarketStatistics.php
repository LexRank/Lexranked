<?php
/**
 * Market statistics (Etap I).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Market;

/**
 * Statistics for a market (a city, state or practice area, or a combination),
 * computed by the backend from stored data. Nothing here is written by AI: an
 * AI layer may later phrase "Miami has 127 tracked personal injury lawyers…",
 * but the numbers come from here.
 *
 * Rules:
 * - counts come from published profiles; "verified" means the profile passed
 *   its required verification checks;
 * - averages and medians use only facts backed by a source and not in
 *   conflict, and are withheld (null, with a note) below MIN_SAMPLE;
 * - each figure carries its sample size, and the result its calculation time.
 * Pure.
 */
final class MarketStatistics {

	public const VERSION    = 'mkt-1.0';
	public const MIN_SAMPLE = 3;

	/**
	 * Compute.
	 *
	 * @param array<int, array<string, mixed>> $entities Entities: {type: lawyer|law_firm, verified: bool, verified_at: ?string, is_demo: bool, facts: array<attribute, fact row>}.
	 * @param array<string, string>            $practice_names Practice-area slug => name.
	 * @param string                           $calculated_at  ISO timestamp.
	 * @return array<string, mixed>
	 */
	public static function compute( array $entities, array $practice_names, string $calculated_at ): array {
		$lawyers = array_values( array_filter( $entities, static fn( array $e ): bool => 'lawyer' === $e['type'] ) );
		$firms   = array_values( array_filter( $entities, static fn( array $e ): bool => 'law_firm' === $e['type'] ) );

		$ratings = array();
		$reviews = array();
		$years   = array();
		$areas   = array();
		// Ratings, reviews and experience describe lawyers; firm figures would double-count their lawyers' clients.
		foreach ( $lawyers as $e ) {
			$rating = self::value( $e, 'rating' );
			if ( is_numeric( $rating ) ) {
				$ratings[] = (float) $rating;
			}
			$count = self::value( $e, 'review_count' );
			if ( is_numeric( $count ) ) {
				$reviews[] = (int) $count;
			}
			$exp = self::value( $e, 'years_experience' );
			if ( is_numeric( $exp ) ) {
				$years[] = (int) $exp;
			}
			foreach ( array_unique( array_map( 'strval', (array) self::value( $e, 'practice_areas' ) ) ) as $slug ) {
				$areas[ $slug ] = ( $areas[ $slug ] ?? 0 ) + 1;
			}
		}
		arsort( $areas );
		$top = array();
		foreach ( array_slice( $areas, 0, 5, true ) as $slug => $n ) {
			$top[] = array(
				'slug'  => (string) $slug,
				'name'  => $practice_names[ $slug ] ?? ucwords( str_replace( '-', ' ', (string) $slug ) ),
				'count' => $n,
			);
		}
		$verified_dates = array_filter( array_map( static fn( array $e ): ?string => $e['verified'] ? ( $e['verified_at'] ?? null ) : null, $entities ) );
		sort( $verified_dates );

		$notes = array();
		if ( count( $ratings ) < self::MIN_SAMPLE ) {
			$notes[] = sprintf( 'Average rating withheld: %d sourced ratings (needs %d).', count( $ratings ), self::MIN_SAMPLE );
		}
		if ( count( $reviews ) < self::MIN_SAMPLE ) {
			$notes[] = sprintf( 'Median review count withheld: %d sourced review counts (needs %d).', count( $reviews ), self::MIN_SAMPLE );
		}

		return array(
			'version'            => self::VERSION,
			'lawyers'            => count( $lawyers ),
			'firms'              => count( $firms ),
			'verifiedLawyers'    => count( array_filter( $lawyers, static fn( array $e ): bool => (bool) $e['verified'] ) ),
			'verifiedFirms'      => count( array_filter( $firms, static fn( array $e ): bool => (bool) $e['verified'] ) ),
			'demoProfiles'       => count( array_filter( $entities, static fn( array $e ): bool => (bool) $e['is_demo'] ) ),
			'averageRating'      => self::stat( $ratings, static fn( array $v ): float => round( array_sum( $v ) / count( $v ), 2 ) ),
			'medianReviewCount'  => self::stat( $reviews, array( self::class, 'median' ) ),
			'medianExperience'   => self::stat( $years, array( self::class, 'median' ) ),
			'mostCommonPractice' => $top[0] ?? null,
			'practiceAreas'      => $top,
			'dataVerifiedAt'     => array() === $verified_dates ? null : (string) end( $verified_dates ),
			'calculatedAt'       => $calculated_at,
			'notes'              => $notes,
		);
	}

	/**
	 * A factual summary of the statistics (template, no AI).
	 *
	 * @param array<string, mixed> $stats compute() result.
	 * @param string               $scope "personal injury lawyers in Miami, Florida" style scope, or a place.
	 * @param string               $place Place name for "in …", or ''.
	 * @param bool                 $with_practice Mention the most common practice area (not when the scope is one).
	 */
	public static function summary( array $stats, string $scope, string $place = '', bool $with_practice = true ): string {
		if ( 0 === $stats['lawyers'] + $stats['firms'] ) {
			return '';
		}
		$parts   = array();
		$parts[] = sprintf(
			'LexRanked tracks %s%s%s.',
			self::count( $stats['lawyers'], 'lawyer' ),
			$stats['firms'] > 0 ? ' and ' . self::count( $stats['firms'], 'law firm' ) : '',
			'' === $scope ? '' : ' ' . $scope
		);
		if ( $stats['lawyers'] > 0 ) {
			$parts[] = sprintf( '%d of the lawyers %s verified professional data.', $stats['verifiedLawyers'], 1 === $stats['verifiedLawyers'] ? 'has' : 'have' );
		}
		if ( null !== $stats['averageRating'] ) {
			$parts[] = sprintf( 'The average client rating is %s out of 5 across %d lawyers with a sourced rating.', number_format( (float) $stats['averageRating']['value'], 1 ), $stats['averageRating']['sample'] );
		}
		if ( null !== $stats['medianReviewCount'] ) {
			$median  = (float) $stats['medianReviewCount']['value'];
			$parts[] = sprintf( 'The median lawyer profile has %s reviews.', floor( $median ) === $median ? number_format( $median ) : number_format( $median, 1 ) );
		}
		if ( $with_practice && null !== $stats['mostCommonPractice'] && '' !== $place ) {
			$parts[] = sprintf( 'The most common practice area in %s is %s (%s).', $place, $stats['mostCommonPractice']['name'], self::count( $stats['mostCommonPractice']['count'], 'lawyer' ) );
		}
		if ( null !== $stats['dataVerifiedAt'] ) {
			$time = strtotime( (string) $stats['dataVerifiedAt'] );
			if ( false !== $time ) {
				$parts[] = 'Data verified ' . gmdate( 'F j, Y', $time ) . '.';
			}
		}
		return implode( ' ', $parts );
	}

	/**
	 * A fact's value when it can be used (sourced, not in conflict).
	 *
	 * @param array<string, mixed> $entity    Entity.
	 * @param string               $attribute Attribute.
	 */
	private static function value( array $entity, string $attribute ): mixed {
		$fact = $entity['facts'][ $attribute ] ?? null;
		if ( null === $fact || ! in_array( (string) ( $fact['status'] ?? '' ), array( 'verified', 'unverified' ), true ) ) {
			return null;
		}
		return $fact['value'] ?? null;
	}

	/**
	 * A figure with its sample size, or null below MIN_SAMPLE.
	 *
	 * @param array<int, int|float> $values Values.
	 * @param callable              $aggregate Aggregate.
	 * @return array{value: float|int, sample: int}|null
	 */
	private static function stat( array $values, callable $aggregate ): ?array {
		if ( count( $values ) < self::MIN_SAMPLE ) {
			return null;
		}
		return array(
			'value'  => $aggregate( $values ),
			'sample' => count( $values ),
		);
	}

	/**
	 * Median.
	 *
	 * @param array<int, int|float> $values Values (non-empty).
	 * @return float|int
	 */
	public static function median( array $values ): float|int {
		sort( $values );
		$n   = count( $values );
		$mid = intdiv( $n, 2 );
		return 1 === $n % 2 ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
	}

	/**
	 * "3 lawyers".
	 *
	 * @param int    $n    Count.
	 * @param string $noun Singular noun.
	 */
	private static function count( int $n, string $noun ): string {
		return $n . ' ' . ( 1 === $n ? $noun : $noun . 's' );
	}
}
