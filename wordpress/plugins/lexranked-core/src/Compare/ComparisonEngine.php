<?php
/**
 * Comparison engine (Etap E).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Compare;

/**
 * Compares two to four entities of one type side by side, from their public
 * detail DTOs only (facts, score, verification, freshness, data quality).
 *
 * Rules:
 * - every cell is a stored value with its status, source and check date, or
 *   "not on record"; nothing is estimated, and no LLM is involved;
 * - "highest" is marked only on numeric rows where every value is on record
 *   and none is in conflict, and only when one entity is strictly ahead;
 *   LexRank scores are compared only within one methodology version;
 * - the summary states differences ("more reviews on record"), never a verdict
 *   ("better lawyer");
 * - commercial status (claimed, premium, placements) is not a dimension and is
 *   never read. Pure.
 */
final class ComparisonEngine {

	public const VERSION = 'cmp-1.0';
	public const MIN     = 2;
	public const MAX     = 4;

	/** Ratings from fewer reviews than this get a note. */
	public const FEW_REVIEWS = 10;

	/**
	 * Rows per entity type: key => [label, group, kind]. Kinds: number (can have
	 * a highest value), text, list (shared items), objects (education, awards).
	 */
	private const ROWS = array(
		'lawyer'   => array(
			'lexrank_score'    => array( 'LexRank score', 'ranking', 'number' ),
			'rating'           => array( 'Client rating', 'reviews', 'number' ),
			'review_count'     => array( 'Review count', 'reviews', 'number' ),
			'years_experience' => array( 'Years of experience', 'experience', 'number' ),
			'practice_areas'   => array( 'Practice areas', 'practice', 'list' ),
			'location'         => array( 'Location', 'location', 'text' ),
			'firm'             => array( 'Law firm', 'organization', 'text' ),
			'bar_status'       => array( 'Bar status', 'credentials', 'text' ),
			'bar_state'        => array( 'Bar admission state', 'credentials', 'text' ),
			'education'        => array( 'Education', 'credentials', 'objects' ),
			'awards'           => array( 'Awards and recognition', 'credentials', 'objects' ),
			'languages'        => array( 'Languages', 'language', 'list' ),
			'verification'     => array( 'Verification', 'verification', 'text' ),
			'data_freshness'   => array( 'Data freshness', 'quality', 'text' ),
			'data_quality'     => array( 'Data quality', 'quality', 'text' ),
		),
		'law_firm' => array(
			'lexrank_score'  => array( 'LexRank score', 'ranking', 'number' ),
			'rating'         => array( 'Client rating', 'reviews', 'number' ),
			'review_count'   => array( 'Review count', 'reviews', 'number' ),
			'practice_areas' => array( 'Practice areas', 'practice', 'list' ),
			'location'       => array( 'Location', 'location', 'text' ),
			'lawyers'        => array( 'Lawyers listed on LexRanked', 'organization', 'text' ),
			'verification'   => array( 'Verification', 'verification', 'text' ),
			'data_freshness' => array( 'Data freshness', 'quality', 'text' ),
			'data_quality'   => array( 'Data quality', 'quality', 'text' ),
		),
	);

	/** Summary phrases for numeric rows: [two entities, three or more]. */
	private const PHRASES = array(
		'lexrank_score'    => array( 'has the higher LexRank score', 'has the highest LexRank score' ),
		'rating'           => array( 'has the higher client rating', 'has the highest client rating' ),
		'review_count'     => array( 'has more reviews on record', 'has the most reviews on record' ),
		'years_experience' => array( 'has more years of experience', 'has the most years of experience' ),
	);

