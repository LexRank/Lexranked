<?php
/**
 * Page eligibility inputs from WordPress (Etap G).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Eligibility;

use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\Services;

/**
 * Collects the counts PageEligibility decides on (published entities, real
 * vs demo, verified, evidence coverage) and evaluates each page type.
 */
final class EligibilityService {

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Counts over a set of lawyer or firm posts.
	 *
	 * @param string          $type   lawyer|law_firm.
	 * @param array<int, int> $wp_ids Published post IDs.
	 * @return array{entities: int, real: int, verified: int, coverage: float}
	 */
	public function entity_stats( string $type, array $wp_ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $wp_ids ) ) );
		if ( array() === $ids ) {
			return array(
				'entities' => 0,
				'real'     => 0,
				'verified' => 0,
				'coverage' => 0.0,
			);
		}
		$s        = $this->services;
		$demo_key = ( 'law_firm' === $type ? $s->law_firm : $s->lawyer )->field( 'is_demo' )?->meta_key();
		$real     = 0;
		foreach ( $ids as $id ) {
			$real += null !== $demo_key && '1' === (string) get_post_meta( $id, $demo_key, true ) ? 0 : 1;
		}
		$policy   = $s->settings->verification_policy( $type );
		$now      = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$records  = $s->verifications->for_entities( $ids );
		$verified = 0;
		foreach ( $ids as $id ) {
			$verified += 'verified' === $policy->evaluate( $records[ $id ] ?? array(), $now )['status'] ? 1 : 0;
		}
		$coverage = $s->quality->coverage( $ids );
		return array(
			'entities' => count( $ids ),
			'real'     => $real,
			'verified' => $verified,
			'coverage' => array_sum( array_map( static fn( int $id ): float => $coverage[ $id ] ?? 0.0, $ids ) ) / count( $ids ),
		);
	}

	/**
	 * A state, city or practice-area hub: published lawyers in the term (including child terms).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 * @return array<string, mixed>
	 */
	public function hub( string $taxonomy, int $term_id ): array {
		$ids = get_posts(
			array(
				'post_type'        => Lawyer::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => 2000,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'tax_query'        => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => array( $term_id ),
					),
				),
			)
		);
		return PageEligibility::evaluate( 'hub', $this->entity_stats( 'lawyer', array_map( 'intval', $ids ) ) );
	}

	/**
	 * A ranking page, from its visible entries.
	 *
	 * @param array<string, mixed>             $record  Ranking record.
	 * @param array<int, array<string, mixed>> $entries Entries (RankingMapper).
	 * @param int                              $min     Minimum entries.
	 * @param array<string, mixed>|null        $context Context DTO of a contextual ranking.
	 * @return array<string, mixed>
	 */
	public function ranking( array $record, array $entries, int $min, ?array $context ): array {
		$type  = 'law_firm' === ( $record['fields']['entity_type'] ?? 'lawyer' ) ? 'law_firm' : 'lawyer';
		$ids   = array_map( static fn( array $e ): int => (int) $e['entity']['id'], $entries );
		$stats = $this->entity_stats( $type, $ids );
		// A demo ranking is never real data, whatever its entries are.
		$stats['real'] = ! empty( $record['fields']['is_demo'] ) || 0 === $stats['real'] ? 0 : 1;
		return PageEligibility::evaluate( 'ranking', $stats, array( 'entities' => $min ), $context['eligibility'] ?? null );
	}

	/**
	 * Lawyer or firm profiles, batched: compact eligibility keyed by post ID.
	 *
	 * @param string          $type   lawyer|law_firm.
	 * @param array<int, int> $wp_ids Post IDs.
	 * @return array<int, array<string, mixed>>
	 */
	public function profiles( string $type, array $wp_ids ): array {
		$s        = $this->services;
		$demo_key = ( 'law_firm' === $type ? $s->law_firm : $s->lawyer )->field( 'is_demo' )?->meta_key();
		$coverage = $s->quality->coverage( $wp_ids );
		$out      = array();
		foreach ( array_map( 'intval', $wp_ids ) as $id ) {
			$out[ $id ] = PageEligibility::evaluate(
				'profile',
				array(
					'real'     => null === $demo_key || '1' !== (string) get_post_meta( $id, $demo_key, true ),
					'coverage' => $coverage[ $id ] ?? 0.0,
				)
			);
		}
		return $out;
	}

	/**
	 * Post type slug for an entity type.
	 *
	 * @param string $type lawyer|law_firm.
	 */
	public static function post_type( string $type ): string {
		return 'law_firm' === $type ? LawFirm::SLUG : Lawyer::SLUG;
	}
}
