<?php
/**
 * Generated ranking text: written before a ranking is published and kept in
 * step with its positions until an editor takes the text over.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Content;

use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\Ranking\RankingQualifier;
use LexRanked\Core\REST\DTO\LocationMapper;
use LexRanked\Core\Services;

/**
 * Builds and stores ranking text from the ranked lawyers' facts and the
 * state knowledge pack (src/Content/knowledge/{STATE}.json).
 */
final class RankingContentService {

	/** Post meta: contract version of generated text; absent once an editor writes the text. */
	public const SOURCE_META = '_lr_content_generated';

	/** Post meta: hash of the generated text, to skip unchanged rewrites. */
	public const HASH_META = '_lr_content_hash';

	/**
	 * Loaded knowledge packs by state code.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $packs = array();

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Hooks: generated text follows every recalculation.
	 */
	public function register(): void {
		add_action( 'lexranked_scores_updated', array( $this, 'refresh_all' ) );
	}

	/**
	 * Calculate a new ranking and write its text. False when complete text cannot
	 * be written (no knowledge for the state, practice area or city, or no entries).
	 *
	 * @param int $ranking_id Ranking post ID.
	 */
	public function prepare( int $ranking_id ): bool {
		$this->services->runner->run_ranking( $ranking_id );
		$content = $this->generate( $ranking_id );
		if ( null === $content ) {
			return false;
		}
		$this->apply( $ranking_id, $content );
		return true;
	}

	/**
	 * Text for a ranking from its latest calculation, or null.
	 *
	 * @param int $ranking_id Ranking post ID.
	 * @return array{summary: string, body: string, faq: array<int, array{question: string, answer: string}>, reviewed_by: string, reviewed_at: string}|null
	 */
	public function generate( int $ranking_id ): ?array {
		$post = get_post( $ranking_id );
		if ( ! $post instanceof \WP_Post || Ranking::SLUG !== $post->post_type ) {
			return null;
		}
		$record    = $this->services->entities->record( $post, $this->services->ranking );
		$location  = LocationMapper::from_terms( $record['locations'] );
		$practice  = RankingQualifier::primary_practice( $record['practice_areas'], null );
		$fields    = $record['fields'];
		$qualifier = RankingQualifier::for_record( $record );
		// Ordinary rankings and language rankings; case and client types are written by editors.
		if ( null === $location || null === $location['citySlug'] || null === $location['stateCode'] || null === $practice || ( null !== $qualifier && RankingQualifier::LANGUAGE !== $qualifier->type ) ) {
			return null;
		}
		if ( null === $qualifier && ! in_array( $fields['context_type'] ?? null, array( null, '' ), true ) ) {
			return null;
		}
		$pack = $this->pack( (string) $location['stateCode'] );
		$runs = $this->services->snapshots->run_ids( $ranking_id );
		if ( null === $pack || array() === $runs ) {
			return null;
		}
		$type   = 'law_firm' === ( $fields['entity_type'] ?? null ) ? 'law_firm' : 'lawyer';
		$people = array();
		foreach ( $this->services->snapshots->run_rows( $runs[0] ) as $row ) {
			$people[] = self::person( $this->services->facts->for_entity( $type, (int) $row['entity_id'] ) );
		}
		return RankingContentBuilder::build(
			$pack,
			array(
				'area_slug'   => (string) $practice['slug'],
				'area_name'   => (string) $practice['name'],
				'city_slug'   => (string) $location['citySlug'],
				'city_name'   => (string) $location['city'],
				'entity_type' => $type,
			) + ( null === $qualifier ? array() : array(
				'language'    => ucwords( str_replace( '-', ' ', $qualifier->value ) ),
				'parent_path' => sprintf( '/rankings/%s/%s/%s/', $location['stateSlug'], $location['citySlug'], $practice['slug'] ),
			) ),
			$people,
			gmdate( 'F Y' ),
			gmdate( 'Y-m-d' )
		);
	}

