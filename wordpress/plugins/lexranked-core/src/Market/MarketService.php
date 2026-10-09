<?php
/**
 * Market statistics from WordPress (Etap I).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Market;

use LexRanked\Core\Content\RankingContentService;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\Services;
use LexRanked\Core\Taxonomies\Location;
use LexRanked\Core\Taxonomies\PracticeArea;

/**
 * Collects the published lawyers and firms of a market (location and/or
 * practice area), their facts and verification status, and hands them to
 * MarketStatistics.
 */
final class MarketService {

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Statistics for a scope.
	 *
	 * @param \WP_Term|null $location Location term (state or city, children included).
	 * @param \WP_Term|null $practice Practice-area term (sub-areas included).
	 * @return array<string, mixed>
	 */
	public function for_scope( ?\WP_Term $location, ?\WP_Term $practice ): array {
		$tax = array();
		if ( null !== $location ) {
			$tax[] = array(
				'taxonomy' => Location::SLUG,
				'field'    => 'term_id',
				'terms'    => array( (int) $location->term_id ),
			);
		}
		if ( null !== $practice ) {
			$tax[] = array(
				'taxonomy' => PracticeArea::SLUG,
				'field'    => 'term_id',
				'terms'    => array( (int) $practice->term_id ),
			);
		}
		$now      = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$entities = array();
		foreach ( array(
			'lawyer'   => Lawyer::SLUG,
			'law_firm' => LawFirm::SLUG,
		) as $type => $post_type ) {
			$ids = array_map(
				'intval',
				get_posts(
					array(
						'post_type'        => $post_type,
						'post_status'      => 'publish',
						'posts_per_page'   => 5000,
						'fields'           => 'ids',
						'no_found_rows'    => true,
						'suppress_filters' => false,
						'tax_query'        => array() === $tax ? array() : array_merge( array( 'relation' => 'AND' ), $tax ),
					)
				)
			);
			if ( array() === $ids ) {
				continue;
			}
			$s        = $this->services;
			$policy   = $s->settings->verification_policy( $type );
			$records  = $s->verifications->for_entities( $ids );
			$demo_key = ( 'law_firm' === $type ? $s->law_firm : $s->lawyer )->field( 'is_demo' )?->meta_key();
			foreach ( $ids as $id ) {
				$verification = $policy->evaluate( $records[ $id ] ?? array(), $now );
				$entities[]   = array(
					'type'        => $type,
					'verified'    => 'verified' === $verification['status'],
					'verified_at' => $verification['verified_at'] ?? null,
					'is_demo'     => null !== $demo_key && '1' === (string) get_post_meta( $id, $demo_key, true ),
					'facts'       => $s->facts->for_entity( $type, $id ),
				);
			}
		}//end foreach

		$names = array();
		$terms = get_terms(
			array(
				'taxonomy'   => PracticeArea::SLUG,
				'hide_empty' => false,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$names[ $term->slug ] = $term->name;
		}
		return MarketStatistics::compute( $entities, $names, $now->format( 'Y-m-d\TH:i:s\Z' ) );
	}
	/**
	 * Statewide figures for the data pages: every published lawyer in the state.
	 *
	 * @param \WP_Term $state State term.
	 * @return array<string, mixed>
	 */
	public function for_state( \WP_Term $state ): array {
		$posts  = get_posts(
			array(
				'post_type'              => Lawyer::SLUG,
				'post_status'            => 'publish',
				'posts_per_page'         => 10000,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'suppress_filters'       => false,
				'tax_query'              => array(
					array(
						'taxonomy' => Location::SLUG,
						'field'    => 'term_id',
						'terms'    => array( (int) $state->term_id ),
					),
				),
			)
		);
		$people = array();
		foreach ( $posts as $post ) {
			$city = null;
			foreach ( (array) get_the_terms( $post, Location::SLUG ) as $term ) {
				if ( $term instanceof \WP_Term && 0 !== (int) $term->parent ) {
					$city = $term;
					break;
				}
			}
			$areas = array();
			foreach ( (array) get_the_terms( $post, PracticeArea::SLUG ) as $term ) {
				if ( $term instanceof \WP_Term ) {
					$areas[] = array( $term->slug, $term->name );
				}
			}
			$people[] = array(
				'city_slug' => $city?->slug,
				'city_name' => $city?->name,
				'areas'     => $areas,
			) + RankingContentService::person( $this->services->facts->for_entity( 'lawyer', (int) $post->ID ) );
		}
		return StateData::compute( $people, gmdate( 'Y-m-d\TH:i:s\Z' ) );
	}
}