	/**
	 * Compare entities.
	 *
	 * @param string                           $type    lawyer|law_firm.
	 * @param array<int, array<string, mixed>> $details Public detail DTOs, in the requested order.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException On an unsupported type or entity count.
	 */
	public static function compare( string $type, array $details ): array {
		if ( ! isset( self::ROWS[ $type ] ) ) {
			throw new \InvalidArgumentException( 'Unsupported entity type.' );
		}
		$details = array_values( $details );
		if ( count( $details ) < self::MIN || count( $details ) > self::MAX ) {
			throw new \InvalidArgumentException( 'Compare between ' . self::MIN . ' and ' . self::MAX . ' entities.' );
		}

		$rows = array();
		foreach ( self::ROWS[ $type ] as $key => [ $label, $group, $kind ] ) {
			$cells  = array_map( static fn( array $d ): array => self::cell( $key, $d ), $details );
			$rows[] = array(
				'key'     => $key,
				'label'   => $label,
				'group'   => $group,
				'kind'    => $kind,
				'cells'   => $cells,
				'highest' => 'number' === $kind ? self::highest( $key, $cells ) : array(),
				'shared'  => 'list' === $kind ? self::shared( $cells ) : null,
				'note'    => self::row_note( $key, $cells ),
			);
		}
		$shared_rankings = self::shared_rankings( $details );

		return array(
			'version'        => self::VERSION,
			'type'           => $type,
			'entities'       => array_map( array( self::class, 'head' ), $details ),
			'rows'           => $rows,
			'sharedRankings' => $shared_rankings,
			'summary'        => self::summary( $details, $rows, $shared_rankings ),
			'basis'          => 'Built from stored facts, sources and score snapshots only. Differences are stated, not judged: this is not a recommendation, and no AI writes or orders it.',
		);
	}

	/**
	 * Entity header.
	 *
	 * @param array<string, mixed> $d Detail DTO.
	 * @return array<string, mixed>
	 */
	private static function head( array $d ): array {
		return array(
			'id'           => (int) $d['id'],
			'entityId'     => isset( $d['entityId'] ) ? (int) $d['entityId'] : null,
			'type'         => (string) $d['type'],
			'name'         => (string) $d['name'],
			'path'         => (string) $d['path'],
			'location'     => $d['location'] ?? null,
			'firm'         => isset( $d['firm']['name'] ) ? array(
				'name' => (string) $d['firm']['name'],
				'path' => (string) $d['firm']['path'],
			) : null,
			'verification' => (string) ( $d['verification']['status'] ?? 'unverified' ),
			'isDemo'       => (bool) ( $d['isDemo'] ?? false ),
		);
	}

