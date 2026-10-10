<?php
/**
 * Generated text for practice-area, city and state hubs.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Content;

use LexRanked\Core\Plugin;
use LexRanked\Core\PostTypes\Article;
use LexRanked\Core\Services;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Writes hub text with HubContentBuilder from the published rankings (the
 * cached public list) and the state knowledge pack, and keeps it in step
 * after every recalculation. A hub gets generated text when it has none or
 * its text is already generated; text an editor writes is never touched.
 */
final class HubContentService {

	/** Term meta: contract version of generated text; absent once an editor writes the text. */
	public const SOURCE_META = '_lr_content_generated';

	/** Term meta: hash of the generated text, to skip unchanged rewrites. */
	public const HASH_META = '_lr_content_hash';

	/**
	 * Published rankings, loaded once per request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $rankings = null;

	/**
	 * Published article paths, loaded once per request.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $articles = null;

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Hooks: hub text follows every recalculation (after ranking text).
	 */
	public function register(): void {
		add_action( 'lexranked_scores_updated', array( $this, 'refresh_all' ), 20 );
	}

	/**
	 * Generate text for one hub term, or null when it has nothing to describe.
	 *
	 * @param \WP_Term $term Practice area or location term.
	 * @return array<string, mixed>|null
	 */
	public function generate( \WP_Term $term ): ?array {
		$today   = gmdate( 'Y-m-d' );
		$checked = gmdate( 'F Y' );
		$all     = $this->rankings();
		if ( PracticeArea::SLUG === $term->taxonomy ) {
			$own  = array_values( array_filter( $all, static fn( array $r ): bool => ( $r['practiceArea']['slug'] ?? '' ) === $term->slug && empty( $r['context'] ) ) );
			$pack = $this->pack_for( $own );
			if ( null === $pack ) {
				return null;
			}
			$rows = array_map(
				static fn( array $r ): array => array(
					'path'    => (string) $r['path'],
					'city'    => isset( $r['location']['city'] ) && '' !== (string) ( $r['location']['citySlug'] ?? '' ) ? (string) $r['location']['city'] : null,
					'entries' => (int) $r['entryCount'],
				),
				$own
			);
			return HubContentBuilder::area(
				$pack,
				array(
					'area_slug' => $term->slug,
					'area_name' => $term->name,
					'rankings'  => $rows,
				),
				$checked,
				$today
			);
		}//end if
		if ( Location::SLUG !== $term->taxonomy ) {
			return null;
		}
		if ( 0 === (int) $term->parent ) {
			return $this->state( $term, $all, $checked, $today );
		}
		$own  = array_values( array_filter( $all, static fn( array $r ): bool => ( $r['location']['citySlug'] ?? '' ) === $term->slug ) );
		$pack = $this->pack_for( $own );
		if ( null === $pack ) {
			return null;
		}
		$rows = array();
		foreach ( $own as $r ) {
			$language = 'language' === ( $r['context']['type'] ?? '' ) ? ucfirst( (string) $r['context']['value'] ) : null;
			if ( ! empty( $r['context'] ) && null === $language ) {
				continue;
			}
			$rows[] = array(
				'path'      => (string) $r['path'],
				'area'      => (string) $r['practiceArea']['name'],
				'area_slug' => (string) $r['practiceArea']['slug'],
				'entries'   => (int) $r['entryCount'],
				'language'  => $language,
			);
		}
		return HubContentBuilder::city(
			$pack,
			array(
				'city_slug' => $term->slug,
				'city_name' => $term->name,
				'lawyers'   => $this->lawyer_count( $term ),
				'rankings'  => $rows,
				'statewide' => $this->statewide_paths( $all, (string) ( $own[0]['location']['stateSlug'] ?? '' ) ),
			),
			$checked,
			$today
		);
	}

	/**
	 * Store generated text on a hub and mark it as generated.
	 *
	 * @param int                  $term_id Term ID.
	 * @param array<string, mixed> $content Output of generate().
	 */
	public function apply( int $term_id, array $content ): void {
		TermContent::store(
			$term_id,
			array(
				'summary'     => $content['summary'],
				'body'        => $content['body'],
				'faq'         => $content['faq'],
				'reviewed_by' => $content['reviewed_by'],
				'reviewed_at' => $content['reviewed_at'],
			)
		);
		update_term_meta( $term_id, self::SOURCE_META, HubContentBuilder::VERSION );
		update_term_meta( $term_id, self::HASH_META, self::hash( $content ) );
	}

