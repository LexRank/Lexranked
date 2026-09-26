<?php
/**
 * Placement rules.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Commercial;

use LexRanked\Core\Schema\FieldSanitizer;
use LexRanked\Core\Schema\ValidationException;

/**
 * Pure rules for commercial placements: what an editor may enter, when a
 * placement is live, which profiles may be advertised, and which placements
 * a page shows. Nothing here reads or writes scores or positions.
 */
final class PlacementPolicy {

	public const MAX_DAYS           = 366;
	public const MAX_MESSAGE        = 600;
	public const STATUSES           = array( 'active', 'paused', 'cancelled' );
	public const BLOCKED_BAR_STATUS = array( 'inactive', 'suspended', 'disbarred', 'retired' );

	/**
	 * Validate admin input.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array{entity_id: int, entity_type: string, product: string, ranking_id: int, location_term_id: int, practice_area_term_id: int, starts_at: string, ends_at: string, status: string, premium_message: string, cta_url: string, order_ref: string, notes: string}
	 * @throws ValidationException When invalid.
	 */
	public static function validate( array $input ): array {
		$product = Product::tryFrom( (string) ( $input['product'] ?? '' ) );
		if ( null === $product ) {
			throw new ValidationException( 'product', 'must be premium, featured or sponsored' );
		}
		$type = (string) ( $input['entity_type'] ?? '' );
		if ( ! in_array( $type, array( 'lawyer', 'law_firm' ), true ) ) {
			throw new ValidationException( 'entity_type', 'must be lawyer or law_firm' );
		}
		$entity = self::id( $input['entity_id'] ?? 0 );
		if ( 0 === $entity ) {
			throw new ValidationException( 'entity_id', 'is required' );
		}
		$starts = self::date( 'starts_at', $input['starts_at'] ?? '' );
		$ends   = self::date( 'ends_at', $input['ends_at'] ?? '' );
		$days   = ( strtotime( $ends ) - strtotime( $starts ) ) / 86400;
		if ( $days <= 0 ) {
			throw new ValidationException( 'ends_at', 'must be after the start date' );
		}
		if ( $days > self::MAX_DAYS ) {
			throw new ValidationException( 'ends_at', 'must be within ' . self::MAX_DAYS . ' days of the start date' );
		}
		$status = (string) ( $input['status'] ?? 'active' );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new ValidationException( 'status', 'must be active, paused or cancelled' );
		}

		$ranking  = self::id( $input['ranking_id'] ?? 0 );
		$location = self::id( $input['location_term_id'] ?? 0 );
		$practice = self::id( $input['practice_area_term_id'] ?? 0 );
		if ( Product::Sponsored === $product && 0 === $ranking ) {
			throw new ValidationException( 'ranking_id', 'is required for a sponsored listing' );
		}
		if ( Product::Featured === $product && ( 0 === $location ) === ( 0 === $practice ) ) {
			throw new ValidationException( 'location_term_id', 'or a practice area (exactly one: the page it appears on) is required for a featured profile' );
		}

		$message = FieldSanitizer::clean_string( (string) ( $input['premium_message'] ?? '' ), true );
		$cta     = trim( (string) ( $input['cta_url'] ?? '' ) );
		if ( Product::Premium !== $product && ( '' !== $message || '' !== $cta ) ) {
			throw new ValidationException( 'premium_message', 'is only available on premium profiles' );
		}
		if ( mb_strlen( $message ) > self::MAX_MESSAGE ) {
			throw new ValidationException( 'premium_message', 'must be at most ' . self::MAX_MESSAGE . ' characters' );
		}
		if ( preg_match( '#https?://|www\.#i', $message ) ) {
			throw new ValidationException( 'premium_message', 'must not contain links; use the call-to-action URL' );
		}
		if ( '' !== $cta && ( 'https' !== strtolower( (string) parse_url( $cta, PHP_URL_SCHEME ) ) || false === filter_var( $cta, FILTER_VALIDATE_URL ) || strlen( $cta ) > 2048 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress-independent by design.
			throw new ValidationException( 'cta_url', 'must be a full https URL' );
		}

		return array(
			'entity_id'             => $entity,
			'entity_type'           => $type,
			'product'               => $product->value,
			'ranking_id'            => Product::Sponsored === $product ? $ranking : 0,
			'location_term_id'      => Product::Featured === $product ? $location : 0,
			'practice_area_term_id' => Product::Featured === $product ? $practice : 0,
			'starts_at'             => $starts,
			'ends_at'               => $ends,
			'status'                => $status,
			'premium_message'       => $message,
			'cta_url'               => $cta,
			'order_ref'             => mb_substr( FieldSanitizer::clean_string( (string) ( $input['order_ref'] ?? '' ) ), 0, 100 ),
			'notes'                 => mb_substr( FieldSanitizer::clean_string( (string) ( $input['notes'] ?? '' ), true ), 0, 1000 ),
		);
	}

