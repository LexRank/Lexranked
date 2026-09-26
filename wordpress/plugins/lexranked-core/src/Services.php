<?php
/**
 * Service container.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core;

use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\PostType;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\PostTypes\ResearchJob;
use LexRanked\Core\PostTypes\Source;
use LexRanked\Core\PostTypes\VerificationRecord;
use LexRanked\Core\Ranking\RankingRunner;
use LexRanked\Core\Ranking\ScoreVersions;
use LexRanked\Core\REST\EntityPresenter;
use LexRanked\Core\Research\CandidateRepository;
use LexRanked\Core\Research\EntityIndex;
use LexRanked\Core\Research\JobService;
use LexRanked\Core\Research\ResearchIngest;
use LexRanked\Core\Research\ResearchLog;
use LexRanked\Core\Repository\ClaimRepository;
use LexRanked\Core\Repository\EntityRepository;
use LexRanked\Core\Repository\SnapshotRepository;
use LexRanked\Core\Repository\VerificationRepository;
use LexRanked\Core\Settings\Settings;
use LexRanked\Core\Sources\ClaimValidator;

/**
 * Minimal, explicit dependency wiring (no framework).
 */
final class Services {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	public readonly Settings $settings;

	/**
	 * Lawyer.
	 *
	 * @var Lawyer
	 */
	public readonly Lawyer $lawyer;

	/**
	 * Law firm.
	 *
	 * @var LawFirm
	 */
	public readonly LawFirm $law_firm;

	/**
	 * Ranking.
	 *
	 * @var Ranking
	 */
	public readonly Ranking $ranking;

	/**
	 * Source.
	 *
	 * @var Source
	 */
	public readonly Source $source;

	/**
	 * Verification.
	 *
	 * @var VerificationRecord
	 */
	public readonly VerificationRecord $verification;

	/**
	 * Research job.
	 *
	 * @var ResearchJob
	 */
	public readonly ResearchJob $research_job;

	/**
	 * Entities.
	 *
	 * @var EntityRepository
	 */
	public readonly EntityRepository $entities;

	/**
	 * Verifications.
	 *
	 * @var VerificationRepository
	 */
	public readonly VerificationRepository $verifications;

	/**
	 * Claims.
	 *
	 * @var ClaimRepository
	 */
	public readonly ClaimRepository $claims;

	/**
	 * Presenter.
	 *
	 * @var EntityPresenter
	 */
	public readonly EntityPresenter $presenter;

	/**
	 * Ranking snapshots.
	 *
	 * @var SnapshotRepository
	 */
	public readonly SnapshotRepository $snapshots;

	/**
	 * Score version registry.
	 *
	 * @var ScoreVersions
	 */
	public readonly ScoreVersions $versions;

	/**
	 * Ranking runner.
	 *
	 * @var RankingRunner
	 */
	public readonly RankingRunner $runner;

	/**
	 * Research job log.
	 *
	 * @var ResearchLog
	 */
	public readonly ResearchLog $research_log;

	/**
	 * Research candidates.
	 *
	 * @var CandidateRepository
	 */
	public readonly CandidateRepository $candidates;

	/**
	 * Candidate-matching index.
	 *
	 * @var EntityIndex
	 */
	public readonly EntityIndex $entity_index;

	/**
	 * Research job queue.
	 *
	 * @var JobService
	 */
	public readonly JobService $jobs;

	/**
	 * Research intake.
	 *
	 * @var ResearchIngest
	 */
	public readonly ResearchIngest $ingest;

	/**
	 * Build the graph.
	 *
	 * @param Settings|null $settings Settings (injectable for tests).
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings      = $settings ?? new Settings();
		$this->lawyer        = new Lawyer();
		$this->law_firm      = new LawFirm();
		$this->ranking       = new Ranking();
		$this->source        = new Source( $this->settings->source_tiers() );
		$this->verification  = new VerificationRecord();
		$this->research_job  = new ResearchJob();
		$this->entities      = new EntityRepository();
		$this->verifications = new VerificationRepository( $this->entities, $this->verification );
		$this->claims        = new ClaimRepository(
			new ClaimValidator(
				array(
					'lawyer'   => self::traceable_fields( $this->lawyer ),
					'law_firm' => self::traceable_fields( $this->law_firm ),
				),
				$this->settings->source_tiers()
			)
		);
		$this->snapshots     = new SnapshotRepository();
		$this->versions      = new ScoreVersions();
		$this->presenter     = new EntityPresenter( $this );
		$this->runner        = new RankingRunner( $this, $this->snapshots, $this->versions );
		$this->research_log  = new ResearchLog();
		$this->candidates    = new CandidateRepository();
		$this->entity_index  = new EntityIndex( $this->entities, $this->lawyer, $this->law_firm );
		$this->jobs          = new JobService( $this, $this->research_log );
		$this->ingest        = new ResearchIngest( $this, $this->candidates, $this->entity_index, $this->research_log );
	}

	/**
	 * All post types.
	 *
	 * @return array<int, PostType>
	 */
	public function post_types(): array {
		return array( $this->lawyer, $this->law_firm, $this->ranking, $this->source, $this->verification, $this->research_job );
	}

	/**
	 * Post type by slug.
	 *
	 * @param string $slug Post type key.
	 */
	public function post_type( string $slug ): ?PostType {
		foreach ( $this->post_types() as $type ) {
			if ( $type->slug() === $slug ) {
				return $type;
			}
		}
		return null;
	}

	/**
	 * Fields that evidence claims may reference (public, editor-maintained facts,
	 * plus taxonomy-backed facts).
	 *
	 * @param PostType $type Post type.
	 * @return array<int, string>
	 */
	public static function traceable_fields( PostType $type ): array {
		$fields = array();
		foreach ( $type->fields() as $field ) {
			if ( $field->is_public && ! $field->read_only && ! in_array( $field->key, array( 'is_demo', 'commercial_status' ), true ) ) {
				$fields[] = $field->key;
			}
		}
		return array_merge( array( 'name', 'city', 'state', 'practice_areas' ), $fields );
	}
}
