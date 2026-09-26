<?php
/**
 * Ranking DTO mapping.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\REST\DTO;

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
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries_from_snapshots( array $rows, array $summaries, ?array $previous ): array {
		$entries  = array();
		$position = 0;
		foreach ( $rows as $row ) {
			$summary = $summaries[ $row['entity_id'] ] ?? null;
			if ( null === $summary ) {
				continue;
			}
			++$position;
			$before    = null === $previous ? null : ( $previous[ $row['entity_id'] ] ?? null );
			$entries[] = array(
				'position'     => $position,
				'score'        => round( (float) $row['score'], 2 ),
				'scoreVersion' => $row['score_version'],
				'movement'     => null === $before ? null : $before - $position,
				'isNew'        => null !== $previous && null === $before,
				'breakdown'    => EntityMapper::breakdown( $row['components'] ),
				'entity'       => $summary,
			);
		}
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
	 * @return array<string, mixed>
	 */
	public static function ranking( array $record, array $entries, int $min_default, string $intro_html = '', bool $with_entries = true, ?string $calculated_at = null ): array {
		$f        = $record['fields'];
		$min      = $f['min_entities'] ?? $min_default;
		$max      = $f['max_entities'] ?? self::DEFAULT_MAX;
		$is_thin  = count( $entries ) < $min;
		$location = LocationMapper::from_terms( $record['locations'] );
		$practice = EntityMapper::practice_areas( $record['practice_areas'] )[0] ?? null;

		$dto = array(
			'id'             => $record['id'],
			'slug'           => $record['slug'],
			'path'           => self::path( $location, $practice ),
			'title'          => $record['title'],
			'entityType'     => $f['entity_type'],
			'location'       => $location,
			'practiceArea'   => $practice,
			'scoreVersion'   => $f['score_version'],
			'entryCount'     => count( $entries ),
			'minEntities'    => $min,
			'isThin'         => $is_thin,
			'indexable'      => ! $is_thin && ! $f['is_demo'],
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
	 * Frontend path, e.g. /rankings/florida/miami/personal-injury/.
	 *
	 * @param array<string, mixed>|null $location Location DTO.
	 * @param array<string, mixed>|null $practice Practice area DTO.
	 */
	public static function path( ?array $location, ?array $practice ): ?string {
		$parts = array_filter(
			array(
				$location['stateSlug'] ?? null,
				$location['citySlug'] ?? null,
				$practice['slug'] ?? null,
			)
		);
		return array() === $parts ? null : '/rankings/' . implode( '/', $parts ) . '/';
	}
}
