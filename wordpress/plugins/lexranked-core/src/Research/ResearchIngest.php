<?php
/**
 * Research data intake.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

use LexRanked\Core\Domain\UsStates;
use LexRanked\Core\Domain\VerificationStatus;
use LexRanked\Core\PostTypes\ContentDraft;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\PostTypes\Source;
use LexRanked\Core\PostTypes\VerificationRecord;
use LexRanked\Core\Repository\ClaimRepository;
use LexRanked\Core\Schema\ValidationException;
use LexRanked\Core\Security\AuditLog;
use LexRanked\Core\Services;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Everything a worker submits goes through here. WordPress stays the source
 * of truth: input is validated, deduplicated and applied by fixed rules.
 *
 * Publication rules (never automatic):
 * - new lawyers/firms are created as drafts;
 * - resolved facts are written only into drafts / pending-review entities,
 *   and never over a value an editor typed into a non-research draft;
 * - evidence about a published entity lands in the editorial review queue
 *   (claims with review_status = pending_review) and is invisible publicly;
 * - research-created sources and verification records are "pending" posts
 *   an editor publishes.
 */
final class ResearchIngest {

	public const META_JOB       = '_lr_research_job';
	public const META_CANDIDATE = '_lr_research_candidate';
	public const META_REVIEW    = '_lr_research_review';
	public const META_VER_HASH  = '_lr_research_hash';

	/** Entity statuses research may write facts into. */
	public const WRITABLE_STATUSES = array( 'draft', 'pending' );

	public const MAX_BATCH = 100;

	/** Methods a worker may declare for a claim. */
	public const WORKER_METHODS = array( 'seed', 'structured_data', 'ai' );

	/** AI-extracted facts never outrank human-curated or structured data on confidence. */
	public const AI_MAX_CONFIDENCE = 0.6;

	public const META_DRAFT_KEY = '_lr_draft_key';

	/**
	 * Constructor.
	 *
	 * @param Services            $services   Services.
	 * @param CandidateRepository $candidates Candidates.
	 * @param EntityIndex         $index      Entity index.
	 * @param ResearchLog         $log        Job log.
	 */
	public function __construct(
		private readonly Services $services,
		private readonly CandidateRepository $candidates,
		private readonly EntityIndex $index,
		private readonly ResearchLog $log
	) {
	}

	// ─── Sources ────────────────────────────────────────────────────────────

