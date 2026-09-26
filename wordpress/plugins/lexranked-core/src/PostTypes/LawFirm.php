<?php
/**
 * Law firm post type.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\PostTypes;

use LexRanked\Core\Domain\CommercialStatus;
use LexRanked\Core\Schema\Field;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Law firm. Post title = firm name; post content = description.
 * lawyer_ids are derived from lawyers whose firm_id points here.
 */
final class LawFirm extends PostType {

	public const SLUG = 'lr_law_firm';

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
		return 'Law Firm';
	}

	/**
	 * {@inheritDoc}
	 */
	public function plural(): string {
		return 'Law Firms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function public_base(): ?string {
		return 'law-firms';
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
		return 'dashicons-building';
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields(): array {
		return array(
			new Field( 'website', Field::TYPE_URL, 'Website' ),
			new Field( 'phone', Field::TYPE_PHONE, 'Phone' ),
			new Field( 'email', Field::TYPE_EMAIL, 'Public business email' ),
			new Field( 'address', Field::TYPE_STRING, 'Street address', max: 200 ),
			new Field( 'zip_code', Field::TYPE_STRING, 'ZIP code', max: 10 ),
			new Field( 'country', Field::TYPE_STRING, 'Country', max: 2, help: 'ISO 3166-1 alpha-2, e.g. US' ),
			new Field( 'rating', Field::TYPE_FLOAT, 'Rating (0–5)', min: 0, max: 5 ),
			new Field( 'review_count', Field::TYPE_INT, 'Review count', min: 0, max: 1000000 ),
			new Field( 'summary', Field::TYPE_TEXT, 'Profile summary', help: 'Answer-first, 2–4 plain-text sentences shown at the top of the profile. Only facts backed by stored data and evidence.' ),
			new Field( 'commercial_status', Field::TYPE_ENUM, 'Commercial status', options: CommercialStatus::values(), help: 'Commercial only. Never affects the organic score.' ),
			new Field( 'score', Field::TYPE_FLOAT, 'LexRank score', read_only: true, min: 0, max: 100 ),
			new Field( 'score_version', Field::TYPE_STRING, 'Score version', read_only: true, max: 20 ),
			new Field( 'score_calculated_at', Field::TYPE_DATETIME, 'Score calculated at', read_only: true ),
			self::demo_field(),
		);
	}
}
