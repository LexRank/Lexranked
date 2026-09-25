<?php
/**
 * Verification record post type.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\PostTypes;

use LexRanked\Core\Domain\VerificationStatus;
use LexRanked\Core\Domain\VerificationType;
use LexRanked\Core\Schema\Field;

/**
 * One verification check for a lawyer or law firm.
 */
final class VerificationRecord extends PostType {

	public const SLUG = 'lr_verification';

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
		return 'Verification Record';
	}

	/**
	 * {@inheritDoc}
	 */
	public function plural(): string {
		return 'Verification';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return 'dashicons-yes-alt';
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields(): array {
		return array(
			new Field( 'entity_id', Field::TYPE_POST_REF, 'Lawyer / law firm', required: true, ref_types: array( Lawyer::SLUG, LawFirm::SLUG ) ),
			new Field( 'verification_type', Field::TYPE_ENUM, 'Verification type', required: true, options: VerificationType::values() ),
			new Field( 'status', Field::TYPE_ENUM, 'Status', required: true, options: VerificationStatus::values() ),
			new Field( 'source_id', Field::TYPE_POST_REF, 'Source', ref_types: array( Source::SLUG ) ),
			new Field( 'source_url', Field::TYPE_URL, 'Evidence URL' ),
			new Field( 'verified_at', Field::TYPE_DATETIME, 'Verified at (UTC)' ),
			new Field( 'expires_at', Field::TYPE_DATETIME, 'Expires at (UTC)' ),
			new Field( 'verified_by', Field::TYPE_STRING, 'Verified by', is_public: false, max: 100 ),
			new Field( 'notes', Field::TYPE_TEXT, 'Notes', is_public: false ),
			self::demo_field(),
		);
	}
}
