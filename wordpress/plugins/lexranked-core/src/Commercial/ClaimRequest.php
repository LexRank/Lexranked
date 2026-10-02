<?php
/**
 * Claim submission validation.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Commercial;

use LexRanked\Core\Domain\UsStates;
use LexRanked\Core\Schema\Field;
use LexRanked\Core\Schema\FieldSanitizer;
use LexRanked\Core\Schema\ValidationException;

/**
 * Validates what a claimant submits through the public form. Pure: no
 * WordPress calls. Everything is plain text; nothing here is ever published.
 */
final class ClaimRequest {

	public const ROLES = array( 'self', 'firm_representative' );

	/**
	 * Validate and normalise.
	 *
	 * @param array<string, mixed> $input Request body (camelCase keys).
	 * @return array{entity_type: string, entity_id: int, claimant_name: string, claimant_email: string, claimant_phone: string, claimant_role: string, bar_state: string, bar_number: string, message: string}
	 * @throws ValidationException When a value is missing or invalid.
	 */
	public static function validate( array $input ): array {
		$type = (string) ( $input['entityType'] ?? '' );
		if ( ! in_array( $type, array( 'lawyer', 'law_firm' ), true ) ) {
			throw new ValidationException( 'entityType', 'must be lawyer or law_firm' );
		}
		$id = filter_var( $input['entityId'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $id ) {
			throw new ValidationException( 'entityId', 'must be a profile ID' );
		}
		if ( true !== ( $input['consent'] ?? null ) ) {
			throw new ValidationException( 'consent', 'is required' );
		}

		$name  = self::field( new Field( 'name', Field::TYPE_STRING, 'Name', max: 120 ), $input['name'] ?? '', true );
		$email = self::field( new Field( 'email', Field::TYPE_EMAIL, 'Email' ), $input['email'] ?? '', true );
		if ( strlen( $email ) > 254 ) {
			throw new ValidationException( 'email', 'is too long' );
		}
		$phone   = self::field( new Field( 'phone', Field::TYPE_PHONE, 'Phone' ), $input['phone'] ?? '', false );
		$message = self::field( new Field( 'message', Field::TYPE_TEXT, 'Message' ), $input['message'] ?? '', false );
		if ( mb_strlen( $message ) > 1000 ) {
			throw new ValidationException( 'message', 'must be at most 1000 characters' );
		}
		if ( preg_match( '#https?://|www\.#i', $message ) ) {
			throw new ValidationException( 'message', 'must not contain links' );
		}

		$role = (string) ( $input['role'] ?? '' );
		if ( ! in_array( $role, self::ROLES, true ) || ( 'law_firm' === $type && 'self' === $role ) ) {
			throw new ValidationException( 'role', 'is not valid for this profile' );
		}

		$bar_state  = strtoupper( trim( (string) ( $input['barState'] ?? '' ) ) );
		$bar_number = self::field( new Field( 'bar_number', Field::TYPE_STRING, 'Bar number', max: 40 ), $input['barNumber'] ?? '', false, 'barNumber' );
		if ( '' !== $bar_state && ! in_array( $bar_state, UsStates::codes(), true ) ) {
			throw new ValidationException( 'barState', 'must be a US state code' );
		}
		if ( 'self' === $role && ( '' === $bar_state || '' === $bar_number ) ) {
			throw new ValidationException( 'barNumber', 'and bar state are required to claim your own profile' );
		}

		return array(
			'entity_type'    => $type,
			'entity_id'      => (int) $id,
			'claimant_name'  => $name,
			'claimant_email' => $email,
			'claimant_phone' => $phone,
			'claimant_role'  => $role,
			'bar_state'      => $bar_state,
			'bar_number'     => $bar_number,
			'message'        => $message,
		);
	}

	/**
	 * Sanitize one value with the shared field rules.
	 *
	 * @param Field       $field    Field.
	 * @param mixed       $raw      Raw value.
	 * @param bool        $required Required.
	 * @param string|null $api_key  Request key when it differs from the field key.
	 * @throws ValidationException When invalid or missing.
	 */
	private static function field( Field $field, mixed $raw, bool $required, ?string $api_key = null ): string {
		$key = $api_key ?? $field->key;
		if ( ! is_scalar( $raw ) ) {
			throw new ValidationException( $key, 'must be text' );
		}
		try {
			$value = FieldSanitizer::sanitize( $field, (string) $raw );
		} catch ( ValidationException $e ) {
			throw new ValidationException( $key, $e->reason );
		}
		if ( null === $value || '' === $value ) {
			if ( $required ) {
				throw new ValidationException( $key, 'is required' );
			}
			return '';
		}
		return (string) $value;
	}
}
