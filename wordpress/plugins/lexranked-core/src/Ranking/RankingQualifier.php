<?php
/**
 * Ranking context qualifier (Etap F).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

/**
 * The "best for" part of a contextual ranking: a case type, a client type or
 * a language on top of the location and practice area.
 *
 * An entity qualifies only through a stored fact: its case types list the
 * case type, its client types list the client type, or its languages list the
 * language. A missing or conflicting fact never qualifies, and nothing is
 * inferred from names or text. Qualification selects who is ranked; it never
 * changes a score. Pure.
 */
final class RankingQualifier {

	public const CASE_TYPE   = 'case_type';
	public const CLIENT_TYPE = 'client_type';
	public const LANGUAGE    = 'language';

	public const TYPES = array( self::CASE_TYPE, self::CLIENT_TYPE, self::LANGUAGE );

	/** Client types a profile can list (the `client_types` fact). */
	public const CLIENT_TYPES = array(
		'individuals' => 'Individuals',
		'businesses'  => 'Businesses',
		'families'    => 'Families',
		'seniors'     => 'Seniors',
		'veterans'    => 'Veterans',
		'immigrants'  => 'Immigrants',
		'employees'   => 'Employees',
	);

	/** Fact attribute that proves each qualifier type. */
	private const ATTRIBUTE = array(
		self::CASE_TYPE   => 'case_types',
		self::CLIENT_TYPE => 'client_types',
		self::LANGUAGE    => 'languages',
	);

	/** Fact statuses that can qualify (a source exists and does not conflict). */
	public const QUALIFYING_STATUSES = array( 'verified', 'unverified' );

	/**
	 * Constructor.
	 *
	 * @param string $type  One of TYPES.
	 * @param string $value Slug (case type, client type or language).
	 */
	private function __construct( public readonly string $type, public readonly string $value ) {
	}

	/**
	 * From ranking fields; null when the ranking has no (valid) context.
	 *
	 * @param mixed $type  Context type.
	 * @param mixed $value Context value.
	 */
	public static function from_fields( mixed $type, mixed $value ): ?self {
		$type  = is_string( $type ) ? $type : '';
		$value = self::slug( is_string( $value ) ? $value : '' );
		if ( ! in_array( $type, self::TYPES, true ) || '' === $value ) {
			return null;
		}
		if ( self::CLIENT_TYPE === $type && ! isset( self::CLIENT_TYPES[ $value ] ) ) {
			return null;
		}
		return new self( $type, $value );
	}

	/**
	 * From a ranking record's fields.
	 *
	 * @param array<string, mixed> $record Ranking record.
	 */
	public static function for_record( array $record ): ?self {
		return self::from_fields( $record['fields']['context_type'] ?? null, $record['fields']['context_value'] ?? null );
	}

	/**
	 * The ranking's practice area: never the case-type term itself, and a
	 * top-level area before a sub-area.
	 *
	 * @param array<int, array<string, mixed>> $terms     Practice-area terms of the ranking.
	 * @param self|null                        $qualifier Qualifier.
	 * @return array<string, mixed>|null
	 */
	public static function primary_practice( array $terms, ?self $qualifier ): ?array {
		$terms = array_values(
			array_filter(
				$terms,
				static fn( array $t ): bool => null === $qualifier || self::CASE_TYPE !== $qualifier->type || $t['slug'] !== $qualifier->value
			)
		);
		usort( $terms, static fn( array $a, array $b ): int => array( 0 === (int) ( $a['parent'] ?? 0 ) ? 0 : 1 ) <=> array( 0 === (int) ( $b['parent'] ?? 0 ) ? 0 : 1 ) );
		return $terms[0] ?? null;
	}

	/**
	 * Lowercase URL slug.
	 *
	 * @param string $text Text.
	 */
	public static function slug( string $text ): string {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( trim( $text ) ) ), '-' );
	}

	/**
	 * The fact attribute that proves this qualifier.
	 */
	public function attribute(): string {
		return self::ATTRIBUTE[ $this->type ];
	}

	/**
	 * The fact attribute that proves a qualifier type.
	 *
	 * @param string $type One of TYPES.
	 */
	public static function attribute_for( string $type ): string {
		return self::ATTRIBUTE[ $type ] ?? '';
	}

	/**
	 * URL segment after the practice area: car-accidents, spanish-speaking, for-businesses.
	 */
	public function segment(): string {
		return match ( $this->type ) {
			self::LANGUAGE    => $this->value . '-speaking',
			self::CLIENT_TYPE => 'for-' . $this->value,
			default           => $this->value,
		};
	}

	/**
	 * Human label: "Car Accidents", "Spanish-speaking", "For businesses".
	 *
	 * @param string|null $case_type_name Practice-area term name for a case type.
	 */
	public function label( ?string $case_type_name = null ): string {
		return match ( $this->type ) {
			self::LANGUAGE    => ucfirst( $this->value ) . '-speaking',
			self::CLIENT_TYPE => 'For ' . strtolower( self::CLIENT_TYPES[ $this->value ] ),
			default           => $case_type_name ?? ucwords( str_replace( '-', ' ', $this->value ) ),
		};
	}

	/**
	 * Whether an entity's facts qualify it, and on what evidence.
	 *
	 * @param array<string, array<string, mixed>> $facts Fact rows keyed by attribute (FactService::for_entity).
	 * @return array<string, mixed>|null Evidence, or null when the entity does not qualify.
	 */
	public function qualify( array $facts ): ?array {
		$fact = $facts[ $this->attribute() ] ?? null;
		if ( null === $fact || ! in_array( (string) $fact['status'], self::QUALIFYING_STATUSES, true ) ) {
			return null;
		}
		foreach ( (array) $fact['value'] as $item ) {
			if ( is_scalar( $item ) && self::slug( (string) $item ) === $this->value ) {
				return array(
					'attribute'  => $this->attribute(),
					'value'      => (string) $item,
					'status'     => (string) $fact['status'],
					'sourceId'   => isset( $fact['source_id'] ) ? (int) $fact['source_id'] : null,
					'claimId'    => isset( $fact['claim_id'] ) ? (int) $fact['claim_id'] : null,
					'observedAt' => isset( $fact['observed_at'] ) ? self::iso( (string) $fact['observed_at'] ) : null,
					'verifiedAt' => isset( $fact['verified_at'] ) ? self::iso( (string) $fact['verified_at'] ) : null,
				);
			}
		}
		return null;
	}

	/**
	 * Serialize.
	 *
	 * @return array{type: string, value: string, segment: string}
	 */
	public function to_array(): array {
		return array(
			'type'    => $this->type,
			'value'   => $this->value,
			'segment' => $this->segment(),
		);
	}

	/**
	 * MySQL datetime or ISO → ISO 8601 UTC.
	 *
	 * @param string $value Datetime.
	 */
	private static function iso( string $value ): string {
		return str_contains( $value, 'T' ) ? $value : str_replace( ' ', 'T', substr( $value, 0, 19 ) ) . 'Z';
	}
}