	/**
	 * One cell: value, display, status, source, check date.
	 *
	 * @param string               $key Row key.
	 * @param array<string, mixed> $d   Detail DTO.
	 * @return array<string, mixed>
	 */
	private static function cell( string $key, array $d ): array {
		$facts = array_column( (array) ( $d['facts'] ?? array() ), null, 'attribute' );
		$cell  = array(
			'id'        => (int) $d['id'],
			'value'     => null,
			'display'   => null,
			'status'    => 'missing',
			'source'    => null,
			'checkedAt' => null,
			'isStale'   => false,
			'note'      => null,
		);

		switch ( $key ) {
			case 'lexrank_score':
				$score = $d['ranking']['score'] ?? null;
				if ( null === $score ) {
					return $cell;
				}
				$version = (string) ( $d['ranking']['scoreVersion'] ?? '' );
				return array_merge(
					$cell,
					array(
						'value'     => (float) $score,
						'display'   => number_format( (float) $score, 2 ) . ' / 100',
						'status'    => 'derived',
						'source'    => array(
							'name'      => 'LexRanked methodology ' . $version,
							'publisher' => 'LexRanked',
							'tierLabel' => null,
							'url'       => '/methodology/',
						),
						'checkedAt' => $d['ranking']['calculatedAt'] ?? null,
						'note'      => '' === $version ? null : 'Methodology ' . $version,
					)
				);

			case 'location':
				$city  = $facts['city'] ?? null;
				$state = $facts['state'] ?? null;
				$base  = $city ?? $state;
				if ( null === $base ) {
					return $cell;
				}
				$parts = array_filter( array( null === $city ? null : (string) $city['value'], null === $state ? null : (string) $state['value'] ) );
				$cell  = self::from_fact( $cell, $base, implode( ', ', $parts ) );
				if ( null !== $city && null !== $state && 'conflict' === $state['status'] ) {
					$cell['status'] = 'conflict';
				}
				return $cell;

			case 'firm':
				if ( ! isset( $d['firm']['name'] ) ) {
					return $cell;
				}
				return array_merge(
					$cell,
					array(
						'value'   => (string) $d['firm']['name'],
						'display' => (string) $d['firm']['name'],
						'status'  => 'directory',
						'note'    => 'As linked in the LexRanked directory.',
					)
				);

			case 'lawyers':
				$count = count( (array) ( $d['lawyers'] ?? array() ) );
				return array_merge(
					$cell,
					array(
						'value'   => $count,
						'display' => 1 === $count ? '1 lawyer' : $count . ' lawyers',
						'status'  => 'directory',
						'note'    => 'Published lawyer profiles linked to the firm; not the firm\'s total headcount.',
					)
				);

			case 'verification':
				$status = (string) ( $d['verification']['status'] ?? 'unverified' );
				$checks = array_keys( array_filter( (array) ( $d['verification']['checks'] ?? array() ), static fn( $v ): bool => 'verified' === $v ) );
				return array_merge(
					$cell,
					array(
						'value'     => $status,
						'display'   => ucfirst( str_replace( '_', ' ', $status ) ) . ( array() === $checks ? '' : ' (' . count( $checks ) . ' ' . ( 1 === count( $checks ) ? 'check' : 'checks' ) . ' passed)' ),
						'status'    => 'derived',
						'checkedAt' => $d['verification']['verifiedAt'] ?? null,
					)
				);

			case 'data_freshness':
				return self::freshness( $cell, (array) ( $d['facts'] ?? array() ) );

			case 'data_quality':
				$score = $d['dataQuality']['score'] ?? null;
				if ( null === $score ) {
					return $cell;
				}
				return array_merge(
					$cell,
					array(
						'value'     => (float) $score,
						'display'   => rtrim( rtrim( number_format( (float) $score, 1 ), '0' ), '.' ) . '%',
						'status'    => 'derived',
						'checkedAt' => $d['dataQuality']['calculatedAt'] ?? null,
						'note'      => 'How complete and well-sourced the record is; not part of the ranking.',
					)
				);

			case 'practice_areas':
				$fact = $facts['practice_areas'] ?? null;
				if ( null === $fact ) {
					return $cell;
				}
				$names         = array_column( (array) ( $d['practiceAreas'] ?? array() ), 'name', 'slug' );
				$items         = array_values( array_map( 'strval', (array) $fact['value'] ) );
				$cell          = self::from_fact( $cell, $fact, implode( ', ', array_map( static fn( string $s ): string => $names[ $s ] ?? ucwords( str_replace( '-', ' ', $s ) ), $items ) ) );
				$cell['value'] = $items;
				return $cell;

			default:
				$fact = $facts[ $key ] ?? null;
				if ( null === $fact ) {
					return $cell;
				}
				$cell = self::from_fact( $cell, $fact, self::display( $key, $fact['value'] ) );
				if ( 'rating' === $key ) {
					$count = $facts['review_count']['value'] ?? null;
					if ( is_numeric( $count ) && (int) $count < self::FEW_REVIEWS ) {
						$cell['note'] = 'Based on ' . (int) $count . ' ' . ( 1 === (int) $count ? 'review' : 'reviews' ) . '.';
					}
				}
				return $cell;
		}//end switch
	}

