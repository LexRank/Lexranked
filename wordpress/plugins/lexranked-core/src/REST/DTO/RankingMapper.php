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
	 * Order entity summaries by stored organic score.
	 *
	 * Deterministic: score DESC, then ID ASC. Entities without a score are
	 * excluded (never ranked on guessed data). Commercial status is ignored.
	 *
	 * @param array<int, array<string, mixed>> $summaries Entity summary DTOs.
	 * @param string|null                      $score_version Only include this score version (null = any).
	 * @return array<int, array<string, mixed>> Ranked entries with position.
	 */
	public static function order( array $summaries, ?string $score_version ): array {
		$eligible = array_values(
			array_filter(
				$summaries,
				static fn( array $s ): bool => null !== $s['ranking']['score']
					&& ( null === $score_version || $s['ranking']['scoreVersion'] === $score_version )
			)
		);
		usort(
			$eligible,
			static fn( array $a, array $b ): int => array( $b['ranking']['score'], $a['id'] ) <=> array( $a['ranking']['score'], $b['id'] )
		);

		$entries = array();
		foreach ( $eligible as $i => $summary ) {
			$entries[] = array(
				'position'     => $i + 1,
				'score'        => $summary['ranking']['score'],
				'scoreVersion' => $summary['ranking']['scoreVersion'],
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
	 * @param string                           $intro_html  Sanitized intro HTML.
	 * @param bool                             $with_entries Include entries (detail) or only counts (list).
	 * @return array<string, mixed>
	 */
	public static function ranking( array $record, array $entries, int $min_default, string $intro_html = '', bool $with_entries = true ): array {
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
			'updatedAt'      => $record['updated_at'],
			'methodologyUrl' => '/methodology/',
		);
		if ( $with_entries ) {
			$dto['intro']   = $intro_html;
			$dto['entries'] = $is_thin ? array() : array_slice( $entries, 0, $max );
		}
		return $dto;
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