	/**
	 * Find or create source records by URL.
	 *
	 * @param int                             $job_id Job ID.
	 * @param array<int, array<string,mixed>> $items {url, source_type, title?}.
	 * @return array<int, array<string, mixed>>
	 */
	public function sources( int $job_id, array $items ): array {
		$tiers = $this->services->settings->source_tiers();
		$out   = array();
		foreach ( array_values( $items ) as $i => $item ) {
			$url  = trim( (string) ( $item['url'] ?? '' ) );
			$type = (string) ( $item['source_type'] ?? '' );
			if ( ! CandidateInput::is_http_url( $url ) ) {
				$out[] = self::error( $i, 'url', 'must be a full http(s) URL' );
				continue;
			}
			if ( ! in_array( $type, $tiers->types(), true ) ) {
				$out[] = self::error( $i, 'source_type', 'must be a configured source type' );
				continue;
			}
			$existing = $this->find_source( $url );
			if ( null !== $existing ) {
				// Re-reading a known source refreshes when it was last checked.
				$this->services->entities->save_fields( $existing, $this->services->source, array( 'last_checked_at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) );
				$out[] = array(
					'index'    => $i,
					'sourceId' => $existing,
					'created'  => false,
					'tier'     => $tiers->tier_for( $type ),
				);
				continue;
			}
			$title = trim( wp_strip_all_tags( (string) ( $item['title'] ?? '' ) ) );
			$id    = wp_insert_post(
				array(
					'post_type'   => Source::SLUG,
					'post_status' => 'pending',
					'post_title'  => '' === $title ? (string) CandidateNormalizer::domain( $url ) : mb_substr( $title, 0, 200 ),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				$out[] = self::error( $i, 'source', 'could not be stored' );
				continue;
			}
			$this->services->entities->save_fields(
				(int) $id,
				$this->services->source,
				array(
					'url'             => $url,
					'source_type'     => $type,
					'retrieved_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'last_checked_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'status'          => 'active',
					'notes'           => 'Added by research job #' . $job_id . '.',
				)
			);
			update_post_meta( (int) $id, self::META_JOB, $job_id );
			$out[] = array(
				'index'    => $i,
				'sourceId' => (int) $id,
				'created'  => true,
				'tier'     => $tiers->tier_for( $type ),
			);
		}//end foreach
		return $out;
	}

	/**
	 * Source post ID by URL (any status but trash).
	 *
	 * @param string $url URL.
	 */
	private function find_source( string $url ): ?int {
		$ids = get_posts(
			array(
				'post_type'        => Source::SLUG,
				'post_status'      => array( 'publish', 'pending', 'draft', 'private' ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact match on one key.
				'meta_query'       => array(
					array(
						'key'   => $this->services->source->field( 'url' )?->meta_key(),
						'value' => $url,
					),
				),
			)
		);
		return array() === $ids ? null : (int) $ids[0];
	}

	// ─── Candidates ─────────────────────────────────────────────────────────

	/**
	 * Store candidates and resolve new ones with the deterministic matcher.
	 *
	 * @param int                             $job_id Job ID.
	 * @param array<int, array<string,mixed>> $items  Raw candidates.
	 * @return array<int, array<string, mixed>>
	 */
	public function submit_candidates( int $job_id, array $items ): array {
		$tiers = $this->services->settings->source_tiers();
		$out   = array();
		foreach ( array_values( $items ) as $i => $item ) {
			try {
				$row = CandidateInput::validate( is_array( $item ) ? $item : array(), $tiers );
			} catch ( ValidationException $e ) {
				$out[] = self::error( $i, $e->field_key, $e->reason );
				continue;
			}
			try {
				$stored = $this->candidates->upsert( $row, $job_id );
			} catch ( \RuntimeException $e ) {
				$out[] = self::error( $i, 'candidate', 'could not be stored' );
				continue;
			}
			$candidate = $stored['candidate'];
			if ( CandidateRepository::STATUS_NEW === $candidate['status'] ) {
				$candidate = $this->auto_resolve( $job_id, $candidate );
			}
			$out[] = array( 'index' => $i ) + self::candidate_result( $candidate, $stored['created'] );
		}
		return $out;
	}

	/**
	 * Run the matcher on a new candidate.
	 *
	 * @param int                  $job_id    Job ID.
	 * @param array<string, mixed> $candidate Candidate.
	 * @return array<string, mixed> Updated candidate.
	 */
	private function auto_resolve( int $job_id, array $candidate ): array {
		$probe    = array(
			'entity_type'     => $candidate['entityType'],
			'normalized_name' => $candidate['normalizedName'],
			'city'            => $candidate['city'],
			'state'           => $candidate['state'],
			'domain'          => CandidateNormalizer::domain( $candidate['website'] ),
			'identifiers'     => $candidate['identifiers'] ?? array(),
		);
		$decision = CandidateMatcher::decide( $probe, $this->index->candidates_for( $probe ) );

		switch ( $decision['decision'] ) {
			case CandidateMatcher::MATCH:
				$this->candidates->resolve( $candidate['id'], CandidateRepository::STATUS_MATCHED, $decision['entity_id'], $decision['confidence'], $decision['reason'] );
				break;
			case CandidateMatcher::CREATE:
				$entity_id = $this->create_draft( $job_id, $candidate );
				$this->candidates->resolve( $candidate['id'], CandidateRepository::STATUS_CREATED, $entity_id, null, $decision['reason'] );
				break;
			default:
				$this->candidates->resolve( $candidate['id'], CandidateRepository::STATUS_NEEDS_REVIEW, $decision['entity_id'], $decision['confidence'], $decision['reason'] );
				$this->log->add(
					$job_id,
					'warning',
					'match',
					'Candidate needs review: ' . $decision['reason'] . '.',
					array(
						'candidate_id'   => $candidate['id'],
						'suggested_id'   => $decision['entity_id'],
						'candidate_name' => $candidate['name'],
					)
				);
		}//end switch
		return (array) $this->candidates->find( $candidate['id'] );
	}

	/**
	 * Manual resolution (admin or worker).
	 *
	 * @param int      $candidate_id Candidate ID.
	 * @param string   $action       match|create|reject|needs_review.
	 * @param int|null $entity_id    Entity for "match".
	 * @param string   $reason       Reason.
	 * @return array<string, mixed>
	 * @throws JobException When invalid.
	 */
	public function resolve_candidate( int $candidate_id, string $action, ?int $entity_id, string $reason ): array {
		$candidate = $this->candidates->find( $candidate_id );
		if ( null === $candidate ) {
			throw new JobException( 'lexranked_not_found', 'Candidate not found.', 404 );
		}
		$reason = '' === trim( $reason ) ? 'resolved by ' . wp_get_current_user()->user_login : trim( $reason );
		switch ( $action ) {
			case 'match':
				$post = null === $entity_id ? null : get_post( $entity_id );
				$want = 'law_firm' === $candidate['entityType'] ? LawFirm::SLUG : Lawyer::SLUG;
				if ( ! $post instanceof \WP_Post || $post->post_type !== $want || 'trash' === $post->post_status ) {
					throw new JobException( 'lexranked_invalid_entity', 'entity_id must reference an existing ' . $candidate['entityType'] . '.', 400 );
				}
				$this->candidates->resolve( $candidate_id, CandidateRepository::STATUS_MATCHED, $entity_id, 1.0, $reason );
				break;
			case 'create':
				if ( null !== $candidate['entityId'] && CandidateRepository::STATUS_CREATED === $candidate['status'] ) {
					break;
					// Idempotent.
				}
				$created = $this->create_draft( $candidate['jobId'], $candidate );
				$this->candidates->resolve( $candidate_id, CandidateRepository::STATUS_CREATED, $created, null, $reason );
				break;
			case 'reject':
				$this->candidates->resolve( $candidate_id, CandidateRepository::STATUS_REJECTED, null, null, $reason );
				break;
			case 'needs_review':
				$this->candidates->resolve( $candidate_id, CandidateRepository::STATUS_NEEDS_REVIEW, $candidate['entityId'], $candidate['matchConfidence'], $reason );
				break;
			default:
				throw new JobException( 'lexranked_invalid_param', 'action must be match, create, reject or needs_review.', 400 );
		}//end switch
		AuditLog::log( 'research.candidate_' . $action, 'lr_candidate', $candidate_id, array( 'entity_id' => $entity_id ) );
		return self::candidate_result( (array) $this->candidates->find( $candidate_id ), false );
	}

	/**
	 * Create a draft lawyer/firm from a candidate. Only the name, location,
	 * practice area and website the candidate carries are set; evidence for
	 * them arrives as claims.
	 *
	 * @param int                  $job_id    Job ID.
	 * @param array<string, mixed> $candidate Candidate.
	 * @return int Entity ID.
	 * @throws \RuntimeException When the post cannot be created.
	 */
	private function create_draft( int $job_id, array $candidate ): int {
		$is_firm = 'law_firm' === $candidate['entityType'];
		$type    = $is_firm ? $this->services->law_firm : $this->services->lawyer;
		$title   = self::display_name( (string) $candidate['name'], $is_firm );
		$id      = wp_insert_post(
			array(
				'post_type'   => $type->slug(),
				'post_status' => 'draft',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( 'Could not create draft entity.' );
		}
		$id     = (int) $id;
		$fields = array();
		if ( ! $is_firm ) {
			$parts                = self::split_person_name( $title );
			$fields['first_name'] = $parts[0];
			$fields['last_name']  = $parts[1];
		}
		if ( null !== $candidate['website'] ) {
			$fields['website'] = $candidate['website'];
		}
		$errors = $this->services->entities->save_fields( $id, $type, $fields );
		foreach ( $errors as $field => $message ) {
			$this->log->add( $job_id, 'warning', 'create', 'Draft #' . $id . ': ' . $message, array( 'field' => $field ) );
		}
		$this->assign_location( $id, $candidate['city'], $candidate['state'] );
		if ( null !== $candidate['practiceArea'] ) {
			$this->assign_practice_areas( $job_id, $id, array( $candidate['practiceArea'] ) );
		}
		update_post_meta( $id, self::META_JOB, $job_id );
		update_post_meta( $id, self::META_CANDIDATE, (int) $candidate['id'] );
		$this->index->index( $id );
		$this->log->add(
			$job_id,
			'info',
			'create',
			'Created draft ' . $candidate['entityType'] . ' #' . $id . '.',
			array(
				'entity_id'    => $id,
				'candidate_id' => $candidate['id'],
			)
		);
		AuditLog::log( 'research.entity_drafted', $type->slug(), $id, array( 'job_id' => $job_id ) );
		return $id;
	}

	/**
	 * API shape of a candidate result. The suggested entity of a candidate in
	 * review is not returned: workers attach evidence only to decided entities.
	 *
	 * @param array<string, mixed> $candidate Candidate.
	 * @param bool                 $created   Newly stored.
	 * @return array<string, mixed>
	 */
	private static function candidate_result( array $candidate, bool $created ): array {
		$decided = in_array( $candidate['status'], array( CandidateRepository::STATUS_MATCHED, CandidateRepository::STATUS_CREATED ), true );
		return array(
			'candidateId' => $candidate['id'],
			'created'     => $created,
			'status'      => $candidate['status'],
			'entityId'    => $decided ? $candidate['entityId'] : null,
			'entityType'  => $candidate['entityType'],
			'reason'      => $candidate['reason'],
		);
	}

	// ─── Claims ─────────────────────────────────────────────────────────────

	/**
	 * Store evidence claims and apply resolved facts to research drafts.
	 *
	 * @param int                             $job_id Job ID.
	 * @param array<int, array<string,mixed>> $items  Raw claims.
	 * @return array{results: array<int, array<string, mixed>>, applied: array<int, array<int, string>>, review: array<int, int>}
	 */
	public function claims( int $job_id, array $items ): array {
		$results  = array();
		$touched  = array();
		$entities = array();
		foreach ( array_values( $items ) as $i => $item ) {
			$item      = is_array( $item ) ? $item : array();
			$entity_id = (int) ( $item['entity_id'] ?? 0 );
			$post      = $entities[ $entity_id ] ?? get_post( $entity_id );
			$type      = $post instanceof \WP_Post ? self::entity_type( $post ) : null;
			if ( null === $type || 'trash' === $post->post_status ) {
				$results[] = self::error( $i, 'entity_id', 'must reference an existing lawyer or law firm' );
				continue;
			}
			$entities[ $entity_id ] = $post;

			$method = (string) ( $item['method'] ?? 'structured_data' );
			if ( ! in_array( $method, self::WORKER_METHODS, true ) ) {
				$results[] = self::error( $i, 'method', 'must be seed, structured_data or ai' );
				continue;
			}
			if ( 'ai' === $method ) {
				if ( ! $this->services->settings->get( 'ai_enabled' ) ) {
					$results[] = self::error( $i, 'method', 'ai is not accepted: AI assistance is disabled in Settings' );
					continue;
				}
				if ( isset( $item['confidence'] ) && is_numeric( $item['confidence'] ) ) {
					$item['confidence'] = min( (float) $item['confidence'], self::AI_MAX_CONFIDENCE );
				}
			}
			unset( $item['method'] );

			// Derived server-side, never trusted from the worker.
			$item['entity_type'] = $type;
			// Research never self-certifies a claim; verification records do that under VerificationRules.
			$item['verification_status'] = VerificationStatus::Pending->value;
			$writable                    = in_array( $post->post_status, self::WRITABLE_STATUSES, true );
			try {
				$stored = $this->services->claims->insert_unique( $item, $job_id, $writable ? ClaimRepository::REVIEW_APPROVED : ClaimRepository::REVIEW_PENDING, $method );
			} catch ( ValidationException $e ) {
				$results[] = self::error( $i, $e->field_key, $e->reason );
				continue;
			} catch ( \RuntimeException $e ) {
				$results[] = self::error( $i, 'claim', 'could not be stored' );
				continue;
			}
			$results[] = array(
				'index'     => $i,
				'claimId'   => $stored['claim_id'],
				'duplicate' => $stored['duplicate'],
			);
			if ( ! $stored['duplicate'] ) {
				$touched[ $entity_id ] = true;
			}
		}//end foreach

		$applied = array();
		$review  = array();
		foreach ( array_keys( $touched ) as $entity_id ) {
			$post = $entities[ $entity_id ];
			if ( in_array( $post->post_status, self::WRITABLE_STATUSES, true ) ) {
				$applied[ $entity_id ] = $this->apply_facts( $job_id, $post );
			} else {
				update_post_meta( $entity_id, self::META_REVIEW, gmdate( 'Y-m-d\TH:i:s\Z' ) );
				$review[] = $entity_id;
				$this->log->add( $job_id, 'info', 'review', 'New evidence for published entity #' . $entity_id . ' queued for editorial review.' );
			}
		}
		return array(
			'results' => $results,
			'applied' => $applied,
			'review'  => $review,
		);
	}

	/**
	 * Resolve all claims of a draft entity and write the winning values.
	 *
	 * @param int      $job_id Job ID.
	 * @param \WP_Post $post   Entity post (draft/pending).
	 * @return array<int, string> Fields written.
	 */
	public function apply_facts( int $job_id, \WP_Post $post ): array {
		$entity_type = (string) self::entity_type( $post );
		$type        = 'law_firm' === $entity_type ? $this->services->law_firm : $this->services->lawyer;
		$resolver    = new FactResolver( $this->services->settings->source_tiers() );
		$resolved    = $resolver->resolve( $this->services->claims->for_entity( $entity_type, $post->ID, true ) );
		$owned       = '' !== (string) get_post_meta( $post->ID, self::META_JOB, true );
		$current     = $this->services->entities->record( $post, $type );
		$written     = array();
		$fields      = array();

		foreach ( $resolved as $field => $fact ) {
			if ( $fact['conflict'] ) {
				update_post_meta( $post->ID, self::META_REVIEW, gmdate( 'Y-m-d\TH:i:s\Z' ) );
				$this->log->add(
					$job_id,
					'warning',
					'resolve',
					sprintf( 'Conflicting values for "%s" on #%d; left for an editor.', $field, $post->ID ),
					array( 'alternatives' => $fact['alternatives'] )
				);
				continue;
			}
			if ( in_array( $field, array( 'name', 'city', 'state', 'practice_areas' ), true ) ) {
				continue;
				// Handled below.
			}
			if ( null === $type->field( $field ) ) {
				continue;
			}
			// Never overwrite an editor's value in a draft research did not create.
			if ( ! $owned && null !== ( $current['fields'][ $field ] ?? null ) ) {
				continue;
			}
			$fields[ $field ] = $fact['value'];
		}//end foreach

		if ( array() !== $fields ) {
			$errors = $this->services->entities->save_fields( $post->ID, $type, $fields );
			foreach ( $errors as $field => $message ) {
				$this->log->add( $job_id, 'warning', 'apply', '#' . $post->ID . ': ' . $message, array( 'field' => $field ) );
				unset( $fields[ $field ] );
			}
			$written = array_keys( $fields );
			// Keep the matching index (website domain, phone, bar number …) in step with the new facts.
			$this->index->index( $post->ID );
		}

		$ok = static fn( string $f ): bool => isset( $resolved[ $f ] ) && ! $resolved[ $f ]['conflict'];
		if ( $ok( 'name' ) && is_string( $resolved['name']['value'] ) && ( $owned || '' === trim( $post->post_title ) ) ) {
			$title = self::display_name( $resolved['name']['value'], 'law_firm' === $entity_type );
			if ( '' !== $title && $title !== $post->post_title ) {
				wp_update_post(
					array(
						'ID'         => $post->ID,
						'post_title' => $title,
					)
				);
				$written[] = 'name';
			}
		}
		if ( $ok( 'state' ) && ( $owned || array() === $current['locations'] ) ) {
			$city = $ok( 'city' ) && is_string( $resolved['city']['value'] ) ? $resolved['city']['value'] : null;
			try {
				$state = CandidateInput::state( $resolved['state']['value'] );
				if ( null !== $state && $this->assign_location( $post->ID, $city, $state ) ) {
					$written[] = 'location';
				}
			} catch ( ValidationException $e ) {
				$this->log->add( $job_id, 'warning', 'apply', '#' . $post->ID . ': state ' . $e->reason . '.' );
			}
		}
		if ( $ok( 'practice_areas' ) && ( $owned || array() === $current['practice_areas'] ) ) {
			$value = $resolved['practice_areas']['value'];
			if ( $this->assign_practice_areas( $job_id, $post->ID, is_array( $value ) ? $value : array( $value ) ) ) {
				$written[] = 'practice_areas';
			}
		}
		$this->index->index( $post->ID );
		return $written;
	}

	// ─── Verifications ──────────────────────────────────────────────────────

	/**
	 * Record automated verification results as pending records for an editor.
	 *
	 * @param int                             $job_id Job ID.
	 * @param array<int, array<string,mixed>> $items  {entity_id, verification_type, status, source_url, source_type, source_id?, notes?}.
	 * @return array<int, array<string, mixed>>
	 */
	public function verifications( int $job_id, array $items ): array {
		$tiers = $this->services->settings->source_tiers();
		$out   = array();
		foreach ( array_values( $items ) as $i => $item ) {
			$item      = is_array( $item ) ? $item : array();
			$entity_id = (int) ( $item['entity_id'] ?? 0 );
			$post      = get_post( $entity_id );
			if ( ! $post instanceof \WP_Post || null === self::entity_type( $post ) || 'trash' === $post->post_status ) {
				$out[] = self::error( $i, 'entity_id', 'must reference an existing lawyer or law firm' );
				continue;
			}
			$source_type = (string) ( $item['source_type'] ?? '' );
			$source_url  = trim( (string) ( $item['source_url'] ?? '' ) );
			if ( ! in_array( $source_type, $tiers->types(), true ) || ! CandidateInput::is_http_url( $source_url ) ) {
				$out[] = self::error( $i, 'source', 'a configured source_type and a full source_url are required' );
				continue;
			}
			$vtype     = (string) ( $item['verification_type'] ?? '' );
			$requested = (string) ( $item['status'] ?? '' );
			try {
				$status = VerificationRules::decide( $vtype, $requested, $tiers->tier_for( $source_type ) );
			} catch ( ValidationException $e ) {
				$out[] = self::error( $i, $e->field_key, $e->reason );
				continue;
			}

			$now  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
			$hash = sha1( implode( '|', array( $entity_id, $vtype, $status, $source_url, $now->format( 'Y-m-d' ) ) ) );
			$dupe = get_posts(
				array(
					'post_type'        => VerificationRecord::SLUG,
					'post_status'      => array( 'publish', 'pending', 'draft', 'private' ),
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact match on one key.
					'meta_query'       => array(
						array(
							'key'   => self::META_VER_HASH,
							'value' => $hash,
						),
					),
				)
			);
			if ( array() !== $dupe ) {
				$out[] = array(
					'index'          => $i,
					'verificationId' => (int) $dupe[0],
					'status'         => $status,
					'downgraded'     => $status !== $requested,
					'duplicate'      => true,
				);
				continue;
			}

			$id = wp_insert_post(
				array(
					'post_type'   => VerificationRecord::SLUG,
					'post_status' => 'pending',
					'post_title'  => sprintf( '%s — %s (research job #%d)', get_the_title( $post ), $vtype, $job_id ),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				$out[] = self::error( $i, 'verification', 'could not be stored' );
				continue;
			}
			$fields = array(
				'entity_id'         => $entity_id,
				'verification_type' => $vtype,
				'status'            => $status,
				'source_url'        => $source_url,
				'verified_by'       => 'research:job-' . $job_id,
				'notes'             => mb_substr( trim( (string) ( $item['notes'] ?? '' ) ), 0, 1000 ),
			);
			if ( isset( $item['source_id'] ) && get_post( (int) $item['source_id'] ) instanceof \WP_Post ) {
				$fields['source_id'] = (int) $item['source_id'];
			}
			if ( VerificationStatus::Pending->value !== $status ) {
				$fields['verified_at'] = $now->format( 'Y-m-d\TH:i:s\Z' );
			}
			if ( VerificationStatus::Verified->value === $status ) {
				$days                 = (int) ( $this->services->settings->get( 'freshness_rules' )[ self::freshness_category( $vtype ) ] ?? 90 );
				$fields['expires_at'] = $now->modify( '+' . $days . ' days' )->format( 'Y-m-d\TH:i:s\Z' );
			}
			$this->services->entities->save_fields( (int) $id, $this->services->verification, $fields );
			update_post_meta( (int) $id, self::META_VER_HASH, $hash );
			update_post_meta( (int) $id, self::META_JOB, $job_id );
			if ( $status !== $requested ) {
				$this->log->add( $job_id, 'info', 'verify', sprintf( '"%s" for #%d downgraded to %s: source tier %d is not authoritative enough.', $vtype, $entity_id, $status, $tiers->tier_for( $source_type ) ) );
			}
			$out[] = array(
				'index'          => $i,
				'verificationId' => (int) $id,
				'status'         => $status,
				'downgraded'     => $status !== $requested,
				'duplicate'      => false,
			);
		}//end foreach
		return $out;
	}

	/**
	 * Freshness category for a verification type.
	 *
	 * @param string $type Verification type.
	 */
	public static function freshness_category( string $type ): string {
		return match ( $type ) {
			'license', 'bar_status' => 'bar_status',
			'website'               => 'website',
			'review_data'           => 'review_data',
			default                 => 'profile',
		};
	}

	// ─── Targets (source refresh) ───────────────────────────────────────────

	/**
	 * Entities with a website inside the job's scope, by ascending ID.
	 *
	 * @param array<string, mixed> $job   Job view.
	 * @param int                  $after Only IDs greater than this.
	 * @param int                  $limit Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function targets( array $job, int $after, int $limit ): array {
		$want  = (string) ( $job['params']['entity_type'] ?? '' );
		$types = match ( $want ) {
			'lawyer'   => array( Lawyer::SLUG ),
			'law_firm' => array( LawFirm::SLUG ),
			default    => array( Lawyer::SLUG, LawFirm::SLUG ),
		};
		$tax = array();
		if ( array() !== $job['scope']['locations'] ) {
			$tax[] = array(
				'taxonomy' => Location::SLUG,
				'terms'    => array_column( $job['scope']['locations'], 'id' ),
			);
		}
		if ( array() !== $job['scope']['practiceAreas'] ) {
			$tax[] = array(
				'taxonomy' => PracticeArea::SLUG,
				'terms'    => array_column( $job['scope']['practiceAreas'], 'id' ),
			);
		}
		// Page through by ID so the cursor stays stable while entities are added.
		$filter = static function ( string $where ) use ( $after ): string {
			global $wpdb;
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
		};
		add_filter( 'posts_where', $filter );
		try {
			$posts = get_posts(
				array(
					'post_type'        => $types,
					'post_status'      => EntityIndex::STATUSES,
					'posts_per_page'   => $limit,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'no_found_rows'    => true,
					'suppress_filters' => false,
					'tax_query'        => array() === $tax ? array() : array_merge( array( 'relation' => 'AND' ), $tax ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Scoped batch.
				)
			);
		} finally {
			remove_filter( 'posts_where', $filter );
		}
		$out = array();
		foreach ( $posts as $post ) {
			$entity_type = (string) self::entity_type( $post );
			$type        = 'law_firm' === $entity_type ? $this->services->law_firm : $this->services->lawyer;
			$record      = $this->services->entities->record( $post, $type );
			$out[]       = array(
				'id'         => (int) $post->ID,
				'entityType' => $entity_type,
				'status'     => (string) $post->post_status,
				'name'       => $record['title'],
				'website'    => $record['fields']['website'] ?? null,
			);
		}
		return $out;
	}

	// ─── AI assistance (Phase 6) ────────────────────────────────────────────

	/**
	 * Candidates in review with their suggested profile, for AI match review.
	 *
	 * @param int $after Last candidate ID seen.
	 * @param int $limit Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function review_candidates( int $after, int $limit ): array {
		$out = array();
		foreach ( $this->candidates->needs_review_after( $after, $limit ) as $c ) {
			$suggested = null;
			$post      = null === $c['entityId'] ? null : get_post( (int) $c['entityId'] );
			if ( $post instanceof \WP_Post && null !== self::entity_type( $post ) && 'trash' !== $post->post_status ) {
				$type   = 'law_firm' === self::entity_type( $post ) ? $this->services->law_firm : $this->services->lawyer;
				$record = $this->services->entities->record( $post, $type );
				$city   = null;
				$state  = null;
				foreach ( $record['locations'] as $term ) {
					if ( null !== $term['state_code'] ) {
						$state = $term['state_code'];
					} elseif ( $term['parent'] > 0 ) {
						$city = $term['name'];
					}
				}
				$suggested = array(
					'id'            => (int) $post->ID,
					'name'          => $record['title'],
					'status'        => (string) $post->post_status,
					'city'          => $city,
					'state'         => $state,
					'website'       => $record['fields']['website'] ?? null,
					'practiceAreas' => array_column( $record['practice_areas'], 'name' ),
				);
			}//end if
			$out[] = array(
				'id'           => $c['id'],
				'entityType'   => $c['entityType'],
				'name'         => $c['name'],
				'city'         => $c['city'],
				'state'        => $c['state'],
				'practiceArea' => $c['practiceArea'],
				'website'      => $c['website'],
				'sourceUrl'    => $c['sourceUrl'],
				'reason'       => $c['reason'],
				'suggested'    => $suggested,
			);
		}//end foreach
		return $out;
	}

	/**
	 * Store advisory AI verdicts on candidates in review. Never resolves them.
	 *
	 * @param int                             $job_id Job ID.
	 * @param array<int, array<string,mixed>> $items  {candidate_id, verdict, confidence, reason, model}.
	 * @return array<int, array<string, mixed>>
	 */
	public function ai_notes( int $job_id, array $items ): array {
		$out     = array();
		$enabled = (bool) $this->services->settings->get( 'ai_enabled' );
		foreach ( array_values( $items ) as $i => $item ) {
			if ( ! $enabled ) {
				$out[] = self::error( $i, 'ai', 'assistance is disabled in Settings' );
				continue;
			}
			$item      = is_array( $item ) ? $item : array();
			$candidate = $this->candidates->find( (int) ( $item['candidate_id'] ?? 0 ) );
			if ( null === $candidate || CandidateRepository::STATUS_NEEDS_REVIEW !== $candidate['status'] ) {
				$out[] = self::error( $i, 'candidate_id', 'must reference a candidate awaiting review' );
				continue;
			}
			$verdict    = (string) ( $item['verdict'] ?? '' );
			$confidence = $item['confidence'] ?? null;
			$reason     = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) ( $item['reason'] ?? '' ) ) ) );
			if ( ! in_array( $verdict, array( 'same', 'different', 'unsure' ), true ) ) {
				$out[] = self::error( $i, 'verdict', 'must be same, different or unsure' );
				continue;
			}
			if ( ! is_numeric( $confidence ) || (float) $confidence < 0 || (float) $confidence > 1 || '' === $reason ) {
				$out[] = self::error( $i, 'confidence', 'must be 0–1 and come with a reason' );
				continue;
			}
			$this->candidates->set_ai_note(
				$candidate['id'],
				array(
					'verdict'    => $verdict,
					'confidence' => round( (float) $confidence, 2 ),
					'reason'     => mb_substr( $reason, 0, 500 ),
					'model'      => mb_substr( (string) ( $item['model'] ?? '' ), 0, 100 ),
					'jobId'      => $job_id,
					'at'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
				)
			);
			$out[] = array(
				'index'       => $i,
				'candidateId' => $candidate['id'],
				'stored'      => true,
			);
		}//end foreach
		return $out;
	}

	/**
	 * Store (or replace, per job and target) a machine-drafted content draft.
	 *
	 * @param int                  $job_id  Job ID.
	 * @param array<string, mixed> $payload Raw payload (see ContentDraftInput).
	 * @return array<string, mixed>
	 * @throws JobException When invalid or not allowed.
	 */
	public function content_draft( int $job_id, array $payload ): array {
		if ( ! $this->services->settings->get( 'ai_enabled' ) ) {
			throw new JobException( 'lexranked_ai_disabled', 'AI assistance is disabled in Settings.', 403 );
		}
		try {
			$draft = ContentDraftInput::validate( $payload );
		} catch ( ValidationException $e ) {
			throw new JobException( 'lexranked_invalid_param', $e->field_key . ' ' . $e->reason . '.', 400 );
		}
		$label = $this->draft_target_label( $draft );

		$key      = sha1( implode( '|', array( $job_id, $draft['content_type'], (string) $draft['target_id'], (string) $draft['target_term'], 'article' === $draft['content_type'] ? $draft['title'] : '' ) ) );
		$existing = get_posts(
			array(
				'post_type'        => ContentDraft::SLUG,
				'post_status'      => array( 'draft', 'pending' ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact match on one key.
				'meta_query'       => array(
					array(
						'key'   => self::META_DRAFT_KEY,
						'value' => $key,
					),
				),
			)
		);
		$postarr = array(
			'post_type'                  => ContentDraft::SLUG,
			'post_status'                => 'draft',
			// Never published by the generator.
							'post_title' => sprintf( 'AI draft: %s (%s)', $label, gmdate( 'Y-m-d' ) ),
			'post_content'               => ContentDraftInput::to_html( $draft['sections'] ),
		);
		if ( array() !== $existing ) {
			$postarr['ID'] = (int) $existing[0];
		}
		$id = array() === $existing ? wp_insert_post( wp_slash( $postarr ), true ) : wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			throw new JobException( 'lexranked_store_failed', 'Could not store the content draft.', 500 );
		}
		$id = (int) $id;
		$this->services->entities->save_fields(
			$id,
			$this->services->content_draft,
			array(
				'content_type'    => $draft['content_type'],
				'target_id'       => $draft['target_id'],
				'target_term'     => $draft['target_term'],
				'target_taxonomy' => $draft['target_taxonomy'],
				'qa_status'       => $draft['qa_status'],
				'summary'         => $draft['summary'],
				'faq'             => ContentDraftInput::faq_for_field( $draft['faq'] ),
				'qa_report'       => (string) wp_json_encode( $draft['issues'] ),
				'facts'           => (string) wp_json_encode( $draft['facts'] ),
				'model'           => $draft['model'],
				'prompt_version'  => $draft['prompt_version'],
				'job_id'          => $job_id,
			),
			true
		);
		update_post_meta( $id, self::META_DRAFT_KEY, $key );
		$this->log->add(
			$job_id,
			ContentDraft::QA_READY === $draft['qa_status'] ? 'info' : 'warning',
			'content',
			sprintf( 'Content draft #%d (%s) for %s: %s (%d QA issue(s)).', $id, $draft['content_type'], $label, $draft['qa_status'], count( $draft['issues'] ) )
		);
		AuditLog::log( 'content_draft.stored', ContentDraft::SLUG, $id, array( 'job_id' => $job_id ) );
		return array(
			'draftId'  => $id,
			'qaStatus' => $draft['qa_status'],
			'updated'  => array() !== $existing,
		);
	}

	/**
	 * Check a draft's target and return a label for titles and logs.
	 *
	 * @param array<string, mixed> $draft Validated draft.
	 * @throws JobException When the target is not allowed.
	 */
	private function draft_target_label( array $draft ): string {
		$type = (string) $draft['content_type'];
		if ( 'hub_content' === $type ) {
			$term = get_term( (int) $draft['target_term'], (string) $draft['target_taxonomy'] );
			if ( ! $term instanceof \WP_Term ) {
				throw new JobException( 'lexranked_invalid_target', 'target_term must reference an existing location or practice area.', 400 );
			}
			return (string) $term->name;
		}
		$post = null === $draft['target_id'] ? null : get_post( (int) $draft['target_id'] );
		$want = match ( $type ) {
			'profile_summary' => array( Lawyer::SLUG, LawFirm::SLUG ),
			default           => array( Ranking::SLUG ),
		};
		if ( 'article' === $type && null === $post ) {
			return (string) $draft['title'];
		}
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $want, true ) || 'publish' !== $post->post_status ) {
			throw new JobException( 'lexranked_invalid_target', 'target_id must reference a published ' . implode( ' or ', $want ) . '.', 400 );
		}
		return 'article' === $type ? (string) $draft['title'] : get_the_title( $post );
	}