	/**
	 * Fill a cell from a fact DTO.
	 *
	 * @param array<string, mixed> $cell    Cell.
	 * @param array<string, mixed> $fact    Fact DTO (FactMapper).
	 * @param string               $display Display text.
	 * @return array<string, mixed>
	 */
	private static function from_fact( array $cell, array $fact, string $display ): array {
		$source = (array) ( $fact['source'] ?? array() );
		return array_merge(
			$cell,
			array(
				'value'     => $fact['value'],
				'display'   => '' === $display ? null : $display,
				'status'    => (string) $fact['status'],
				'source'    => null === ( $source['name'] ?? null ) && null === ( $source['url'] ?? null ) ? null : array(
					'name'      => $source['name'] ?? null,
					'publisher' => $source['publisher'] ?? null,
					'tierLabel' => $source['tierLabel'] ?? null,
					'url'       => $source['url'] ?? null,
				),
				'checkedAt' => $fact['verifiedAt'] ?? $fact['observedAt'] ?? null,
				'isStale'   => (bool) ( $fact['freshness']['isStale'] ?? false ),
			)
		);
	}

	/**
	 * Display text for a fact value.
	 *
	 * @param string $key   Attribute.
	 * @param mixed  $value Value.
	 */
	private static function display( string $key, mixed $value ): string {
		if ( is_array( $value ) ) {
			$items = array_map(
				static fn( $item ): string => is_array( $item )
					? trim( implode( ', ', array_filter( array( $item['degree'] ?? null, $item['institution'] ?? $item['name'] ?? null, $item['year'] ?? null ), static fn( $v ): bool => null !== $v && '' !== $v ) ) )
					: (string) $item,
				$value
			);
			return implode( is_array( reset( $value ) ) ? '; ' : ', ', array_filter( $items, static fn( string $s ): bool => '' !== $s ) );
		}
		return match ( $key ) {
			'rating'           => number_format( (float) $value, 1 ) . ' / 5',
			'review_count'     => number_format( (int) $value ) . ' ' . ( 1 === (int) $value ? 'review' : 'reviews' ),
			'years_experience' => (int) $value . ' ' . ( 1 === (int) $value ? 'year' : 'years' ),
			'bar_status'       => ucfirst( (string) $value ),
			default            => (string) $value,
		};
	}

	/**
	 * Data freshness cell: the oldest check among the facts and how many are stale.
	 *
	 * @param array<string, mixed>             $cell  Cell.
	 * @param array<int, array<string, mixed>> $facts Fact DTOs.
	 * @return array<string, mixed>
	 */
	private static function freshness( array $cell, array $facts ): array {
		$dates = array_filter( array_map( static fn( array $f ): ?string => $f['verifiedAt'] ?? $f['observedAt'] ?? null, $facts ) );
		if ( array() === $dates ) {
			return $cell;
		}
		sort( $dates );
		$stale = count( array_filter( $facts, static fn( array $f ): bool => (bool) ( $f['freshness']['isStale'] ?? false ) ) );
		return array_merge(
			$cell,
			array(
				'value'     => array(
					'oldest' => $dates[0],
					'newest' => $dates[ count( $dates ) - 1 ],
					'stale'  => $stale,
					'facts'  => count( $facts ),
				),
				'display'   => 0 === $stale ? 'All ' . count( $facts ) . ' facts within their freshness window' : $stale . ' of ' . count( $facts ) . ' facts past their freshness window',
				'status'    => 'derived',
				'checkedAt' => $dates[0],
				'isStale'   => $stale > 0,
				'note'      => 'Date shown is the oldest check among the facts.',
			)
		);
	}

	/**
	 * Entity IDs holding the strictly highest value, or none.
	 *
	 * @param string                           $key   Row key.
	 * @param array<int, array<string, mixed>> $cells Cells.
	 * @return array<int, int>
	 */
	private static function highest( string $key, array $cells ): array {
		foreach ( $cells as $cell ) {
			if ( ! in_array( $cell['status'], array( 'verified', 'unverified', 'derived' ), true ) || ! is_numeric( $cell['value'] ) ) {
				return array();
			}
		}
		if ( 'lexrank_score' === $key && count( array_unique( array_map( static fn( array $c ): string => (string) $c['note'], $cells ) ) ) > 1 ) {
			return array();
			// Different methodology versions are not comparable.
		}
		$values = array_map( static fn( array $c ): float => round( (float) $c['value'], 2 ), $cells );
		$max    = max( $values );
		$top    = array_keys( $values, $max, true );
		return 1 === count( $top ) ? array( (int) $cells[ $top[0] ]['id'] ) : array();
	}