	/**
	 * Whether a placement is running at $now.
	 *
	 * @param array<string, mixed> $row Placement row (UTC datetimes).
	 * @param \DateTimeImmutable   $now Now.
	 */
	public static function is_live( array $row, \DateTimeImmutable $now ): bool {
		if ( 'active' !== ( $row['status'] ?? '' ) ) {
			return false;
		}
		$t = $now->format( 'Y-m-d H:i:s' );
		return (string) $row['starts_at'] <= $t && $t < (string) $row['ends_at'];
	}

	/**
	 * Reasons a profile may not be advertised (empty = eligible).
	 *
	 * @param array{published: bool, claimed: bool, bar_status: string|null, in_scope: bool} $facts Facts.
	 * @return array<int, string>
	 */
	public static function ineligibility( array $facts ): array {
		$reasons = array();
		if ( ! $facts['published'] ) {
			$reasons[] = 'profile is not published';
		}
		if ( ! $facts['claimed'] ) {
			$reasons[] = 'profile has no approved claim (only the profile owner can buy placements)';
		}
		if ( in_array( (string) $facts['bar_status'], self::BLOCKED_BAR_STATUS, true ) ) {
			$reasons[] = 'bar status is ' . $facts['bar_status'];
		}
		if ( ! $facts['in_scope'] ) {
			$reasons[] = 'profile is not in the page\'s location and practice area';
		}
		return $reasons;
	}

	/**
	 * Whether an entity belongs on a page scope.
	 *
	 * @param array<int, int> $entity_locations Location term IDs of the entity, with their ancestors.
	 * @param array<int, int> $entity_practices Practice-area term IDs of the entity.
	 * @param int             $location_id      Page location (0 = any).
	 * @param int             $practice_id      Page practice area (0 = any).
	 */
	public static function in_scope( array $entity_locations, array $entity_practices, int $location_id, int $practice_id ): bool {
		return ( 0 === $location_id || in_array( $location_id, $entity_locations, true ) )
			&& ( 0 === $practice_id || in_array( $practice_id, $entity_practices, true ) );
	}

	/**
	 * Placements a page shows: live, one per profile, oldest booking first
	 * (deterministic; never by score or price), capped.
	 *
	 * @param array<int, array<string, mixed>> $rows  Candidate rows (already eligible).
	 * @param \DateTimeImmutable               $now   Now.
	 * @param int                              $limit Max placements.
	 * @return array<int, array<string, mixed>>
	 */
	public static function select( array $rows, \DateTimeImmutable $now, int $limit ): array {
		$live = array_values( array_filter( $rows, static fn( array $r ): bool => self::is_live( $r, $now ) ) );
		usort( $live, static fn( array $a, array $b ): int => array( (string) $a['starts_at'], (int) $a['placement_id'] ) <=> array( (string) $b['starts_at'], (int) $b['placement_id'] ) );
		$out  = array();
		$seen = array();
		foreach ( $live as $row ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( isset( $seen[ (int) $row['entity_id'] ] ) ) {
				continue;
			}
			$seen[ (int) $row['entity_id'] ] = true;
			$out[]                           = $row;
		}
		return $out;
	}

	/**
	 * Non-negative integer ID.
	 *
	 * @param mixed $value Value.
	 */
	private static function id( mixed $value ): int {
		$id = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		return false === $id ? 0 : (int) $id;
	}

	/**
	 * Date (Y-m-d) → UTC midnight datetime.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Value.
	 * @throws ValidationException When invalid.
	 */
	private static function date( string $key, mixed $value ): string {
		$value = trim( (string) $value );
		$date  = \DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $value, 0, 10 ), new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d' ) !== substr( $value, 0, 10 ) ) {
			throw new ValidationException( $key, 'must be a date (YYYY-MM-DD)' );
		}
		return $date->format( 'Y-m-d H:i:s' );
	}
}