	/**
	 * Store generated text on a ranking and mark it as generated.
	 *
	 * @param int                  $ranking_id Ranking post ID.
	 * @param array<string, mixed> $content    Output of generate().
	 */
	public function apply( int $ranking_id, array $content ): void {
		$this->services->entities->save_fields(
			$ranking_id,
			$this->services->ranking,
			array(
				'summary'     => $content['summary'],
				'faq'         => $content['faq'],
				'reviewed_by' => $content['reviewed_by'],
				'reviewed_at' => $content['reviewed_at'],
			)
		);
		// Text does not move scores: save without scheduling another recalculation.
		$hook     = 'save_post_' . Ranking::SLUG;
		$callback = array( $this->services->runner, 'schedule_soon' );
		$had      = false !== has_action( $hook, $callback );
		remove_action( $hook, $callback );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $ranking_id,
					'post_content' => wp_kses_post( (string) $content['body'] ),
				)
			)
		);
		if ( $had ) {
			add_action( $hook, $callback );
		}
		update_post_meta( $ranking_id, self::SOURCE_META, RankingContentBuilder::VERSION );
		update_post_meta( $ranking_id, self::HASH_META, self::hash( $content ) );
	}

	/**
	 * Rewrite generated text whose figures changed after a recalculation.
	 * Text an editor wrote is never touched.
	 *
	 * @return int Rankings rewritten.
	 */
	public function refresh_all(): int {
		$ids     = get_posts(
			array(
				'post_type'        => Ranking::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => 500,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed meta key on a short list.
				'meta_key'         => self::SOURCE_META,
			)
		);
		$changed = 0;
		foreach ( $ids as $id ) {
			$content = $this->generate( (int) $id );
			if ( null === $content || self::hash( $content ) === get_post_meta( (int) $id, self::HASH_META, true ) ) {
				continue;
			}
			$this->apply( (int) $id, $content );
			++$changed;
		}
		return $changed;
	}

	/**
	 * An editor wrote the text: stop regenerating it.
	 *
	 * @param int $ranking_id Ranking post ID.
	 */
	public function release( int $ranking_id ): void {
		delete_post_meta( $ranking_id, self::SOURCE_META );
		delete_post_meta( $ranking_id, self::HASH_META );
	}

	/**
	 * Whether a ranking's text is generated.
	 *
	 * @param int $ranking_id Ranking post ID.
	 */
	public function is_generated( int $ranking_id ): bool {
		return '' !== (string) get_post_meta( $ranking_id, self::SOURCE_META, true );
	}

	/**
	 * Knowledge pack for a state, or null.
	 *
	 * @param string $code Two-letter state code.
	 * @return array<string, mixed>|null
	 */
	public function pack( string $code ): ?array {
		$code = strtoupper( $code );
		if ( ! array_key_exists( $code, $this->packs ) ) {
			$this->packs[ $code ] = self::load_pack( $code );
		}
		return $this->packs[ $code ];
	}

	/**
	 * Read a pack file.
	 *
	 * @param string $code Two-letter state code.
	 * @return array<string, mixed>|null
	 */
	public static function load_pack( string $code ): ?array {
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
			return null;
		}
		$file = __DIR__ . '/knowledge/' . $code . '.json';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Builder input from an entity's facts (facts in conflict are left out).
	 *
	 * @param array<string, array<string, mixed>> $facts Facts by attribute.
	 * @return array{years: int|null, languages: array<int, string>, awards: array<int, string>, schools: array<int, string>}
	 */
	public static function person( array $facts ): array {
		$value = static function ( string $key ) use ( $facts ): mixed {
			$fact = $facts[ $key ] ?? null;
			return null === $fact || 'conflict' === ( $fact['status'] ?? null ) ? null : $fact['value'];
		};
		$names = static function ( mixed $items, string $key ): array {
			$out = array();
			foreach ( is_array( $items ) ? $items : array() as $item ) {
				$name = is_array( $item ) ? ( $item[ $key ] ?? '' ) : $item;
				if ( is_string( $name ) && '' !== $name ) {
					$out[] = $name;
				}
			}
			return $out;
		};
		$years = $value( 'years_experience' );
		return array(
			'years'     => is_numeric( $years ) ? (int) $years : null,
			'languages' => $names( $value( 'languages' ), 'name' ),
			'awards'    => $names( $value( 'awards' ), 'name' ),
			'schools'   => $names( $value( 'education' ), 'institution' ),
		);
	}

	/**
	 * Hash of the text, without the review date.
	 *
	 * @param array<string, mixed> $content Content.
	 */
	private static function hash( array $content ): string {
		return md5( (string) wp_json_encode( array( $content['summary'], $content['body'], $content['faq'] ) ) );
	}
}
