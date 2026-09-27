<?php
/**
 * Ranking DTO mapping.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST\DTO;

use LexRanked\Core\Ranking\RankingExplainer;
use LexRanked\Core\Ranking\RankingQualifier;

/**
 * Maps ranking definitions and ordered entries.
 */
final class RankingMapper {

	public const DEFAULT_MAX = 25;

	/**
	 * Entries from a snapshot run (Phase 4 engine output).
	 *
	 * Rows whose entity is no longer published are skipped and positions are
	 * compacted, keeping the stored order. Movement compares with the previous
	 * run (positive = moved up; null = new entry or first run).
	 *
	 * @param array<int, array<string, mixed>> $rows      Snapshot rows ordered by position.
	 * @param array<int, array<string, mixed>> $summaries Entity summary DTOs keyed by entity ID.
	 * @param array<int, int>|null             $previous  Previous run: entity ID => position, or null.
	 * @param array<int, array<string, mixed>> $previous_rows Previous run's rows keyed by entity ID (for change explanations).
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries_from_snapshots( array $rows, array $summaries, ?array $previous, array $previous_rows = array() ): array {
		$entries  = array();
		$position = 0;
		// Explanations are computed on the published entries only, with compacted positions.
		$visible = array();
		foreach ( $rows as $row ) {
			if ( isset( $summaries[ $row['entity_id'] ] ) ) {
				$visible[] = array( 'position' => count( $visible ) + 1 ) + $row;
			}
		}
		$names   = array_map( static fn( array $s ): string => (string) $s['name'], $summaries );
		$why     = RankingExplainer::why( $visible, $names );
		$current = array_column( $visible, null, 'entity_id' );
		foreach ( $rows as $row ) {
			$summary = $summaries[ $row['entity_id'] ] ?? null;
			if ( null === $summary ) {
				continue;
			}
			++$position;
			$before    = null === $previous ? null : ( $previous[ $row['entity_id'] ] ?? null );
			$entries[] = array(
				'position'      => $position,
				'score'         => round( (float) $row['score'], 2 ),
				'scoreVersion'  => $row['score_version'],
				'movement'      => null === $before ? null : $before - $position,
				'isNew'         => null !== $previous && null === $before,
				'breakdown'     => EntityMapper::breakdown( $row['components'] ),
				'why'           => $why[ $row['entity_id'] ] ?? null,
				'change'        => null === $previous || array() === $previous_rows ? null : RankingExplainer::change( $current[ $row['entity_id'] ], $previous_rows[ $row['entity_id'] ] ?? null, $current, $previous_rows, $names ),
				'keyFacts'      => self::key_facts( (array) ( $row['inputs'] ?? array() ) ),
				'qualification' => $row['context']['qualification'] ?? null,
				'entity'        => $summary,
			);
		}//end foreach
		return $entries;
	}

	/**
	 * Ranking DTO.
	 *
	 * @param array<string, mixed>             $record    Ranking record.
	 * @param array<int, array<string, mixed>> $entries   Ordered entries (from order()).
	 * @param int                              $min_default Default minimum entity count.
	 * @param string                           $intro_html  Sanitized body HTML (shown below the ranking).
	 * @param bool                             $with_entries Include entries (detail) or only counts (list).
	 * @param string|null                      $calculated_at Time of the snapshot run the entries come from.
	 * @param array<string, mixed>|null        $context     Context DTO of a contextual ranking (EntityPresenter::ranking_context).
	 * @param array<string, mixed>|null        $eligibility PageEligibility result (Etap G); decides isThin and indexable when given.
	 * @return array<string, mixed>
	 */
	public static function ranking( array $record, array $entries, int $min_default, string $intro_html = '', bool $with_entries = true, ?string $calculated_at = null, ?array $context = null, ?array $eligibility = null ): array {
		$f        = $record['fields'];
		$min      = $f['min_entities'] ?? $min_default;
		$max      = $f['max_entities'] ?? self::DEFAULT_MAX;
		$is_thin  = null !== $eligibility ? ! $eligibility['exists'] : ( count( $entries ) < $min || ( null !== $context && ! $context['eligibility']['eligible'] ) );
		$location = LocationMapper::from_terms( $record['locations'] );
		$practice = self::practice( $record );

		$dto = array(
			'id'             => $record['id'],
			'slug'           => $record['slug'],
			'path'           => self::record_path( $record ),
			'title'          => $record['title'],
			'entityType'     => $f['entity_type'],
			'location'       => $location,
			'practiceArea'   => $practice,
			'context'        => $context,
			'eligibility'    => $eligibility,
			'scoreVersion'   => $f['score_version'],
			'entryCount'     => count( $entries ),
			'minEntities'    => $min,
			'isThin'         => $is_thin,
			'indexable'      => null !== $eligibility ? (bool) $eligibility['indexable'] : ! $is_thin && ! $f['is_demo'],
			'isDemo'         => (bool) $f['is_demo'],
			'updatedAt'      => self::latest( $record['updated_at'], $calculated_at ),
			'calculatedAt'   => $calculated_at,
			'methodologyUrl' => '/methodology/',
		);
		if ( $with_entries ) {
			$dto['summary'] = $f['summary'] ?? null;
			$dto['body']    = $intro_html;
			$dto['intro']   = $intro_html;
			// Deprecated alias of `body` (API 1.1); kept for compatibility.
			$dto['faq']       = self::faq( $f['faq'] ?? array() );
			$dto['editorial'] = array(
				'reviewedBy' => $f['reviewed_by'] ?? null,
				'reviewedAt' => $f['reviewed_at'] ?? null,
			);
			$dto['entries']   = $is_thin ? array() : array_slice( $entries, 0, $max );
		}
		return $dto;
	}

