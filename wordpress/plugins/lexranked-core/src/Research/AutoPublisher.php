<?php
/**
 * Autonomous research: publish what passes every check.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\PostTypes\Source;
use LexRanked\Core\PostTypes\VerificationRecord;
use LexRanked\Core\Repository\ClaimRepository;
use LexRanked\Core\Security\AuditLog;
use LexRanked\Core\Services;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Runs when a research job completes and Settings → "Autonomous research" is
 * on (a job can opt out with the parameter `"auto_publish": false`).
 *
 * For each lawyer / law firm the job created, AutoPublishPolicy decides:
 * publish it together with its verified verification records and the
 * sources behind them, or keep everything as a draft and record why. Then a ranking is
 * created for each city and practice area that now has enough published
 * profiles and no ranking yet. Verified checks about profiles that were
 * already published are published too, unless a published record of the same
 * check contradicts them. Positions are still calculated by the engine.
 */
final class AutoPublisher {

	/** Why a research draft was kept (JSON list of reasons). */
	public const META_HOLD = '_lr_auto_publish_hold';

	/**
	 * Constructor.
	 *
	 * @param Services    $services Services.
	 * @param ResearchLog $log      Job log.
	 */
	public function __construct( private readonly Services $services, private readonly ResearchLog $log ) {
	}

	/**
	 * Whether the job's results should be published automatically.
	 *
	 * @param array<string, mixed> $params Job parameters.
	 */
	public function enabled_for( array $params ): bool {
		return (bool) $this->services->settings->get( 'research_autonomy' ) && false !== ( $params['auto_publish'] ?? true );
	}

