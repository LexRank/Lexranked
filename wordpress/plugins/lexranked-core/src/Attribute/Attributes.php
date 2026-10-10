<?php
/**
 * Attribute registry.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Attribute;

use LexRanked\Core\Ranking\ScoreVersion;

/**
 * The data dictionary: every fact LexRanked can hold about an entity, and
 * every metric it derives. Pure. A unit test keeps it in sync with the
 * traceable fields of each entity schema.
 */
final class Attributes {

	private const L  = array( 'lawyer' );
	private const F  = array( 'law_firm' );
	private const LF = array( 'lawyer', 'law_firm' );

	/**
	 * Fact attributes, keyed by key.
	 *
	 * @return array<string, Attribute>
	 */
	public static function facts(): array {
		$list = array(
			new Attribute( 'name', 'Name', 'string', self::LF, 'identity', description: 'Name as published by the source.' ),
			new Attribute( 'first_name', 'First name', 'string', self::L, 'identity' ),
			new Attribute( 'last_name', 'Last name', 'string', self::L, 'identity' ),
			new Attribute( 'title', 'Professional title', 'string', self::L, 'identity', description: 'Role at the firm, e.g. Partner.' ),
			new Attribute( 'firm_id', 'Law firm', 'reference', self::L, 'organization', description: 'The firm the lawyer practises at.' ),
			new Attribute( 'city', 'City', 'string', self::LF, 'location' ),
			new Attribute( 'state', 'State', 'code', self::LF, 'location', description: 'USPS state code.' ),
			new Attribute( 'address', 'Street address', 'string', self::F, 'location' ),
			new Attribute( 'zip_code', 'ZIP code', 'code', self::LF, 'location' ),
			new Attribute( 'country', 'Country', 'code', self::LF, 'location' ),
			new Attribute( 'website', 'Website', 'url', self::LF, 'contact', freshness: 'website' ),
			new Attribute( 'phone', 'Phone', 'phone', self::LF, 'contact' ),
			new Attribute( 'email', 'Email', 'email', self::F, 'contact' ),
			new Attribute( 'practice_areas', 'Practice areas', 'list', self::LF, 'practice', description: 'Areas from the LexRanked taxonomy.' ),
			new Attribute( 'bar_state', 'Bar admission state', 'code', self::L, 'credentials', freshness: 'bar_status' ),
			new Attribute( 'bar_number', 'Bar number', 'code', self::L, 'credentials', freshness: 'bar_status', description: 'Official identifier issued by the state bar.' ),
			new Attribute( 'bar_status', 'Bar status', 'enum', self::L, 'credentials', freshness: 'bar_status', description: 'active, inactive, suspended, disbarred or retired, per the regulator.' ),
			new Attribute( 'education', 'Education', 'object_list', self::L, 'credentials' ),
			new Attribute( 'awards', 'Awards and recognition', 'object_list', self::L, 'credentials' ),
			new Attribute( 'years_experience', 'Years of experience', 'integer', self::L, 'experience', unit: 'years' ),
			new Attribute( 'languages', 'Languages', 'list', self::L, 'language' ),
			new Attribute( 'case_types', 'Case types handled', 'list', self::LF, 'practice', description: 'Sub-areas of a practice area (e.g. car-accidents under personal injury), as stated by a source. Selects contextual rankings; never scored.' ),
			new Attribute( 'client_types', 'Client types served', 'list', self::LF, 'practice', description: 'Who the lawyer or firm works for (individuals, businesses, …), as stated by a source.' ),
			new Attribute( 'rating', 'Client rating', 'number', self::LF, 'reviews', freshness: 'review_data', unit: 'stars (0-5)', description: 'Average rating on the cited review platform.' ),
			new Attribute( 'review_count', 'Review count', 'integer', self::LF, 'reviews', freshness: 'review_data', unit: 'reviews' ),
		);
		$out  = array();
		foreach ( $list as $attribute ) {
			$out[ $attribute->key ] = $attribute;
		}
		return $out;
	}

	/**
	 * Derived metrics: the score components (calculated, never sourced).
	 *
	 * @return array<string, Attribute>
	 */
	public static function derived(): array {
		$out = array(
			'lexrank_score' => new Attribute( 'lexrank_score', 'LexRank score', 'number', self::LF, 'score', Attribute::LAYER_DERIVED, unit: 'points (0-100)', description: 'Sum of the score components for the active methodology version.' ),
		);
		foreach ( ScoreVersion::COMPONENTS as $key => $label ) {
			$out[ $key ] = new Attribute( $key, $label, 'number', self::LF, 'score', Attribute::LAYER_DERIVED, unit: 'points', description: 'Score component; its maximum is the weight of the methodology version.' );
		}
		return $out;
	}

	/**
	 * One fact attribute.
	 *
	 * @param string $key Key.
	 */
	public static function fact( string $key ): ?Attribute {
		return self::facts()[ $key ] ?? null;
	}

	/**
	 * Everything, for GET /attributes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function dictionary(): array {
		return array_values( array_map( static fn( Attribute $a ): array => $a->to_array(), self::facts() + self::derived() ) );
	}
}
