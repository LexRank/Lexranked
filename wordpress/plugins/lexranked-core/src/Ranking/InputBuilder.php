<?php
/**
 * Builds scoring inputs from stored data.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\Services;

/**
 * Turns entity records + evidence + verification into EntityInput objects.
 * The commercial status field is never read here.
 */
final class InputBuilder {

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Inputs for a batch of posts of one type.
	 *
	 * @param array<int, \WP_Post> $posts Posts (all lawyers or all firms).
	 * @param \DateTimeImmutable   $as_of Calculation time (verification expiry is evaluated at this instant).
	 * @return array<int, EntityInput>
	 */
	public function build( array $posts, \DateTimeImmutable $as_of ): array {
		if ( array() === $posts ) {
			return array();
		}
		$s       = $this->services;
		$is_firm = LawFirm::SLUG === $posts[0]->post_type;
		$type    = $is_firm ? $s->law_firm : $s->lawyer;
		$policy  = $s->settings->verification_policy( $is_firm ? 'law_firm' : 'lawyer' );
		$ver_map = $s->verifications->for_entities( array_map( static fn( \WP_Post $p ): int => (int) $p->ID, $posts ) );
		$inputs  = array();

		foreach ( $posts as $post ) {
			$record           = $s->entities->record( $post, $type );
			$f                = $record['fields'];
			$verification     = $policy->evaluate( $ver_map[ $post->ID ] ?? array(), $as_of );
			$claims           = $s->claims->for_entity( $is_firm ? 'law_firm' : 'lawyer', (int) $post->ID );
			[ $city, $state ] = self::location( $record['locations'] );
			$practice         = array_column( $record['practice_areas'], 'slug' );

			$present = array_keys(
				array_filter(
					array(
						'rating'           => null !== $f['rating'],
						'review_count'     => null !== $f['review_count'],
						'years_experience' => ! $is_firm && null !== $f['years_experience'],
						'bar_status'       => ! $is_firm && null !== $f['bar_status'],
						'website'          => null !== $f['website'],
						'practice_areas'   => array() !== $practice,
						'location'         => null !== $city || null !== $state,
					)
				)
			);
			$sourced = array();
			foreach ( $claims as $claim ) {
				$field     = in_array( $claim['field_name'], array( 'city', 'state' ), true ) ? 'location' : $claim['field_name'];
				$sourced[] = $field;
			}

			$years        = $is_firm ? $this->firm_max_years( (int) $post->ID ) : $f['years_experience'];
			$lawyer_count = $is_firm ? count( $s->presenter->firm_lawyer_posts( (int) $post->ID ) ) : 0;

			$inputs[] = new EntityInput(
				entity_type: $is_firm ? 'law_firm' : 'lawyer',
				entity_id: (int) $post->ID,
				rating: $f['rating'],
				review_count: $f['review_count'],
				years_experience: $years,
				awards_count: $is_firm ? 0 : count( $f['awards'] ),
				education_count: $is_firm ? 0 : count( $f['education'] ),
				bar_status: $is_firm ? null : $f['bar_status'],
				practice_areas: $practice,
				city: $city,
				state: $state,
				verification_status: (string) $verification['status'],
				verification_checks: $verification['types'],
				present_fields: $present,
				sourced_fields: array_values( array_unique( array_intersect( $sourced, ScoreCalculator::KEY_FIELDS ) ) ),
				sourced_facts: count( $claims ),
				lawyer_count: $lawyer_count,
			);
		}//end foreach
		return $inputs;
	}

	/**
	 * City and state slugs from location terms.
	 *
	 * @param array<int, array<string, mixed>> $terms Location terms (+ parents).
	 * @return array{0: string|null, 1: string|null}
	 */
	public static function location( array $terms ): array {
		$by_id = array_column( $terms, null, 'id' );
		ksort( $by_id );
		foreach ( $by_id as $term ) {
			if ( 0 !== $term['parent'] ) {
				return array( $term['slug'], $by_id[ $term['parent'] ]['slug'] ?? null );
			}
		}
		foreach ( $by_id as $term ) {
			return array( null, $term['slug'] );
		}
		return array( null, null );
	}

	/**
	 * Firm experience: the longest-practising profiled lawyer (null if unknown).
	 *
	 * @param int $firm_id Firm ID.
	 */
	private function firm_max_years( int $firm_id ): ?int {
		$years = array();
		foreach ( $this->services->presenter->firm_lawyer_posts( $firm_id ) as $lawyer ) {
			$value = get_post_meta( $lawyer->ID, (string) $this->services->lawyer->field( 'years_experience' )?->meta_key(), true );
			if ( '' !== $value ) {
				$years[] = (int) $value;
			}
		}
		return array() === $years ? null : max( $years );
	}
}
