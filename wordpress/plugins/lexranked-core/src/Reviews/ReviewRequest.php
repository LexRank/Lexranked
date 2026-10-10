<?php
/**
 * Validation of a public client review.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Reviews;

use LexRanked\Core\Schema\ValidationException;

/**
 * Validates the review form (the frontend validates first; this is the
 * authority). WordPress-independent so it can be unit-tested.
 */
final class ReviewRequest {

	public const MIN_BODY = 40;
	public const MAX_BODY = 2000;

	/**
	 * Validate input.
	 *
	 * @param array<string, mixed> $input Request body.
	 * @return array{entity_type: string, entity_id: int, rating: int, title: string, body: string, reviewer_name: string, reviewer_email: string, service_year: int}
	 * @throws ValidationException When a field is invalid.
	 */
	public static function validate( array $input ): array {
		$text = static fn( mixed $v ): string => trim( (string) preg_replace( '/\s+/u', ' ', is_scalar( $v ) ? (string) $v : '' ) );
		$body = trim( (string) preg_replace( '/[^\S\n]+/u', ' ', str_replace( "\r", '', is_scalar( $input['body'] ?? null ) ? (string) $input['body'] : '' ) ) );
		$body = (string) preg_replace( '/\n{3,}/', "\n\n", $body );

		$type = $text( $input['entityType'] ?? '' );
		if ( ! in_array( $type, array( 'lawyer', 'law_firm' ), true ) ) {
			throw new ValidationException( 'entityType', 'must be lawyer or law_firm' );
		}
		$id = filter_var( $input['entityId'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $id ) {
			throw new ValidationException( 'entityId', 'must be a profile ID' );
		}
		$rating = filter_var(
			$input['rating'] ?? null,
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => 1,
					'max_range' => 5,
				),
			)
		);
		if ( false === $rating ) {
			throw new ValidationException( 'rating', 'must be a whole number from 1 to 5' );
		}
		$title = $text( $input['title'] ?? '' );
		if ( mb_strlen( $title ) > 120 ) {
			throw new ValidationException( 'title', 'must be at most 120 characters' );
		}
		$length = mb_strlen( $body );
		if ( $length < self::MIN_BODY || $length > self::MAX_BODY ) {
			throw new ValidationException( 'body', sprintf( 'must be %d to %d characters', self::MIN_BODY, self::MAX_BODY ) );
		}
		if ( preg_match( '#https?://|www\.#i', $body . ' ' . $title ) ) {
			throw new ValidationException( 'body', 'must not contain links' );
		}
		$name = $text( $input['name'] ?? '' );
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 80 ) {
			throw new ValidationException( 'name', 'must be 2 to 80 characters' );
		}
		$email = strtolower( $text( $input['email'] ?? '' ) );
		if ( mb_strlen( $email ) > 254 || ! preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $email ) ) {
			throw new ValidationException( 'email', 'must be a valid email address' );
		}
		$year    = filter_var(
			$input['serviceYear'] ?? null,
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => 1950,
					'max_range' => 2100,
				),
			)
		);
		$current = (int) gmdate( 'Y' );
		if ( false === $year || $year > $current ) {
			throw new ValidationException( 'serviceYear', 'must be the year the lawyer worked for you' );
		}
		if ( true !== ( $input['client'] ?? null ) ) {
			throw new ValidationException( 'client', 'must confirm you were a client' );
		}
		return array(
			'entity_type'    => $type,
			'entity_id'      => (int) $id,
			'rating'         => (int) $rating,
			'title'          => $title,
			'body'           => $body,
			'reviewer_name'  => $name,
			'reviewer_email' => $email,
			'service_year'   => (int) $year,
		);
	}

	/**
	 * Public display name: first name and last initial ("Maria G.").
	 *
	 * @param string $name Full name as entered.
	 */
	public static function display_name( string $name ): string {
		$parts = preg_split( '/\s+/u', trim( $name ) );
		$parts = is_array( $parts ) ? array_values( array_filter( $parts, static fn( string $p ): bool => '' !== $p ) ) : array();
		if ( array() === $parts ) {
			return 'Client';
		}
		$first = mb_convert_case( mb_strtolower( $parts[0] ), MB_CASE_TITLE );
		if ( count( $parts ) < 2 ) {
			return $first;
		}
		return $first . ' ' . mb_strtoupper( mb_substr( (string) end( $parts ), 0, 1 ) ) . '.';
	}

	/**
	 * Rating summary of approved reviews: average to one decimal and count.
	 *
	 * @param array<int, int> $ratings Ratings 1-5.
	 * @return array{count: int, average: float|null}
	 */
	public static function aggregate( array $ratings ): array {
		$ratings = array_values( array_filter( array_map( 'intval', $ratings ), static fn( int $r ): bool => $r >= 1 && $r <= 5 ) );
		if ( array() === $ratings ) {
			return array(
				'count'   => 0,
				'average' => null,
			);
		}
		return array(
			'count'   => count( $ratings ),
			'average' => round( array_sum( $ratings ) / count( $ratings ), 1 ),
		);
	}
}
