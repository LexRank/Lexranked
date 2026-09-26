<?php
/**
 * WP-CLI commands.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\CLI;

use LexRanked\Core\Content\TermContent;
use LexRanked\Core\Database\Installer;
use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\PostType;
use LexRanked\Core\Research\JobException;
use LexRanked\Core\Research\JobPolicy;
use LexRanked\Core\Services;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Manage LexRanked data.
 *
 * ## EXAMPLES
 *
 *     wp lexranked status
 *     wp lexranked seed-demo
 *     wp lexranked purge-demo --yes
 */
final class Command {

	private const TERM_DEMO_META = '_lr_is_demo';

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Show plugin, schema and content status.
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		\WP_CLI::log( 'Plugin version: ' . LEXRANKED_CORE_VERSION );
		\WP_CLI::log( 'API: ' . rest_url( Plugin::REST_NAMESPACE ) );
		\WP_CLI::log( 'Schema version: ' . (string) get_option( Installer::VERSION_OPTION, 'not installed' ) );
		$rows = array();
		foreach ( $this->services->post_types() as $type ) {
			$counts = wp_count_posts( $type->slug() );
			$rows[] = array(
				'type'      => $type->slug(),
				'published' => (int) ( $counts->publish ?? 0 ),
				'demo'      => count( $this->demo_ids( $type ) ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'type', 'published', 'demo' ) );
	}

