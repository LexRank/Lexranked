<?php
/**
 * Research job post type.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\PostTypes;

use LexRanked\Core\Domain\ResearchJobStatus;
use LexRanked\Core\Schema\Field;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Research job record. Execution arrives in Phase 5; the record, status
 * lifecycle and resumability fields (cursor, processed_count) exist now.
 * Administrators only (custom capability type).
 */
final class ResearchJob extends PostType {

	public const SLUG = 'lr_research_job';

	public const JOB_TYPES = array( 'candidate_discovery', 'source_refresh', 'verification', 'ranking_recalculation' );

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
		return 'Research Job';
	}

	/**
	 * {@inheritDoc}
	 */
	public function plural(): string {
		return 'Research Jobs';
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
	public function capability_type(): string|array {
		return array( 'lr_research_job', 'lr_research_jobs' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return 'dashicons-search';
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields(): array {
		return array(
			new Field( 'job_type', Field::TYPE_ENUM, 'Job type', required: true, options: self::JOB_TYPES ),
			new Field( 'status', Field::TYPE_ENUM, 'Status', required: true, options: ResearchJobStatus::values() ),
			new Field( 'started_at', Field::TYPE_DATETIME, 'Started at', read_only: true, is_public: false ),
			new Field( 'completed_at', Field::TYPE_DATETIME, 'Completed at', read_only: true, is_public: false ),
			new Field( 'retry_count', Field::TYPE_INT, 'Retry count', read_only: true, is_public: false, min: 0 ),
			new Field( 'processed_count', Field::TYPE_INT, 'Processed records', read_only: true, is_public: false, min: 0 ),
			new Field( 'cursor', Field::TYPE_STRING, 'Resume cursor', read_only: true, is_public: false, max: 255 ),
			new Field( 'error_message', Field::TYPE_TEXT, 'Last error', read_only: true, is_public: false ),
		);
	}
}
