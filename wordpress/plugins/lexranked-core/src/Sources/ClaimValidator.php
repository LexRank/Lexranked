<?php
/**
 * Evidence claim validation.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Sources;

use LexRanked\Core\Domain\VerificationStatus;
use LexRanked\Core\Schema\ValidationException;

/**
 * Validates an evidence claim before storage. Every claim must point at a
 * source (URL and/or registered source) — facts without a source are rejected.
 */
final class ClaimValidator {

	public const ENTITY_TYPES = array( 'lawyer', 'law_firm' );

	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, string>> $allowed_fields Entity type => allowed field names.
	 * @param SourceTiers                       $tiers          Configured source types.
	 */
	public function __construct(
		private readonly array $allowed_fields,
		private readonly SourceTiers $tiers
	) {
	}

	/**
	 * Validate and normalize a claim.
	 *
	 * @param array<string, mixed> $claim Raw claim.
	 * @return array<string, mixed> Normalized claim ready for insertion.
	 * @throws ValidationException When invalid.
	 */
	public function validate( array $claim ): array {
		$entity_type = (string) ( $claim['entity_type'] ?? '' );
		if ( ! in_array( $entity_type, self::ENTITY_TYPES, true ) ) {
			throw new ValidationException( 'entity_type', 'must be lawyer or law_firm' );
		}
		$entity_id = filter_var( $claim['entity_id'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $entity_id ) {
			throw new ValidationException( 'entity_id', 'must be a positive integer' );
		}
		$field_name = (string) ( $claim['field_name'] ?? '' );
		if ( ! in_array( $field_name, $this->allowed_fields[ $entity_type ] ?? array(), true ) ) {
			throw new ValidationException( 'field_name', 'is not a traceable field of ' . $entity_type );
		}
		if ( ! array_key_exists( 'value', $claim ) || null === $claim['value'] || '' === $claim['value'] ) {
			throw new ValidationException( 'value', 'is required' );
		}

		$source_id  = isset( $claim['source_id'] ) ? filter_var( $claim['source_id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : null;
		$source_url = trim( (string) ( $claim['source_url'] ?? '' ) );
		if ( false === $source_id ) {
			throw new ValidationException( 'source_id', 'must be a positive integer' );
		}
		if ( '' !== $source_url && ( ! preg_match( '#^https?://#i', $source_url ) || false === filter_var( $source_url, FILTER_VALIDATE_URL ) ) ) {
			throw new ValidationException( 'source_url', 'must be a full http(s) URL' );
		}
		if ( null === $source_id && '' === $source_url ) {
			throw new ValidationException( 'source', 'is required: a claim without a source cannot be stored' );
		}

		$source_type = (string) ( $claim['source_type'] ?? '' );
		if ( ! in_array( $source_type, $this->tiers->types(), true ) ) {
			throw new ValidationException( 'source_type', 'must be a configured source type' );
		}

		$confidence = $claim['confidence'] ?? null;
		if ( ! is_numeric( $confidence ) || (float) $confidence < 0 || (float) $confidence > 1 ) {
			throw new ValidationException( 'confidence', 'must be between 0 and 1' );
		}

		$status = (string) ( $claim['verification_status'] ?? VerificationStatus::Pending->value );
		if ( ! in_array( $status, VerificationStatus::values(), true ) ) {
			throw new ValidationException( 'verification_status', 'is not a valid status' );
		}

		try {
			$retrieved = new \DateTimeImmutable( (string) ( $claim['retrieved_at'] ?? '' ), new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			throw new ValidationException( 'retrieved_at', 'must be a valid date/time' );
		}
		if ( empty( $claim['retrieved_at'] ) ) {
			throw new ValidationException( 'retrieved_at', 'is required' );
		}

		return array(
			'entity_id'           => (int) $entity_id,
			'entity_type'         => $entity_type,
			'field_name'          => $field_name,
			'value'               => (string) json_encode( $claim['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-independent by design.
			'source_id'           => $source_id,
			'source_url'          => $source_url,
			'source_type'         => $source_type,
			'retrieved_at'        => $retrieved->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'confidence'          => round( (float) $confidence, 3 ),
			'verification_status' => $status,
		);
	}
}
