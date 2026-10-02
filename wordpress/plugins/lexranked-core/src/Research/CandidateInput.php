<?php
/**
 * Candidate validation.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

use LexRanked\Core\Domain\UsStates;
use LexRanked\Core\Schema\ValidationException;
use LexRanked\Core\Sources\SourceTiers;

/**
 * Validates a candidate submitted by a worker. A candidate is only a lead
 * ("this lawyer/firm appears in that source"), never a fact: it must name
 * its source, and nothing from it is published without an editor.
 * Pure logic (no WordPress calls).
 */
final class CandidateInput {

	public const ENTITY_TYPES = array( 'lawyer', 'law_firm' );

	public const MAX_PAYLOAD_BYTES = 8000;

	/**
	 * Validate and normalize.
	 *
	 * @param array<string, mixed> $input Raw candidate.
	 * @param SourceTiers          $tiers Configured source types.
	 * @return array<string, mixed> Row ready for storage (without job/status columns).
	 * @throws ValidationException When invalid.
	 */
	public static function validate( array $input, SourceTiers $tiers ): array {
		$type = (string) ( $input['entity_type'] ?? '' );
		if ( ! in_array( $type, self::ENTITY_TYPES, true ) ) {
			throw new ValidationException( 'entity_type', 'must be lawyer or law_firm' );
		}

		$name = self::clean( $input['name'] ?? '', 255 );
		if ( '' === $name ) {
			throw new ValidationException( 'name', 'is required' );
		}
		$normalized = CandidateNormalizer::name( $name, $type );
		if ( '' === $normalized ) {
			throw new ValidationException( 'name', 'has no usable characters' );
		}
		if ( 'lawyer' === $type && ! str_contains( $normalized, ' ' ) ) {
			throw new ValidationException( 'name', 'must contain a first and last name' );
		}

		$state = self::state( $input['state'] ?? null );
		$city  = self::clean( $input['city'] ?? '', 100 );
		if ( '' !== $city && null === $state ) {
			throw new ValidationException( 'state', 'is required when city is given' );
		}

		$source_url = trim( (string) ( $input['source_url'] ?? '' ) );
		if ( ! self::is_http_url( $source_url ) ) {
			throw new ValidationException( 'source_url', 'must be a full http(s) URL' );
		}
		$source_type = (string) ( $input['source_type'] ?? '' );
		if ( ! in_array( $source_type, $tiers->types(), true ) ) {
			throw new ValidationException( 'source_type', 'must be a configured source type' );
		}

		$website = trim( (string) ( $input['website'] ?? '' ) );
		if ( '' !== $website && ! self::is_http_url( $website ) ) {
			throw new ValidationException( 'website', 'must be a full http(s) URL' );
		}

		$practice = self::clean( $input['practice_area'] ?? '', 100 );

		$payload = null;
		if ( isset( $input['payload'] ) && is_array( $input['payload'] ) && array() !== $input['payload'] ) {
			$payload = (string) json_encode( $input['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-independent by design.
			if ( strlen( $payload ) > self::MAX_PAYLOAD_BYTES ) {
				throw new ValidationException( 'payload', 'is too large' );
			}
		}

		// Optional resolution identifiers (phone, email, bar_state + bar_number, address + zip_code).
		// Unusable values are dropped: they are matching hints, never stored as facts.
		$identifiers = isset( $input['identifiers'] ) && is_array( $input['identifiers'] ) ? Identifiers::from( $input['identifiers'] ) : array();

		return array(
			'identifiers'     => $identifiers,
			'dedupe_key'      => CandidateNormalizer::dedupe_key( $type, $name, '' === $city ? null : strtolower( $city ), $state ),
			'entity_type'     => $type,
			'name'            => $name,
			'normalized_name' => $normalized,
			'city'            => '' === $city ? null : $city,
			'state'           => $state,
			'practice_area'   => '' === $practice ? null : $practice,
			'website'         => '' === $website ? null : $website,
			'source_url'      => $source_url,
			'source_type'     => $source_type,
			'payload'         => $payload,
		);
	}

	/**
	 * Two-letter state code from a code or full name; null when empty.
	 *
	 * @param mixed $raw Raw value.
	 * @throws ValidationException When not a US state.
	 */
	public static function state( mixed $raw ): ?string {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( null !== UsStates::name( $raw ) && 2 === strlen( $raw ) ) {
			return strtoupper( $raw );
		}
		foreach ( UsStates::codes() as $code ) {
			if ( 0 === strcasecmp( (string) UsStates::name( $code ), $raw ) ) {
				return $code;
			}
		}
		throw new ValidationException( 'state', 'must be a US state code or name' );
	}

	/**
	 * Whether a string is an absolute http(s) URL.
	 *
	 * @param string $url URL.
	 */
	public static function is_http_url( string $url ): bool {
		return '' !== $url && 1 === preg_match( '#^https?://#i', $url ) && false !== filter_var( $url, FILTER_VALIDATE_URL ) && strlen( $url ) <= 2048;
	}

	/**
	 * Single-line, trimmed, length-limited string.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max characters.
	 */
	private static function clean( mixed $value, int $max ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/<[^>]*>/', ' ', $value ) ) );
		return mb_substr( $value, 0, $max );
	}
}