	/**
	 * Apply the policy to everything the job created.
	 *
	 * @param int $job_id Job ID.
	 * @return array{published: array<int, int>, held: array<int, array<int, string>>, sources: int, verifications: int, claims: int, rankings: array<int, int>}
	 */
	public function run( int $job_id ): array {
		$summary = array(
			'published'     => array(),
			'held'          => array(),
			'sources'       => 0,
			'verifications' => 0,
			'claims'        => 0,
			'rankings'      => array(),
		);

		// Large jobs publish hundreds of profiles; do not let the PHP time limit
		// or a closed connection stop the run halfway (it is also re-runnable).
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
		ignore_user_abort( true );

		$records = $this->job_verifications( $job_id );
		$backing = array();
		$earlier = array();
		foreach ( $this->job_entities( $job_id ) as $post ) {
			// Published by an earlier run of this job: count it for rankings, change nothing.
			if ( 'publish' === $post->post_status ) {
				$earlier[] = (int) $post->ID;
				continue;
			}
			$is_firm  = LawFirm::SLUG === $post->post_type;
			$type     = $is_firm ? $this->services->law_firm : $this->services->lawyer;
			$record   = $this->services->entities->record( $post, $type );
			$city     = null;
			$state    = null;
			$checks   = array_values( array_filter( $records, static fn( array $r ): bool => $r['entity_id'] === (int) $post->ID ) );
			$location = $this->city_and_state( $record['locations'] );
			if ( null !== $location ) {
				$city  = $location['city']['name'];
				$state = $location['state']['name'];
			}
			$decision = AutoPublishPolicy::decide_entity(
				array(
					'entity_type'         => $is_firm ? 'law_firm' : 'lawyer',
					'status'              => $record['status'],
					'title'               => $record['title'],
					'fields'              => $record['fields'],
					'city'                => $city,
					'state'               => $state,
					'practice_area_count' => count( $record['practice_areas'] ),
					'flagged_for_review'  => '' !== (string) get_post_meta( $post->ID, ResearchIngest::META_REVIEW, true ),
					'verifications'       => array_map(
						static fn( array $r ): array => array(
							'type'   => $r['type'],
							'status' => $r['status'],
						),
						$checks
					),
				)
			);

			if ( ! $decision['publish'] ) {
				update_post_meta( $post->ID, self::META_HOLD, (string) wp_json_encode( $decision['reasons'] ) );
				$summary['held'][ (int) $post->ID ] = $decision['reasons'];
				$this->log->add(
					$job_id,
					'warning',
					'auto_publish',
					sprintf( 'Kept #%d (%s) as a draft: %s.', $post->ID, $record['title'], implode( '; ', $decision['reasons'] ) ),
					array( 'entity_id' => (int) $post->ID )
				);
				continue;
			}

			foreach ( $checks as $check ) {
				if ( 'verified' !== $check['status'] ) {
					continue;
				}
				if ( 'pending' === $check['post_status'] && $this->publish_post( $check['id'] ) ) {
					++$summary['verifications'];
				}
				$backing[] = $check;
			}
			if ( $this->publish_post( (int) $post->ID ) ) {
				delete_post_meta( $post->ID, self::META_HOLD );
				$summary['published'][] = (int) $post->ID;
				$this->log->add( $job_id, 'info', 'auto_publish', sprintf( 'Published #%d (%s): every check passed.', $post->ID, $record['title'] ), array( 'entity_id' => (int) $post->ID ) );
				AuditLog::log( 'research.auto_published', $post->post_type, (int) $post->ID, array( 'job_id' => $job_id ) );
			}
		}//end foreach

		// New official checks about profiles that were already published.
		$handled = array_column( $backing, 'id' );
		foreach ( $records as $check ) {
			$entity = get_post( $check['entity_id'] );
			if ( in_array( $check['id'], $handled, true ) || in_array( $check['entity_id'], $summary['published'], true ) ) {
				continue;
			}
			if ( ! $entity instanceof \WP_Post || 'publish' !== $entity->post_status || 'pending' !== $check['post_status'] ) {
				continue;
			}
			if ( ! AutoPublishPolicy::publish_record_for_published( $check['status'], $this->published_statuses( $check['entity_id'], $check['type'] ) ) ) {
				continue;
			}
			if ( $this->publish_post( $check['id'] ) ) {
				++$summary['verifications'];
				$backing[] = $check;
				$this->log->add( $job_id, 'info', 'auto_publish', sprintf( 'Published verified %s check for #%d.', $check['type'], $check['entity_id'] ), array( 'entity_id' => $check['entity_id'] ) );
			}
		}

		$summary['claims']   = $this->approve_claims( $job_id );
		$summary['sources']  = $this->publish_sources( $job_id, $backing );
		$summary['rankings'] = $this->ensure_rankings( $job_id, array_merge( $summary['published'], $earlier ) );

		$this->log->add(
			$job_id,
			'info',
			'auto_publish',
			sprintf(
				'Autonomous research: %d published, %d kept as drafts, %d verification records and %d sources published, %d rankings created, %d facts approved on published profiles.',
				count( $summary['published'] ),
				count( $summary['held'] ),
				$summary['verifications'],
				$summary['sources'],
				count( $summary['rankings'] ),
				$summary['claims']
			)
		);
		return $summary;
	}

