<?php
/**
 * Lawyer post type.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\PostTypes;

use LexRanked\Core\Domain\CommercialStatus;
use LexRanked\Core\Domain\UsStates;
use LexRanked\Core\Schema\Field;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Individual lawyer. Post title = full name; post content = biography.
 *
 * City/state come from the assigned Location term; verification status and
 * last_verified_at are derived from Verification Records at read time.
 */
final class Lawyer extends PostType {

	public const SLUG = 'lr_lawyer';

	public const BAR_STATUSES = array( 'active', 'inactive', 'suspended', 'disbarred', 'retired' );

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function singular(): string {
		return 'Lawyer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function plural(): string {
		return 'Lawyers';
	}

	/**
	 * {@inheritDoc}
	 */
	public function public_base(): ?string {
		return 'lawyers';
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_editor(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function taxonomies(): array {
		return array( Location::SLUG, PracticeArea::SLUG );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return 'dashicons-businessperson';
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields(): array {
		return array(
			new Field( 'first_name', Field::TYPE_STRING, 'First name', required: true, max: 100 ),
			new Field( 'last_name', Field::TYPE_STRING, 'Last name', required: true, max: 100 ),
			new Field( 'title', Field::TYPE_STRING, 'Professional title', max: 150, help: 'e.g. Partner, Founding Attorney' ),
			new Field( 'firm_id', Field::TYPE_POST_REF, 'Law firm', ref_types: array( LawFirm::SLUG ) ),
			new Field( 'zip_code', Field::TYPE_STRING, 'ZIP code', max: 10 ),
			new Field( 'country', Field::TYPE_STRING, 'Country', max: 2, help: 'ISO 3166-1 alpha-2, e.g. US' ),
			new Field( 'website', Field::TYPE_URL, 'Website' ),
			new Field( 'phone', Field::TYPE_PHONE, 'Phone' ),
			new Field( 'email', Field::TYPE_EMAIL, 'Email', is_public: false, help: 'Private: never returned by the public API.' ),
			new Field( 'years_experience', Field::TYPE_INT, 'Years of experience', min: 0, max: 80 ),
			new Field( 'rating', Field::TYPE_FLOAT, 'Rating (0–5)', min: 0, max: 5 ),
			new Field( 'review_count', Field::TYPE_INT, 'Review count', min: 0, max: 1000000 ),
			new Field( 'bar_state', Field::TYPE_ENUM, 'Bar state', options: UsStates::codes() ),
			new Field( 'bar_number', Field::TYPE_STRING, 'Bar number', max: 50 ),
			new Field( 'bar_status', Field::TYPE_ENUM, 'Bar status', options: self::BAR_STATUSES ),
			new Field( 'education', Field::TYPE_OBJECT_LIST, 'Education', options: array( 'institution', 'degree', 'year' ), help: 'One per line: Institution | Degree | Year' ),
			new Field( 'awards', Field::TYPE_OBJECT_LIST, 'Awards', options: array( 'name', 'issuer', 'year' ), help: 'One per line: Award | Issuer | Year' ),
			new Field( 'languages', Field::TYPE_STRING_LIST, 'Languages', help: 'One per line' ),
			new Field( 'commercial_status', Field::TYPE_ENUM, 'Commercial status', options: CommercialStatus::values(), help: 'Commercial only. Never affects the organic score.' ),
			new Field( 'score', Field::TYPE_FLOAT, 'LexRank score', read_only: true, min: 0, max: 100 ),
			new Field( 'score_version', Field::TYPE_STRING, 'Score version', read_only: true, max: 20 ),
			new Field( 'score_calculated_at', Field::TYPE_DATETIME, 'Score calculated at', read_only: true ),
			self::demo_field(),
		);
	}
}