	/**
	 * Create clearly-labelled demo (mock) data: Miami, FL · Personal Injury.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Remove existing demo data first.
	 *
	 * @subcommand seed-demo
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function seed_demo( array $args, array $assoc_args ): void {
		unset( $args );
		if ( array() !== $this->demo_ids( $this->services->lawyer ) ) {
			if ( ! isset( $assoc_args['force'] ) ) {
				\WP_CLI::error( 'Demo data already exists. Use --force to recreate it.' );
			}
			$this->purge();
		}

		$s        = $this->services;
		$state    = $this->term( Location::SLUG, DemoData::STATE['name'], DemoData::STATE['slug'], 0 );
		$city     = $this->term( Location::SLUG, DemoData::CITY['name'], DemoData::CITY['slug'], $state );
		$practice = $this->term( PracticeArea::SLUG, DemoData::PRACTICE['name'], DemoData::PRACTICE['slug'], 0 );
		update_term_meta( $state, Location::META_STATE, DemoData::STATE['code'] );

		$sources = array();
		foreach ( DemoData::sources() as $key => $source ) {
			$sources[ $key ] = $this->create(
				$s->source,
				$source['title'],
				'',
				array(
					'url'         => $source['url'],
					'source_type' => $source['source_type'],
				)
			);
		}

		$firms = array();
		foreach ( DemoData::firms() as $key => $firm ) {
			$firms[ $key ] = $this->create(
				$s->law_firm,
				$firm['title'],
				$firm['content'],
				array(
					'website'      => $firm['website'],
					'phone'        => $firm['phone'],
					'address'      => $firm['address'],
					'zip_code'     => $firm['zip_code'],
					'country'      => 'US',
					'rating'       => $firm['rating'],
					'review_count' => $firm['review_count'],
				)
			);
			wp_set_object_terms( $firms[ $key ], array( $city ), Location::SLUG );
			wp_set_object_terms( $firms[ $key ], array( $practice ), PracticeArea::SLUG );
		}//end foreach

		$verified_at = gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS );
		$expires_at  = gmdate( 'Y-m-d\TH:i:s\Z', time() + 90 * DAY_IN_SECONDS );
		foreach ( DemoData::lawyers() as $lawyer ) {
			$id = $this->create(
				$s->lawyer,
				$lawyer['title'],
				$lawyer['content'],
				array(
					'first_name'       => $lawyer['first_name'],
					'last_name'        => $lawyer['last_name'],
					'title'            => $lawyer['title_field'],
					'firm_id'          => $firms[ $lawyer['firm'] ],
					'country'          => 'US',
					'website'          => $lawyer['website'],
					'phone'            => $lawyer['phone'],
					'years_experience' => $lawyer['years_experience'],
					'rating'           => $lawyer['rating'],
					'review_count'     => $lawyer['review_count'],
					'bar_state'        => $lawyer['bar_state'],
					'bar_number'       => $lawyer['bar_number'],
					'bar_status'       => $lawyer['bar_status'],
					'education'        => $lawyer['education'],
					'languages'        => $lawyer['languages'],
					'awards'           => $lawyer['awards'],
				)
			);
			wp_set_object_terms( $id, array( $city ), Location::SLUG );
			wp_set_object_terms( $id, array( $practice ), PracticeArea::SLUG );

			foreach ( array( 'identity', 'license', 'bar_status' ) as $vtype ) {
				$status = 'failed' === $lawyer['verified'] && 'bar_status' === $vtype ? 'failed' : ( 'verified' === $lawyer['verified'] ? 'verified' : 'pending' );
				$this->create(
					$s->verification,
					sprintf( '%s — %s', $lawyer['title'], $vtype ),
					'',
					array(
						'entity_id'         => $id,
						'verification_type' => $vtype,
						'status'            => $status,
						'source_id'         => $sources['registry'],
						'source_url'        => 'https://example.com/demo/bar-registry',
						'verified_at'       => 'verified' === $status ? $verified_at : null,
						'expires_at'        => 'verified' === $status ? $expires_at : null,
						'verified_by'       => 'demo-seeder',
					)
				);
			}

			$claims = array(
				array( 'bar_status', $lawyer['bar_status'], 'registry', 'official_registry', 0.99 ),
				array( 'years_experience', $lawyer['years_experience'], 'registry', 'official_registry', 0.95 ),
				array( 'website', $lawyer['website'], 'website', 'official_website', 0.9 ),
				array( 'rating', $lawyer['rating'], 'reviews', 'review_platform', 0.8 ),
				array( 'review_count', $lawyer['review_count'], 'reviews', 'review_platform', 0.8 ),
			);
			foreach ( $claims as [ $field, $value, $source, $source_type, $confidence ] ) {
				$s->claims->insert(
					array(
						'entity_id'           => $id,
						'entity_type'         => 'lawyer',
						'field_name'          => $field,
						'value'               => $value,
						'source_id'           => $sources[ $source ],
						'source_url'          => DemoData::sources()[ $source ]['url'],
						'source_type'         => $source_type,
						'retrieved_at'        => $verified_at,
						'confidence'          => $confidence,
						'verification_status' => 'official_registry' === $source_type ? 'verified' : 'pending',
					)
				);
			}
		}//end foreach

		$ranking = $this->create(
			$s->ranking,
			'Best Personal Injury Lawyers in Miami, Florida (Demo)',
			DemoData::ranking_body(),
			array(
				'entity_type'  => 'lawyer',
				'min_entities' => 5,
				'max_entities' => 25,
				'summary'      => DemoData::RANKING_SUMMARY,
				'faq'          => DemoData::ranking_faq(),
				'reviewed_by'  => 'LexRanked Demo Editor',
				'reviewed_at'  => gmdate( 'Y-m-d' ),
			)
		);
		wp_set_object_terms( $ranking, array( $city ), Location::SLUG );
		wp_set_object_terms( $ranking, array( $practice ), PracticeArea::SLUG );

		TermContent::store(
			$city,
			array(
				'summary'     => DemoData::CITY_SUMMARY,
				'faq'         => DemoData::city_faq(),
				'reviewed_by' => 'LexRanked Demo Editor',
				'reviewed_at' => gmdate( 'Y-m-d' ),
			)
		);
		$article = $this->create(
			$s->article,
			DemoData::ARTICLE_TITLE,
			DemoData::article_body(),
			array(
				'related_ranking' => $ranking,
				'reviewed_by'     => 'LexRanked Demo Editor',
				'reviewed_at'     => gmdate( 'Y-m-d' ),
			)
		);
		wp_update_post(
			array(
				'ID'           => $article,
				'post_excerpt' => 'Demo content: how positions, scores, verification and sources work on a LexRanked ranking page.',
			)
		);

		$result = $s->runner->run_all();
		$this->seed_demo_commercial( $ranking, $city, $firms['coral'] );
		\WP_CLI::success(
			sprintf(
				'Demo data created: %d lawyers, %d firms, 1 ranking, 1 article, demo claims and paid placements (scored %d entities, calculated %d ranking). All records are flagged isDemo.',
				count( DemoData::lawyers() ),
				count( $firms ),
				$result['entities'],
				$result['rankings']
			)
		);
	}

	/**
	 * Demo claims and placements, so the labelled commercial blocks can be seen.
	 * They are attached to demo profiles only and removed by purge-demo.
	 *
	 * @param int $ranking Demo ranking ID.
	 * @param int $city    Demo city term ID.
	 * @param int $firm    Demo firm ID.
	 */
	private function seed_demo_commercial( int $ranking, int $city, int $firm ): void {
		$c      = $this->services->commercial;
		$lawyer = get_posts(
			array(
				'post_type'   => $this->services->lawyer->slug(),
				'title'       => DemoData::COMMERCIAL_LAWYER,
				'post_status' => 'publish',
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
		$lawyer = (int) ( $lawyer[0] ?? 0 );
		foreach ( array( array( $lawyer, 'lawyer', 'self' ), array( $firm, 'law_firm', 'firm_representative' ) ) as [ $id, $type, $role ] ) {
			$c->claims->insert(
				array(
					'entity_id'         => $id,
					'entity_type'       => $type,
					'status'            => 'approved',
					'claimant_name'     => 'Demo Claimant',
					'claimant_email'    => 'demo-claimant@example.com',
					'claimant_role'     => $role,
					'bar_state'         => 'lawyer' === $type ? 'FL' : '',
					'bar_number'        => 'lawyer' === $type ? (string) get_post_meta( $id, '_lr_bar_number', true ) : '',
					'message'           => 'Demo claim (fictional).',
					'email_verified_at' => gmdate( 'Y-m-d H:i:s' ),
					'identity_method'   => 'bar_record',
					'review_note'       => 'Demo data.',
					'reviewed_at'       => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}
		$period = array(
			'starts_at' => gmdate( 'Y-m-d' ),
			'ends_at'   => gmdate( 'Y-m-d', time() + 90 * DAY_IN_SECONDS ),
			'order_ref' => 'DEMO',
			'notes'     => 'Demo placement (not a real advertiser).',
		);
		$c->save_placement(
			$period + array(
				'product'     => 'sponsored',
				'entity_type' => 'lawyer',
				'entity_id'   => $lawyer,
				'ranking_id'  => $ranking,
			)
		);
		$c->save_placement(
			$period + array(
				'product'          => 'featured',
				'entity_type'      => 'law_firm',
				'entity_id'        => $firm,
				'location_term_id' => $city,
			)
		);
		$c->save_placement(
			$period + array(
				'product'         => 'premium',
				'entity_type'     => 'lawyer',
				'entity_id'       => $lawyer,
				'premium_message' => DemoData::PREMIUM_MESSAGE,
				'cta_url'         => 'https://example.com/demo/contact',
			)
		);
	}

	/**
	 * List profile claims (no contact details).
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : pending_email, pending_review, approved, rejected or expired.
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function claims( array $args, array $assoc_args ): void {
		unset( $args );
		$rows = $this->services->commercial->claims->list( $assoc_args['status'] ?? null, 200 );
		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				static fn( array $r ): array => array(
					'id'              => $r['claim_id'],
					'profile'         => get_the_title( (int) $r['entity_id'] ) . ' (#' . $r['entity_id'] . ')',
					'role'            => $r['claimant_role'],
					'status'          => $r['status'],
					'email_confirmed' => null === $r['email_verified_at'] ? 'no' : 'yes',
					'created'         => $r['created_at'],
				),
				$rows
			),
			array( 'id', 'profile', 'role', 'status', 'email_confirmed', 'created' )
		);
	}

	/**
	 * Approve or reject a profile claim.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Claim ID.
	 *
	 * [--approve]
	 * : Approve (requires --identity).
	 *
	 * [--reject]
	 * : Reject, or revoke an approved claim.
	 *
	 * [--identity=<method>]
	 * : How identity was checked: bar_record, phone_callback, firm_email or document.
	 *
	 * [--note=<note>]
	 * : Private note.
	 *
	 * @subcommand claim-review
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function claim_review( array $args, array $assoc_args ): void {
		$id = (int) ( $args[0] ?? 0 );
		try {
			if ( isset( $assoc_args['approve'] ) ) {
				$this->services->commercial->approve( $id, (string) ( $assoc_args['identity'] ?? '' ), (string) ( $assoc_args['note'] ?? '' ) );
				\WP_CLI::success( "Claim {$id} approved." );
			} elseif ( isset( $assoc_args['reject'] ) ) {
				$this->services->commercial->reject( $id, (string) ( $assoc_args['note'] ?? '' ) );
				\WP_CLI::success( "Claim {$id} rejected." );
			} else {
				\WP_CLI::error( 'Pass --approve or --reject.' );
			}
		} catch ( \LexRanked\Core\Commercial\CommercialException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Add a paid placement (always labelled; never affects scores or positions).
	 *
	 * ## OPTIONS
	 *
	 * --product=<product>
	 * : premium, featured or sponsored.
	 *
	 * --entity=<id>
	 * : Lawyer or firm ID.
	 *
	 * [--entity-type=<type>]
	 * : lawyer or law_firm.
	 * ---
	 * default: lawyer
	 * ---
	 *
	 * [--ranking=<id>]
	 * : Ranking ID (sponsored).
	 *
	 * [--location=<term_id>]
	 * : Location term ID (featured).
	 *
	 * [--practice-area=<term_id>]
	 * : Practice-area term ID (featured).
	 *
	 * [--starts=<date>]
	 * : Start date (UTC, YYYY-MM-DD). Default today.
	 *
	 * [--ends=<date>]
	 * : End date (exclusive). Default in 30 days.
	 *
	 * [--message=<text>]
	 * : Premium message.
	 *
	 * [--cta=<url>]
	 * : Premium call-to-action URL (https).
	 *
	 * [--order=<ref>]
	 * : Order reference (private).
	 *
	 * @subcommand placement-add
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function placement_add( array $args, array $assoc_args ): void {
		unset( $args );
		try {
			$id = $this->services->commercial->save_placement(
				array(
					'product'               => $assoc_args['product'] ?? '',
					'entity_type'           => $assoc_args['entity-type'] ?? 'lawyer',
					'entity_id'             => $assoc_args['entity'] ?? 0,
					'ranking_id'            => $assoc_args['ranking'] ?? 0,
					'location_term_id'      => $assoc_args['location'] ?? 0,
					'practice_area_term_id' => $assoc_args['practice-area'] ?? 0,
					'starts_at'             => $assoc_args['starts'] ?? gmdate( 'Y-m-d' ),
					'ends_at'               => $assoc_args['ends'] ?? gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
					'premium_message'       => $assoc_args['message'] ?? '',
					'cta_url'               => $assoc_args['cta'] ?? '',
					'order_ref'             => $assoc_args['order'] ?? '',
				)
			);
		} catch ( \LexRanked\Core\Schema\ValidationException $e ) {
			\WP_CLI::error( $e->field_key . ' ' . $e->reason . '.' );
		} catch ( \LexRanked\Core\Commercial\CommercialException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}//end try
		\WP_CLI::success( "Placement {$id} created." );
	}

	/**
	 * List placements.
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function placements( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				static fn( array $r ): array => array(
					'id'      => $r['placement_id'],
					'product' => $r['product'],
					'profile' => get_the_title( (int) $r['entity_id'] ) . ' (#' . $r['entity_id'] . ')',
					'ranking' => $r['ranking_id'],
					'term'    => max( (int) $r['location_term_id'], (int) $r['practice_area_term_id'] ),
					'period'  => substr( (string) $r['starts_at'], 0, 10 ) . ' – ' . substr( (string) $r['ends_at'], 0, 10 ),
					'status'  => \LexRanked\Core\Commercial\PlacementPolicy::is_live( $r, $now ) ? 'live' : $r['status'],
				),
				$this->services->commercial->placements->list( true )
			),
			array( 'id', 'product', 'profile', 'ranking', 'term', 'period', 'status' )
		);
	}

	/**
	 * Cancel a placement.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Placement ID.
	 *
	 * @subcommand placement-cancel
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function placement_cancel( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		try {
			$this->services->commercial->cancel_placement( (int) ( $args[0] ?? 0 ) );
		} catch ( \LexRanked\Core\Commercial\CommercialException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( 'Placement cancelled.' );
	}

	/**
	 * Run commercial maintenance now: expire links, erase closed claims' personal data, apply placement start/end.
	 *
	 * @subcommand commercial-sync
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function commercial_sync( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$this->services->commercial->hourly();
		\WP_CLI::success( 'Commercial statuses synchronised.' );
	}

	/**
	 * Recalculate scores and rankings with the deterministic engine.
	 *
	 * ## OPTIONS
	 *
	 * [--ranking=<id>]
	 * : Only this ranking (post ID).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lexranked recalculate
	 *     wp lexranked recalculate --ranking=42
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function recalculate( array $args, array $assoc_args ): void {
		unset( $args );
		$runner = $this->services->runner;
		if ( isset( $assoc_args['ranking'] ) ) {
			$result = $runner->run_ranking( (int) $assoc_args['ranking'] );
			\WP_CLI::success( sprintf( 'Ranking %d: %d entries (run %s).', (int) $assoc_args['ranking'], $result['entries'], $result['run_id'] ?? 'none' ) );
			return;
		}
		$result = $runner->run_all();
		\WP_CLI::success( sprintf( 'Scored %d entities and calculated %d rankings with %s.', $result['entities'], $result['rankings'], $runner->active_version()->id ) );
	}

	/**
	 * Recompute the latest run of every ranking from its stored inputs and
	 * confirm the stored scores are reproduced exactly.
	 *
	 * @subcommand verify-snapshots
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function verify_snapshots( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$runner  = $this->services->runner;
		$checked = 0;
		$failed  = 0;
		$ids     = array_merge(
			array( 0 ),
			array_map(
				'intval',
				get_posts(
					array(
						'post_type'      => $this->services->ranking->slug(),
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'fields'         => 'ids',
					)
				)
			)
		);
		foreach ( $ids as $ranking_id ) {
			foreach ( $this->services->snapshots->run_ids( $ranking_id, 0 === $ranking_id ? 2 : 1 ) as $run_id ) {
				$result   = $runner->verify_run( $run_id );
				$checked += $result['rows'];
				$failed  += count( $result['mismatches'] );
				foreach ( $result['mismatches'] as $entity_id ) {
					\WP_CLI::warning( sprintf( 'Run %s: entity %d does not reproduce.', $run_id, $entity_id ) );
				}
			}
		}
		if ( $failed > 0 ) {
			\WP_CLI::error( sprintf( '%d of %d snapshot rows did not reproduce.', $failed, $checked ) );
		}
		\WP_CLI::success( sprintf( 'All %d snapshot rows reproduce exactly from stored inputs.', $checked ) );
	}

	/**
	 * Create a research job.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : candidate_discovery | source_refresh | verification | ranking_recalculation
	 *
	 * [--params=<json>]
	 * : Parameters as a JSON object, e.g. '{"provider":"csv","dataset":"florida-pi"}'.
	 *
	 * [--title=<title>]
	 * : Title.
	 *
	 * [--location=<slug>]
	 * : Location term slug that scopes the job.
	 *
	 * [--practice-area=<slug>]
	 * : Practice-area term slug that scopes the job.
	 *
	 * [--porcelain]
	 * : Print only the job ID.
	 *
	 * @subcommand research-job
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function research_job( array $args, array $assoc_args ): void {
		$params = JobPolicy::params( $assoc_args['params'] ?? '' );
		if ( null === $params ) {
			\WP_CLI::error( '--params must be a JSON object.' );
		}
		$scope = array();
		foreach ( array(
			'location'      => Location::SLUG,
			'practice-area' => PracticeArea::SLUG,
		) as $flag => $taxonomy ) {
			$scope[ $flag ] = array();
			if ( isset( $assoc_args[ $flag ] ) ) {
				$term = get_term_by( 'slug', (string) $assoc_args[ $flag ], $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					\WP_CLI::error( sprintf( 'Unknown %s "%s".', $flag, (string) $assoc_args[ $flag ] ) );
				}
				$scope[ $flag ] = array( (int) $term->term_id );
			}
		}
		try {
			$id = $this->services->jobs->create( (string) ( $args[0] ?? '' ), (array) $params, (string) ( $assoc_args['title'] ?? '' ), $scope['location'], $scope['practice-area'] );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		if ( isset( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( (string) $id );
			return;
		}
		\WP_CLI::success( sprintf( 'Research job %d created (pending).', $id ) );
	}

	/**
	 * Show a research job (status, progress, log).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job ID.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand research-status
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function research_status( array $args, array $assoc_args ): void {
		$id = (int) ( $args[0] ?? 0 );
		try {
			$job = $this->services->jobs->view( $id );
		} catch ( JobException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$job['candidateCounts'] = $this->services->candidates->counts( $id );
		$job['log']             = $this->services->research_log->for_job( $id, 200 );
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		foreach ( array( 'jobType', 'status', 'processedCount', 'cursor', 'retryCount', 'nextRetryAt', 'lockedUntil', 'worker', 'error' ) as $key ) {
			\WP_CLI::line( sprintf( '%-15s %s', $key, is_scalar( $job[ $key ] ) ? (string) $job[ $key ] : '—' ) );
		}
		\WP_CLI::line( sprintf( '%-15s %s', 'stats', (string) wp_json_encode( $job['stats'] ) ) );
		\WP_CLI::line( sprintf( '%-15s %s', 'candidates', (string) wp_json_encode( array_filter( $job['candidateCounts'] ) ) ) );
		foreach ( $job['log'] as $entry ) {
			\WP_CLI::line( sprintf( '  %s %-7s %-10s %s', $entry['createdAt'], $entry['level'], $entry['stage'], $entry['message'] ) );
		}
	}

	/**
	 * Run due internal research jobs (verification expiry, ranking recalculation) now.
	 *
	 * @subcommand research-run
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function research_run( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$done = $this->services->jobs->run_internal( 20 );
		\WP_CLI::success( sprintf( 'Processed %d internal research job(s).', $done ) );
	}

	/**
	 * Rebuild the candidate-matching index and hash legacy claims.
	 *
	 * @subcommand research-reindex
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function research_reindex( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$claims   = $this->services->claims->backfill_hashes();
		$entities = $this->services->entity_index->reindex_all();
		\WP_CLI::success( sprintf( 'Indexed %d entities; hashed %d legacy claims.', $entities, $claims ) );
	}

	/**
	 * Operational health checks (exit code 1 when a check is critical).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function health( array $args, array $assoc_args ): void {
		unset( $args );
		$report = $this->services->health->report();
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $report['checks'], array( 'key', 'status', 'message' ) );
			\WP_CLI::line( 'Overall: ' . $report['status'] );
		}
		if ( 'critical' === $report['status'] ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Delete all demo data (posts flagged is_demo, their claims, and demo-created empty terms).
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * @subcommand purge-demo
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function purge_demo( array $args, array $assoc_args ): void {
		unset( $args );
		\WP_CLI::confirm( 'Delete all LexRanked demo data?', $assoc_args );
		$count = $this->purge();
		\WP_CLI::success( sprintf( 'Deleted %d demo records.', $count ) );
	}

	/**
	 * Purge implementation.
	 */
	private function purge(): int {
		$count = 0;
		// Content drafts written for demo rankings go with them.
		foreach ( get_posts(
			array(
				'post_type'      => $this->services->content_draft->slug(),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		) as $draft_id ) {
			$target = (int) get_post_meta( (int) $draft_id, (string) $this->services->content_draft->field( 'target_id' )?->meta_key(), true );
			if ( in_array( $target, $this->demo_ids( $this->services->ranking ), true ) ) {
				wp_delete_post( (int) $draft_id, true );
				++$count;
			}
		}
		foreach ( array_merge( $this->services->post_types(), array( $this->services->article ) ) as $type ) {
			$ids = $this->demo_ids( $type );
			$this->services->commercial->claims->delete_for( $ids );
			$this->services->commercial->placements->delete_for( $ids );
			foreach ( $ids as $id ) {
				$this->services->claims->delete_for_entity( $id );
				$this->services->snapshots->delete_for( $id );
				wp_delete_post( $id, true );
				++$count;
			}
		}
		foreach ( array( Location::SLUG, PracticeArea::SLUG ) as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'meta_key'   => self::TERM_DEMO_META,
					'meta_value' => '1',
					'orderby'    => 'parent',
					'order'      => 'DESC',
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$children = get_term_children( $term->term_id, $taxonomy );
				if ( 0 === (int) $term->count && ( ! is_array( $children ) || array() === $children ) ) {
					wp_delete_term( $term->term_id, $taxonomy );
				}
			}
		}
		return $count;
	}

	/**
	 * IDs of demo posts of a type (any status).
	 *
	 * @param PostType $type Post type.
	 * @return array<int, int>
	 */
	private function demo_ids( PostType $type ): array {
		$field = $type->field( 'is_demo' );
		if ( null === $field ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => $type->slug(),
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => $field->meta_key(),
					'meta_value'     => '1',
				)
			)
		);
	}

	/**
	 * Get or create a term; mark it as demo-created when new.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $name     Name.
	 * @param string $slug     Slug.
	 * @param int    $parent_id Parent term ID.
	 */
	private function term( string $taxonomy, string $name, string $slug, int $parent_id ): int {
		$existing = get_term_by( 'slug', $slug, $taxonomy );
		if ( $existing instanceof \WP_Term ) {
			return (int) $existing->term_id;
		}
		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'slug'   => $slug,
				'parent' => $parent_id,
			)
		);
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		update_term_meta( (int) $result['term_id'], self::TERM_DEMO_META, '1' );
		return (int) $result['term_id'];
	}

	/**
	 * Create a published post with fields.
	 *
	 * @param PostType             $type      Type.
	 * @param string               $title     Title.
	 * @param string               $content   Content.
	 * @param array<string, mixed> $fields    Editor fields.
	 * @param array<string, mixed> $system    System (read-only) fields.
	 */
	private function create( PostType $type, string $title, string $content, array $fields, array $system = array() ): int {
		$id = wp_insert_post(
			array(
				'post_type'    => $type->slug(),
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			\WP_CLI::error( $id->get_error_message() );
		}
		$fields['is_demo'] = true;
		$errors            = $this->services->entities->save_fields( (int) $id, $type, $fields + $system, true );
		if ( array() !== $errors ) {
			\WP_CLI::error( 'Invalid demo data: ' . implode( ' ', $errors ) );
		}
		return (int) $id;
	}
}