	/**
	 * Items every entity with a value shares.
	 *
	 * @param array<int, array<string, mixed>> $cells Cells.
	 * @return array<int, string>
	 */
	private static function shared( array $cells ): array {
		$lists = array();
		foreach ( $cells as $cell ) {
			if ( ! is_array( $cell['value'] ) || 'conflict' === $cell['status'] ) {
				return array();
			}
			$lists[] = array_map( 'strval', $cell['value'] );
		}
		return array_values( array_intersect( ...$lists ) );
	}

	/**
	 * Why a row has no highest value, when that is not obvious.
	 *
	 * @param string                           $key   Row key.
	 * @param array<int, array<string, mixed>> $cells Cells.
	 */
	private static function row_note( string $key, array $cells ): ?string {
		$statuses = array_column( $cells, 'status' );
		if ( in_array( 'conflict', $statuses, true ) ) {
			return 'Sources disagree for at least one entity, so this row is not compared.';
		}
		if ( 'lexrank_score' === $key && ! in_array( 'missing', $statuses, true ) && count( array_unique( array_map( static fn( array $c ): string => (string) $c['note'], $cells ) ) ) > 1 ) {
			return 'Scores come from different methodology versions and are not compared.';
		}
		return null;
	}

	/**
	 * Rankings in which every compared entity appears, with their positions.
	 *
	 * @param array<int, array<string, mixed>> $details Detail DTOs.
	 * @return array<int, array<string, mixed>>
	 */
	private static function shared_rankings( array $details ): array {
		$by_ranking = array();
		foreach ( $details as $d ) {
			foreach ( (array) ( $d['rankings'] ?? array() ) as $r ) {
				if ( empty( $r['path'] ) ) {
					continue;
				}
				$by_ranking[ (int) $r['id'] ]['ranking']     = $r;
				$by_ranking[ (int) $r['id'] ]['positions'][] = array(
					'id'       => (int) $d['id'],
					'position' => (int) $r['position'],
					'score'    => (float) $r['score'],
				);
			}
		}
		$out = array();
		foreach ( $by_ranking as $id => $item ) {
			if ( count( $item['positions'] ) !== count( $details ) ) {
				continue;
			}
			$out[] = array(
				'id'           => $id,
				'title'        => (string) $item['ranking']['title'],
				'path'         => (string) $item['ranking']['path'],
				'calculatedAt' => $item['ranking']['calculatedAt'] ?? null,
				'positions'    => $item['positions'],
			);
		}
		usort( $out, static fn( array $a, array $b ): int => array( min( array_column( $a['positions'], 'position' ) ), $a['id'] ) <=> array( min( array_column( $b['positions'], 'position' ) ), $b['id'] ) );
		return $out;
	}

