<?php
/**
 * WP-CLI commands.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\CLI;

use LexRanked\Core\Database\Installer;
use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\PostType;
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

		$result = $s->runner->run_all();
		\WP_CLI::success(
			sprintf(
				'Demo data created: %d lawyers, %d firms, 1 ranking (scored %d entities, calculated %d ranking). All records are flagged isDemo.',
				count( DemoData::lawyers() ),
				count( $firms ),
				$result['entities'],
				$result['rankings']
			)
		);
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
		foreach ( $this->services->post_types() as $type ) {
			foreach ( $this->demo_ids( $type ) as $id ) {
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