	/**
	 * Later of two ISO timestamps (a recalculation also updates the page).
	 *
	 * @param string|null $a Timestamp.
	 * @param string|null $b Timestamp.
	 */
	public static function latest( ?string $a, ?string $b ): ?string {
		if ( null === $a || null === $b ) {
			return $a ?? $b;
		}
		return strcmp( $a, $b ) >= 0 ? $a : $b;
	}

	/**
	 * FAQ items with both a question and an answer.
	 *
	 * @param array<int, array<string, string|null>> $items Stored FAQ items.
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function faq( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['question'] ) && ! empty( $item['answer'] ) ) {
				$out[] = array(
					'question' => (string) $item['question'],
					'answer'   => (string) $item['answer'],
				);
			}
		}
		return $out;
	}

	/**
	 * Frontend path, e.g. /rankings/florida/miami/personal-injury/, with a
	 * context segment for contextual rankings (…/personal-injury/car-accidents/).
	 *
	 * @param array<string, mixed>|null $location Location DTO.
	 * @param array<string, mixed>|null $practice Practice area DTO.
	 * @param string|null               $segment  Context segment.
	 */
	public static function path( ?array $location, ?array $practice, ?string $segment = null ): ?string {
		$parts = array_filter(
			array(
				$location['stateSlug'] ?? null,
				$location['citySlug'] ?? null,
				$practice['slug'] ?? null,
			)
		);
		if ( array() === $parts ) {
			return null;
		}
		if ( null !== $segment && '' !== $segment ) {
			$parts[] = $segment;
		}
		return '/rankings/' . implode( '/', $parts ) . '/';
	}

	/**
	 * Path of a ranking record (location, practice area, context).
	 *
	 * @param array<string, mixed> $record Ranking record.
	 */
	public static function record_path( array $record ): ?string {
		$qualifier = RankingQualifier::for_record( $record );
		return self::path( LocationMapper::from_terms( $record['locations'] ), self::practice( $record ), $qualifier?->segment() );
	}

	/**
	 * The ranking's practice area DTO (never a case-type sub-area it is qualified by).
	 *
	 * @param array<string, mixed> $record Ranking record.
	 * @return array<string, mixed>|null
	 */
	public static function practice( array $record ): ?array {
		$term = RankingQualifier::primary_practice( $record['practice_areas'], RankingQualifier::for_record( $record ) );
		return null === $term ? null : ( EntityMapper::practice_areas( array( $term ) )[0] ?? null );
	}

	/**
	 * Key facts of an entry, from the inputs it was scored on (for contextual attributes on cards).
	 *
	 * @param array<string, mixed> $inputs Snapshot inputs.
	 * @return array<string, mixed>
	 */
	public static function key_facts( array $inputs ): array {
		return array(
			'yearsExperience' => isset( $inputs['years_experience'] ) ? (int) $inputs['years_experience'] : null,
			'barStatus'       => isset( $inputs['bar_status'] ) ? (string) $inputs['bar_status'] : null,
			'practiceAreas'   => array_values( array_map( 'strval', (array) ( $inputs['practice_areas'] ?? array() ) ) ),
			'awards'          => (int) ( $inputs['awards_count'] ?? 0 ),
		);
	}
}