	/**
	 * Write or refresh generated text on every hub that has none or has
	 * generated text. Text an editor wrote is never touched.
	 *
	 * @return int Hubs written.
	 */
	public function refresh_all(): int {
		$this->rankings = null;
		$this->articles = null;
		$terms          = get_terms(
			array(
				'taxonomy'   => array( PracticeArea::SLUG, Location::SLUG ),
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return 0;
		}
		$changed = 0;
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term || ! $this->writable( $term->term_id ) ) {
				continue;
			}
			$content = $this->generate( $term );
			if ( null === $content || self::hash( $content ) === get_term_meta( $term->term_id, self::HASH_META, true ) ) {
				continue;
			}
			$this->apply( $term->term_id, $content );
			++$changed;
		}
		if ( $changed > 0 ) {
			$this->services->revalidator->on_term();
		}
		return $changed;
	}

	/**
	 * An editor wrote the text: stop regenerating it.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function release( int $term_id ): void {
		delete_term_meta( $term_id, self::SOURCE_META );
		delete_term_meta( $term_id, self::HASH_META );
	}

	/**
	 * Whether a hub's text is generated.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function is_generated( int $term_id ): bool {
		return '' !== (string) get_term_meta( $term_id, self::SOURCE_META, true );
	}

	/**
	 * A hub may receive generated text: it has none, or its text is generated.
	 *
	 * @param int $term_id Term ID.
	 */
	private function writable( int $term_id ): bool {
		if ( self::is_generated( $term_id ) ) {
			return true;
		}
		$c = TermContent::raw( $term_id );
		return '' === $c['summary'] && '' === trim( $c['body'] ) && array() === $c['faq'];
	}

	/**
	 * State hub text.
	 *
	 * @param \WP_Term                         $term    State term.
	 * @param array<int, array<string, mixed>> $all     Published rankings.
	 * @param string                           $checked Month checked.
	 * @param string                           $today   Review date.
	 * @return array<string, mixed>|null
	 */
	private function state( \WP_Term $term, array $all, string $checked, string $today ): ?array {
		$own  = array_values( array_filter( $all, static fn( array $r ): bool => ( $r['location']['stateSlug'] ?? '' ) === $term->slug ) );
		$pack = $this->pack_for( $own );
		if ( null === $pack ) {
			return null;
		}
		$wide   = array();
		$cities = array();
		foreach ( $own as $r ) {
			$city = (string) ( $r['location']['citySlug'] ?? '' );
			if ( '' === $city ) {
				if ( empty( $r['context'] ) ) {
					$wide[] = array(
						'path'    => (string) $r['path'],
						'area'    => (string) $r['practiceArea']['name'],
						'entries' => (int) $r['entryCount'],
					);
				}
				continue;
			}
			if ( ! isset( $cities[ $city ] ) ) {
				$cities[ $city ] = array(
					'path'     => '/cities/' . $city . '/',
					'city'     => (string) $r['location']['city'],
					'rankings' => 0,
				);
			}
			++$cities[ $city ]['rankings'];
		}//end foreach
		return HubContentBuilder::state(
			$pack,
			array(
				'lawyers'   => $this->lawyer_count( $term ),
				'statewide' => $wide,
				'cities'    => array_values( $cities ),
			),
			$checked,
			$today
		);
	}

	/**
	 * Statewide ranking path by practice area slug.
	 *
	 * @param array<int, array<string, mixed>> $all   Published rankings.
	 * @param string                           $state State slug.
	 * @return array<string, string>
	 */
	private function statewide_paths( array $all, string $state ): array {
		$out = array();
		foreach ( $all as $r ) {
			if ( '' === (string) ( $r['location']['citySlug'] ?? '' ) && empty( $r['context'] ) && ( $r['location']['stateSlug'] ?? '' ) === $state ) {
				$out[ (string) $r['practiceArea']['slug'] ] = (string) $r['path'];
			}
		}
		return $out;
	}

	/**
	 * Knowledge pack of the state the rankings belong to.
	 *
	 * @param array<int, array<string, mixed>> $rankings Rankings.
	 * @return array<string, mixed>|null
	 */
	private function pack_for( array $rankings ): ?array {
		$code = (string) ( $rankings[0]['location']['stateCode'] ?? '' );
		$pack = '' === $code ? null : $this->services->ranking_content->pack( $code );
		if ( null === $pack ) {
			return null;
		}
		// Link only guides that are published, so a hub never points at a missing page.
		$pack['articles'] = $this->article_paths();
		return $pack;
	}

	/**
	 * Paths of published articles, loaded once per request.
	 *
	 * @return array<int, string>
	 */
	private function article_paths(): array {
		if ( null === $this->articles ) {
			$slugs          = get_posts(
				array(
					'post_type'        => Article::SLUG,
					'post_status'      => 'publish',
					'posts_per_page'   => 1000,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => false,
				)
			);
			$this->articles = array_map( static fn( $id ): string => '/articles/' . get_post_field( 'post_name', (int) $id ) . '/', $slugs );
		}
		return $this->articles;
	}

	/**
	 * Published lawyers on a location term (the hub's own count).
	 *
	 * @param \WP_Term $term Term.
	 */
	private function lawyer_count( \WP_Term $term ): int {
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . ( 0 === (int) $term->parent ? '/states' : '/cities' ) ) );
		foreach ( (array) $response->get_data() as $row ) {
			if ( is_array( $row ) && ( $row['slug'] ?? '' ) === $term->slug ) {
				return (int) ( $row['lawyerCount'] ?? 0 );
			}
		}
		return 0;
	}

	/**
	 * Every published, non-thin ranking from the public (cached) list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rankings(): array {
		if ( null !== $this->rankings ) {
			return $this->rankings;
		}
		$out  = array();
		$page = 1;
		do {
			$request = new \WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . '/rankings' );
			$request->set_query_params(
				array(
					'per_page' => 100,
					'page'     => $page,
				)
			);
			$rows  = (array) rest_do_request( $request )->get_data();
			$count = count( $rows );
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && empty( $row['isThin'] ) && ! empty( $row['path'] ) ) {
					$out[] = $row;
				}
			}
			++$page;
		} while ( 100 === $count && $page <= 20 );
		$this->rankings = $out;
		return $out;
	}

	/**
	 * Hash of the parts that change between runs (not the review date).
	 *
	 * @param array<string, mixed> $content Content.
	 */
	private static function hash( array $content ): string {
		return md5( (string) wp_json_encode( array( $content['summary'], $content['body'], $content['faq'] ) ) );
	}
}