	// ─── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Assign a city (under its state) or just the state.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $city    City name.
	 * @param string|null $state   State code.
	 * @return bool Whether terms were assigned.
	 */
	private function assign_location( int $post_id, ?string $city, ?string $state ): bool {
		if ( null === $state || null === UsStates::name( $state ) ) {
			return false;
		}
		$state_id = $this->state_term( $state );
		if ( null === $state_id ) {
			return false;
		}
		$term_id = $state_id;
		if ( null !== $city && '' !== trim( $city ) ) {
			$term_id = $this->city_term( trim( $city ), $state_id ) ?? $state_id;
		}
		$result = wp_set_object_terms( $post_id, array( $term_id ), Location::SLUG );
		return ! is_wp_error( $result );
	}

	/**
	 * State term by code, created when missing.
	 *
	 * @param string $code State code.
	 */
	private function state_term( string $code ): ?int {
		$found = get_terms(
			array(
				'taxonomy'   => Location::SLUG,
				'hide_empty' => false,
				'parent'     => 0,
				'number'     => 1,
				'fields'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Tiny taxonomy.
				'meta_key'   => Location::META_STATE,
				'meta_value' => strtoupper( $code ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Tiny taxonomy.
			)
		);
		if ( is_array( $found ) && array() !== $found ) {
			return (int) $found[0];
		}
		$name   = (string) UsStates::name( $code );
		$result = wp_insert_term( $name, Location::SLUG, array( 'slug' => sanitize_title( $name ) ) );
		if ( is_wp_error( $result ) ) {
			$existing = get_term_by( 'slug', sanitize_title( $name ), Location::SLUG );
			if ( ! $existing instanceof \WP_Term ) {
				return null;
			}
			$result = array( 'term_id' => $existing->term_id );
		}
		update_term_meta( (int) $result['term_id'], Location::META_STATE, strtoupper( $code ) );
		return (int) $result['term_id'];
	}

	/**
	 * City term under a state, created when missing.
	 *
	 * @param string $city     City name.
	 * @param int    $state_id State term ID.
	 */
	private function city_term( string $city, int $state_id ): ?int {
		$children = get_terms(
			array(
				'taxonomy'   => Location::SLUG,
				'hide_empty' => false,
				'parent'     => $state_id,
			)
		);
		foreach ( is_array( $children ) ? $children : array() as $term ) {
			if ( $term instanceof \WP_Term && 0 === strcasecmp( $term->name, $city ) ) {
				return (int) $term->term_id;
			}
		}
		$result = wp_insert_term( $city, Location::SLUG, array( 'parent' => $state_id ) );
		return is_wp_error( $result ) ? null : (int) $result['term_id'];
	}

	/**
	 * Assign existing practice-area terms by slug or name. Unknown areas are
	 * logged, never created: the practice taxonomy is editorial.
	 *
	 * @param int               $job_id  Job ID.
	 * @param int               $post_id Post ID.
	 * @param array<int, mixed> $values  Names or slugs.
	 * @return bool Whether any term was assigned.
	 */
	private function assign_practice_areas( int $job_id, int $post_id, array $values ): bool {
		$ids = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}
			$term = get_term_by( 'slug', sanitize_title( $value ), PracticeArea::SLUG );
			$term = $term instanceof \WP_Term ? $term : get_term_by( 'name', trim( $value ), PracticeArea::SLUG );
			if ( $term instanceof \WP_Term ) {
				$ids[] = (int) $term->term_id;
			} else {
				$this->log->add( $job_id, 'warning', 'apply', 'Unknown practice area "' . mb_substr( $value, 0, 80 ) . '" for #' . $post_id . '; add it to the taxonomy first.' );
			}
		}
		if ( array() === $ids ) {
			return false;
		}
		return ! is_wp_error( wp_set_object_terms( $post_id, array_values( array_unique( $ids ) ), PracticeArea::SLUG ) );
	}

	/**
	 * Entity type for a post.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function entity_type( \WP_Post $post ): ?string {
		return match ( $post->post_type ) {
			Lawyer::SLUG  => 'lawyer',
			LawFirm::SLUG => 'law_firm',
			default       => null,
		};
	}

	/**
	 * Display name: collapse whitespace and drop honorifics/suffixes for people.
	 *
	 * @param string $name    Raw name.
	 * @param bool   $is_firm Firm names are kept as written.
	 */
	public static function display_name( string $name, bool $is_firm ): string {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
		if ( $is_firm ) {
			return $name;
		}
		$name = (string) preg_replace( '/,?\s+(Esq\.?|Esquire|J\.?D\.?)$/i', '', $name );
		$name = (string) preg_replace( '/^(Mr|Mrs|Ms|Dr|Hon)\.?\s+/i', '', $name );
		return trim( $name, " ,\t" );
	}

	/**
	 * First and last name from a display name ("Jane A. Doe" → [Jane, Doe]).
	 *
	 * @param string $name Display name.
	 * @return array{0: string, 1: string}
	 */
	public static function split_person_name( string $name ): array {
		$words = array_values( array_filter( explode( ' ', $name ), static fn( string $w ): bool => '' !== $w ) );
		if ( count( $words ) < 2 ) {
			return array( $name, $name );
		}
		$last = array_pop( $words );
		// Keep generational suffixes with the last name ("Smith Jr.").
		if ( preg_match( '/^(Jr|Sr|II|III|IV)\.?$/i', $last ) && count( $words ) >= 2 ) {
			$last = array_pop( $words ) . ' ' . $last;
		}
		return array( $words[0], $last );
	}

	/**
	 * Per-item validation error.
	 *
	 * @param int    $index  Item index.
	 * @param string $field  Field.
	 * @param string $reason Reason.
	 * @return array{index: int, error: array{field: string, message: string}}
	 */
	private static function error( int $index, string $field, string $reason ): array {
		return array(
			'index' => $index,
			'error' => array(
				'field'   => $field,
				'message' => $field . ' ' . $reason,
			),
		);
	}
}