	/**
	 * Approve and apply the job's evidence about already-published profiles
	 * that AutoPublishPolicy::approve_claim_for_published allows.
	 *
	 * @param int $job_id Job ID.
	 * @return int Claims approved.
	 */
	private function approve_claims( int $job_id ): int {
		$tiers    = $this->services->settings->source_tiers();
		$approved = 0;
		$touched  = array();
		foreach ( $this->services->claims->pending_review( 1000 ) as $claim ) {
			if ( (int) $claim['job_id'] !== $job_id ) {
				continue;
			}
			$post = get_post( (int) $claim['entity_id'] );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
				continue;
			}
			$type    = LawFirm::SLUG === $post->post_type ? $this->services->law_firm : $this->services->lawyer;
			$field   = (string) $claim['field_name'];
			$current = null === $type->field( $field ) ? null : ( $this->services->entities->record( $post, $type )['fields'][ $field ] ?? null );
			$empty   = null === $current || '' === $current || array() === $current;
			$allowed = AutoPublishPolicy::approve_claim_for_published(
				$tiers->tier_for( (string) $claim['source_type'] ),
				(string) $claim['method'],
				null !== $type->field( $field ) && ! in_array( $field, array( 'name', 'city', 'state', 'practice_areas' ), true ),
				$empty || wp_json_encode( $current ) === wp_json_encode( $claim['value'] )
			);
			if ( ! $allowed ) {
				continue;
			}
			$this->services->claims->set_review_status( (int) $claim['claim_id'], ClaimRepository::REVIEW_APPROVED );
			if ( $empty ) {
				$this->services->entities->save_fields( $post->ID, $type, array( $field => $claim['value'] ) );
			}
			++$approved;
			$touched[ $post->ID ] = true;
			AuditLog::log(
				'research.claim_auto_approved',
				'lr_claim',
				(int) $claim['claim_id'],
				array(
					'entity_id' => $post->ID,
					'field'     => $field,
				)
			);
		}//end foreach
		$pending = $this->services->claims->pending_review( 1000 );
		foreach ( array_keys( $touched ) as $entity_id ) {
			if ( array() === array_filter( $pending, static fn( array $c ): bool => (int) $c['entity_id'] === $entity_id ) ) {
				delete_post_meta( $entity_id, ResearchIngest::META_REVIEW );
			}
			$this->services->entity_index->index( $entity_id );
		}
		if ( $approved > 0 ) {
			$this->log->add( $job_id, 'info', 'auto_publish', sprintf( 'Approved %d facts from official sources on published profiles (only empty or unchanged fields).', $approved ) );
		}
		return $approved;
	}

	/**
	 * Statuses of an entity's published records for one check.
	 *
	 * @param int    $entity_id Entity post ID.
	 * @param string $type      Verification type.
	 * @return array<int, string>
	 */
	private function published_statuses( int $entity_id, string $type ): array {
		$v     = $this->services->verification;
		$posts = get_posts(
			array(
				'post_type'        => VerificationRecord::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Two exact keys on a short list.
				'meta_query'       => array(
					array(
						'key'   => (string) $v->field( 'entity_id' )?->meta_key(),
						'value' => $entity_id,
					),
					array(
						'key'   => (string) $v->field( 'verification_type' )?->meta_key(),
						'value' => $type,
					),
				),
			)
		);
		return array_map( fn( \WP_Post $p ): string => (string) ( $this->services->entities->record( $p, $v )['fields']['status'] ?? '' ), $posts );
	}

	/**
	 * Draft lawyers and firms created by the job.
	 *
	 * @param int $job_id Job ID.
	 * @return array<int, \WP_Post>
	 */
	private function job_entities( int $job_id ): array {
		return get_posts(
			array(
				'post_type'        => array( Lawyer::SLUG, LawFirm::SLUG ),
				'post_status'      => 'draft',
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact match on one key.
				'meta_query'       => array(
					array(
						'key'   => ResearchIngest::META_JOB,
						'value' => $job_id,
					),
				),
			)
		);
	}

	/**
	 * Verification records created by the job.
	 *
	 * @param int $job_id Job ID.
	 * @return array<int, array{id: int, post_status: string, entity_id: int, type: string, status: string, source_id: int, source_url: string}>
	 */
	private function job_verifications( int $job_id ): array {
		$posts = get_posts(
			array(
				'post_type'        => VerificationRecord::SLUG,
				'post_status'      => array( 'pending', 'publish' ),
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact match on one key.
				'meta_query'       => array(
					array(
						'key'   => ResearchIngest::META_JOB,
						'value' => $job_id,
					),
				),
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$f     = $this->services->entities->record( $post, $this->services->verification )['fields'];
			$out[] = array(
				'id'          => (int) $post->ID,
				'post_status' => (string) $post->post_status,
				'entity_id'   => (int) ( $f['entity_id'] ?? 0 ),
				'type'        => (string) ( $f['verification_type'] ?? '' ),
				'status'      => (string) ( $f['status'] ?? '' ),
				'source_id'   => (int) ( $f['source_id'] ?? 0 ),
				'source_url'  => (string) ( $f['source_url'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Publish the job's pending sources that back a published record and are
	 * authoritative enough. Others stay pending for an editor.
	 *
	 * @param int                              $job_id  Job ID.
	 * @param array<int, array<string, mixed>> $backing Verification records of published entities.
	 * @return int Sources published.
	 */
	private function publish_sources( int $job_id, array $backing ): int {
		$ids  = array_filter( array_map( static fn( array $r ): int => (int) $r['source_id'], $backing ) );
		$urls = array_filter( array_map( static fn( array $r ): string => (string) $r['source_url'], $backing ) );
		if ( array() === $ids && array() === $urls ) {
			return 0;
		}
		$tiers = $this->services->settings->source_tiers();
		$count = 0;
		$posts = get_posts(
			array(
				'post_type'        => Source::SLUG,
				'post_status'      => 'pending',
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact match on one key.
				'meta_query'       => array(
					array(
						'key'   => ResearchIngest::META_JOB,
						'value' => $job_id,
					),
				),
			)
		);
		foreach ( $posts as $post ) {
			$f      = $this->services->entities->record( $post, $this->services->source )['fields'];
			$backs  = in_array( (int) $post->ID, $ids, true ) || in_array( (string) ( $f['url'] ?? '' ), $urls, true );
			$tier   = $tiers->tier_for( (string) ( $f['source_type'] ?? '' ) );
			$public = AutoPublishPolicy::publish_source( $tier, $backs );
			if ( $public && $this->publish_post( (int) $post->ID ) ) {
				++$count;
			} elseif ( $backs && ! $public ) {
				$this->log->add( $job_id, 'info', 'auto_publish', sprintf( 'Source #%d left pending: tier %d is below the automatic threshold.', $post->ID, $tier ) );
			}
		}
		return $count;
	}

	/**
	 * Create a ranking for each city and practice area of the newly published
	 * entities that has enough published profiles and no ranking yet.
	 *
	 * @param int             $job_id    Job ID.
	 * @param array<int, int> $published Newly published entity IDs.
	 * @return array<int, int> Created ranking IDs.
	 */
	private function ensure_rankings( int $job_id, array $published ): array {
		$pairs = array();
		foreach ( $published as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$location = $this->city_and_state( $this->services->entities->location_terms( $id ) );
			if ( null === $location ) {
				continue;
			}
			$entity_type = LawFirm::SLUG === $post->post_type ? 'law_firm' : 'lawyer';
			foreach ( $this->services->entities->practice_terms( $id ) as $area ) {
				$key           = $entity_type . '|' . $location['city']['id'] . '|' . $area['id'];
				$pairs[ $key ] = array(
					'entity_type' => $entity_type,
					'post_type'   => $post->post_type,
					'city'        => $location['city'],
					'state'       => $location['state'],
					'area'        => $area,
				);
			}
		}//end foreach

		$created = array();
		$min     = (int) $this->services->settings->get( 'min_ranking_entities' );
		foreach ( $pairs as $pair ) {
			$decision = AutoPublishPolicy::decide_ranking(
				$this->count_published( $pair['post_type'], $pair['city']['id'], $pair['area']['id'] ),
				$min,
				$this->ranking_exists( $pair['entity_type'], $pair['city']['id'], $pair['area']['id'] )
			);
			$title    = AutoPublishPolicy::ranking_title( $pair['entity_type'], $pair['area']['name'], $pair['city']['name'], $pair['state']['name'] );
			if ( ! $decision['create'] ) {
				$this->log->add( $job_id, 'info', 'auto_publish', sprintf( 'No new ranking "%s": %s.', $title, $decision['reason'] ) );
				continue;
			}
			$id = $this->create_ranking( $title, $pair['entity_type'], $pair['city']['id'], $pair['area']['id'] );
			if ( null !== $id ) {
				$created[] = $id;
				$this->log->add( $job_id, 'info', 'auto_publish', sprintf( 'Created ranking #%d "%s" (%s).', $id, $title, $decision['reason'] ) );
				AuditLog::log( 'research.ranking_created', Ranking::SLUG, $id, array( 'job_id' => $job_id ) );
			}
		}
		return $created;
	}

	/**
	 * Published real (non-demo) entities of a type in a city and practice area.
	 *
	 * @param string $post_type Post type.
	 * @param int    $city_id   City term ID.
	 * @param int    $area_id   Practice-area term ID.
	 */
	private function count_published( string $post_type, int $city_id, int $area_id ): int {
		return count(
			get_posts(
				array(
					'post_type'        => $post_type,
					'post_status'      => 'publish',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Two exact term IDs.
					'tax_query'        => self::tax_query( $city_id, $area_id ),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Demo flag on a short list.
					'meta_query'       => array(
						'relation' => 'OR',
						array(
							'key'     => '_lr_is_demo',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_lr_is_demo',
							'value'   => '1',
							'compare' => '!=',
						),
					),
				)
			)
		);
	}

	/**
	 * Whether a non-contextual ranking for the pair exists in any status but trash.
	 *
	 * @param string $entity_type lawyer|law_firm.
	 * @param int    $city_id     City term ID.
	 * @param int    $area_id     Practice-area term ID.
	 */
	private function ranking_exists( string $entity_type, int $city_id, int $area_id ): bool {
		$posts = get_posts(
			array(
				'post_type'        => Ranking::SLUG,
				'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Two exact term IDs.
				'tax_query'        => self::tax_query( $city_id, $area_id ),
			)
		);
		foreach ( $posts as $post ) {
			$f = $this->services->entities->record( $post, $this->services->ranking )['fields'];
			if ( ( $f['entity_type'] ?? null ) === $entity_type && in_array( $f['context_type'] ?? null, array( null, '' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Create and publish a ranking. The engine calculates its positions.
	 *
	 * @param string $title       Title.
	 * @param string $entity_type lawyer|law_firm.
	 * @param int    $city_id     City term ID.
	 * @param int    $area_id     Practice-area term ID.
	 */
	private function create_ranking( string $title, string $entity_type, int $city_id, int $area_id ): ?int {
		$id = wp_insert_post(
			array(
				'post_type'   => Ranking::SLUG,
				'post_status' => 'draft',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return null;
		}
		$this->services->entities->save_fields( (int) $id, $this->services->ranking, array( 'entity_type' => $entity_type ) );
		wp_set_object_terms( (int) $id, array( $city_id ), Location::SLUG );
		wp_set_object_terms( (int) $id, array( $area_id ), PracticeArea::SLUG );
		// Publish last so save hooks see the complete record.
		return $this->publish_post( (int) $id ) ? (int) $id : null;
	}

	/**
	 * The city (a location with a parent) and its state.
	 *
	 * @param array<int, array<string, mixed>> $locations Location terms incl. parents.
	 * @return array{city: array<string, mixed>, state: array<string, mixed>}|null
	 */
	private function city_and_state( array $locations ): ?array {
		foreach ( $locations as $city ) {
			if ( (int) $city['parent'] <= 0 ) {
				continue;
			}
			foreach ( $locations as $state ) {
				if ( (int) $state['id'] === (int) $city['parent'] ) {
					return array(
						'city'  => $city,
						'state' => $state,
					);
				}
			}
		}
		return null;
	}

	/**
	 * Tax query for a city and a practice area.
	 *
	 * @param int $city_id City term ID.
	 * @param int $area_id Practice-area term ID.
	 * @return array<string|int, mixed>
	 */
	private static function tax_query( int $city_id, int $area_id ): array {
		return array(
			'relation' => 'AND',
			array(
				'taxonomy'         => Location::SLUG,
				'field'            => 'term_id',
				'terms'            => array( $city_id ),
				'include_children' => false,
			),
			array(
				'taxonomy'         => PracticeArea::SLUG,
				'field'            => 'term_id',
				'terms'            => array( $area_id ),
				'include_children' => false,
			),
		);
	}

	/**
	 * Publish a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function publish_post( int $post_id ): bool {
		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);
		return ! is_wp_error( $result ) && 0 !== $result;
	}
}