	/**
	 * Template summary: differences on record, shared practice, shared
	 * rankings, gaps and conflicts. No verdict.
	 *
	 * @param array<int, array<string, mixed>> $details         Detail DTOs.
	 * @param array<int, array<string, mixed>> $rows            Rows.
	 * @param array<int, array<string, mixed>> $shared_rankings Shared rankings.
	 * @return array<int, string>
	 */
	private static function summary( array $details, array $rows, array $shared_rankings ): array {
		$names = array();
		foreach ( $details as $d ) {
			$names[ (int) $d['id'] ] = (string) $d['name'];
		}
		$many  = count( $details ) > 2;
		$lines = array();

		foreach ( $rows as $row ) {
			if ( array() === $row['highest'] || ! isset( self::PHRASES[ $row['key'] ] ) ) {
				continue;
			}
			$top    = $row['highest'][0];
			$values = array();
			foreach ( $row['cells'] as $cell ) {
				if ( $cell['id'] !== $top ) {
					$values[] = self::short( $row['key'], $cell['value'] );
				}
			}
			$lines[] = sprintf(
				'%s %s (%s%s %s).',
				$names[ $top ],
				self::PHRASES[ $row['key'] ][ $many ? 1 : 0 ],
				self::short( $row['key'], self::cell_of( $row, $top )['value'] ),
				$many ? '; others' : ' vs',
				implode( ', ', $values )
			);
		}

		$practice = self::row( $rows, 'practice_areas' );
		if ( null !== $practice && array() === array_intersect( array( 'missing', 'conflict' ), array_column( $practice['cells'], 'status' ) ) ) {
			$labels = array();
			foreach ( $details as $d ) {
				$labels += array_column( (array) ( $d['practiceAreas'] ?? array() ), 'name', 'slug' );
			}
			$shared  = array_map( static fn( string $s ): string => $labels[ $s ] ?? ucwords( str_replace( '-', ' ', $s ) ), (array) $practice['shared'] );
			$lines[] = array() === $shared
				? 'They list no practice area in common.'
				: sprintf( '%s %s %s.', $many ? 'All ' . count( $details ) : 'Both', 'practice', self::join( $shared ) );
		}

		foreach ( $shared_rankings as $ranking ) {
			$parts = array();
			foreach ( $ranking['positions'] as $p ) {
				$parts[] = $names[ $p['id'] ] . ' is #' . $p['position'];
			}
			$lines[] = sprintf( 'In %s, %s.', $ranking['title'], self::join( $parts ) );
		}

		$missing   = array();
		$conflicts = array();
		foreach ( $rows as $row ) {
			foreach ( $row['cells'] as $cell ) {
				if ( 'missing' === $cell['status'] && 'data_quality' !== $row['key'] ) {
					$missing[ $cell['id'] ][] = strtolower( $row['label'] );
				} elseif ( 'conflict' === $cell['status'] ) {
					$conflicts[ $cell['id'] ][] = strtolower( $row['label'] );
				}
			}
		}
		$conflicts = array_replace( array_intersect_key( array_fill_keys( array_keys( $names ), null ), $conflicts ), $conflicts );
		$missing   = array_replace( array_intersect_key( array_fill_keys( array_keys( $names ), null ), $missing ), $missing );
		foreach ( $conflicts as $id => $labels ) {
			$lines[] = sprintf( 'Sources disagree on %s for %s; not compared.', implode( ', ', $labels ), $names[ $id ] );
		}
		foreach ( $missing as $id => $labels ) {
			$lines[] = sprintf( 'Not on record for %s: %s.', $names[ $id ], implode( ', ', $labels ) );
		}
		return $lines;
	}

	/**
	 * Row by key.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @param string                           $key  Key.
	 * @return array<string, mixed>|null
	 */
	private static function row( array $rows, string $key ): ?array {
		foreach ( $rows as $row ) {
			if ( $key === $row['key'] ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Cell of an entity in a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @param int                  $id  Entity (post) ID.
	 * @return array<string, mixed>
	 */
	private static function cell_of( array $row, int $id ): array {
		foreach ( $row['cells'] as $cell ) {
			if ( $id === $cell['id'] ) {
				return $cell;
			}
		}
		return array( 'value' => null );
	}

	/**
	 * Short number for the summary.
	 *
	 * @param string $key   Row key.
	 * @param mixed  $value Value.
	 */
	private static function short( string $key, mixed $value ): string {
		return match ( $key ) {
			'lexrank_score' => number_format( (float) $value, 2 ),
			'rating'        => number_format( (float) $value, 1 ),
			default         => number_format( (int) $value ),
		};
	}

	/**
	 * "a, b and c".
	 *
	 * @param array<int, string> $items Items.
	 */
	private static function join( array $items ): string {
		$items = array_values( $items );
		if ( count( $items ) < 2 ) {
			return $items[0] ?? '';
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ' and ' . $last;
	}
}
