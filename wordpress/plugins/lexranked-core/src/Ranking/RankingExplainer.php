<?php
/**
 * Why an entity ranks where it does.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

/**
 * Deterministic explanations built from stored score components only (no
 * AI, no text generation beyond fixed templates):
 *
 * - why: strongest and weakest components compared with the ranking's
 *   average, what separates the entry from the one above, missing inputs;
 * - change: what differs between two snapshot runs (own components and
 *   inputs, competitors that moved past, entries that joined or left,
 *   methodology version).
 */
final class RankingExplainer {

	/** Inputs compared between runs (snapshot `inputs`), with labels. */
	public const INPUT_LABELS = array(
		'rating'              => 'rating',
		'review_count'        => 'review count',
		'years_experience'    => 'years of experience',
		'awards_count'        => 'awards on record',
		'education_count'     => 'education entries',
		'bar_status'          => 'bar status',
		'verification_status' => 'verification',
		'practice_areas'      => 'practice areas',
		'city'                => 'city',
		'sourced_facts'       => 'sourced facts',
		'lawyer_count'        => 'lawyers profiled',
	);

	/**
	 * "Why ranked here" for every row of one run.
	 *
	 * @param array<int, array<string, mixed>> $rows  Run rows ordered by position (components, score, position, entity_id).
	 * @param array<int, string>               $names Display names keyed by entity ID.
	 * @return array<int, array<string, mixed>> Keyed by entity ID.
	 */
	public static function why( array $rows, array $names = array() ): array {
		$n = count( $rows );
		if ( 0 === $n ) {
			return array();
		}
		// Average points per component across the ranking.
		$avg = array();
		foreach ( $rows as $row ) {
			foreach ( $row['components'] as $c ) {
				$avg[ $c['key'] ] = ( $avg[ $c['key'] ] ?? 0.0 ) + (float) $c['points'] / $n;
			}
		}
		$out   = array();
		$above = null;
		foreach ( $rows as $row ) {
			$items = array();
			foreach ( $row['components'] as $c ) {
				$max = (float) $c['weight'];
				if ( $max <= 0 ) {
					continue;
				}
				$items[] = array(
					'key'     => (string) $c['key'],
					'label'   => (string) $c['label'],
					'points'  => round( (float) $c['points'], 2 ),
					'max'     => $max,
					'average' => round( $avg[ $c['key'] ] ?? 0.0, 2 ),
					// Relative to the component's size, so a 5-point component can matter.
					'edge'    => ( (float) $c['points'] - ( $avg[ $c['key'] ] ?? 0.0 ) ) / $max,
					'share'   => (float) $c['points'] / $max,
				);
			}
			$by_edge = $items;
			usort( $by_edge, static fn( array $a, array $b ): int => array( $b['edge'], $a['key'] ) <=> array( $a['edge'], $b['key'] ) );
			$strengths = array_values( array_filter( array_slice( $by_edge, 0, 2 ), static fn( array $i ): bool => $i['edge'] > 0.001 || $i['share'] >= 0.9 ) );
			$by_share  = $items;
			usort( $by_share, static fn( array $a, array $b ): int => array( $a['share'], $a['key'] ) <=> array( $b['share'], $b['key'] ) );
			$strong_keys = array_column( $strengths, 'key' );
			$gaps        = array_slice( array_values( array_filter( $by_share, static fn( array $i ): bool => $i['share'] < 0.75 && ! in_array( $i['key'], $strong_keys, true ) ) ), 0, 2 );

			$behind = null;
			if ( null !== $above ) {
				$deltas = array();
				foreach ( $above['components'] as $c ) {
					$mine = self::points( $row['components'], (string) $c['key'] );
					$diff = round( (float) $c['points'] - $mine, 2 );
					if ( $diff > 0.004 ) {
						$deltas[] = array(
							'key'   => (string) $c['key'],
							'label' => (string) $c['label'],
							'delta' => $diff,
						);
					}
				}
				usort( $deltas, static fn( array $a, array $b ): int => array( $b['delta'], $a['key'] ) <=> array( $a['delta'], $b['key'] ) );
				$behind = array(
					'position'   => (int) $above['position'],
					'entityId'   => (int) $above['entity_id'],
					'name'       => $names[ (int) $above['entity_id'] ] ?? null,
					'scoreGap'   => round( (float) $above['score'] - (float) $row['score'], 2 ),
					'components' => array_slice( $deltas, 0, 3 ),
				);
			}//end if

			$missing = array();
			foreach ( $row['components'] as $c ) {
				foreach ( (array) ( $c['missing'] ?? array() ) as $m ) {
					$missing[ (string) $m ] = true;
				}
			}
			$strip = static fn( array $items_in ): array => array_map( static fn( array $i ): array => array_diff_key( $i, array_flip( array( 'edge', 'share' ) ) ), $items_in );

			$out[ (int) $row['entity_id'] ] = array(
				'summary'   => self::summary( (int) $row['position'], (float) $row['score'], $strengths, $gaps, $behind ),
				'strengths' => $strip( $strengths ),
				'gaps'      => $strip( $gaps ),
				'behind'    => $behind,
				'missing'   => array_keys( $missing ),
			);
			$above                          = $row;
		}//end foreach
		return $out;
	}

	/**
	 * What changed between two runs, for one entity.
	 *
	 * @param array<string, mixed>             $current      This run's row for the entity.
	 * @param array<string, mixed>|null        $previous     Previous run's row for the entity (null = new entry).
	 * @param array<int, array<string, mixed>> $current_run  This run's rows (keyed by entity ID).
	 * @param array<int, array<string, mixed>> $previous_run Previous run's rows (keyed by entity ID).
	 * @param array<int, string>               $names        Display names keyed by entity ID.
	 * @return array<string, mixed>|null Null when nothing changed.
	 */
	public static function change( array $current, ?array $previous, array $current_run, array $previous_run, array $names = array() ): ?array {
		if ( null === $previous ) {
			return array(
				'previousPosition' => null,
				'previousScore'    => null,
				'scoreDelta'       => null,
				'reasons'          => array(
					array(
						'type' => 'entered',
						'text' => 'New in this ranking since the previous calculation.',
					),
				),
			);
		}
		$id    = (int) $current['entity_id'];
		$delta = round( (float) $current['score'] - (float) $previous['score'], 2 );
		$moved = (int) $previous['position'] - (int) $current['position'];
		if ( 0 === $moved && abs( $delta ) < 0.005 ) {
			return null;
		}
		$reasons = array();
		if ( (string) $current['score_version'] !== (string) $previous['score_version'] ) {
			$reasons[] = array(
				'type' => 'methodology',
				'from' => (string) $previous['score_version'],
				'to'   => (string) $current['score_version'],
				'text' => sprintf( 'The methodology changed from %s to %s.', $previous['score_version'], $current['score_version'] ),
			);
		}
		// Own score components.
		$components = array();
		foreach ( $current['components'] as $c ) {
			$before = self::points( $previous['components'], (string) $c['key'] );
			$d      = round( (float) $c['points'] - $before, 2 );
			if ( abs( $d ) >= 0.01 ) {
				$components[] = array(
					'type'      => 'component',
					'component' => (string) $c['key'],
					'from'      => $before,
					'to'        => round( (float) $c['points'], 2 ),
					'delta'     => $d,
					'text'      => sprintf( '%s %s%.2f (%.2f → %.2f of %s).', $c['label'], $d > 0 ? '+' : '', $d, $before, (float) $c['points'], rtrim( rtrim( number_format( (float) $c['weight'], 2, '.', '' ), '0' ), '.' ) ),
				);
			}
		}
		usort( $components, static fn( array $a, array $b ): int => abs( $b['delta'] ) <=> abs( $a['delta'] ) );
		array_push( $reasons, ...$components );
		// Own inputs (the data behind the components).
		foreach ( self::INPUT_LABELS as $key => $label ) {
			$from = $previous['inputs'][ $key ] ?? null;
			$to   = $current['inputs'][ $key ] ?? null;
			if ( $from !== $to && ( is_scalar( $from ) || is_scalar( $to ) || null === $from || null === $to ) && json_encode( $from ) !== json_encode( $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Comparison only.
				$reasons[] = array(
					'type'  => 'input',
					'field' => $key,
					'from'  => $from,
					'to'    => $to,
					'text'  => sprintf( 'Data changed: %s %s → %s.', $label, self::show( $from ), self::show( $to ) ),
				);
			}
		}
		// Competitors that moved past (or fell behind), and entries that left.
		foreach ( $current_run as $other_id => $other ) {
			if ( $other_id === $id ) {
				continue;
			}
			$was_above = isset( $previous_run[ $other_id ] ) ? (int) $previous_run[ $other_id ]['position'] < (int) $previous['position'] : null;
			$is_above  = (int) $other['position'] < (int) $current['position'];
			$name      = $names[ $other_id ] ?? ( '#' . $other_id );
			if ( $is_above && null === $was_above ) {
				$reasons[] = array(
					'type'     => 'competitor',
					'entityId' => (int) $other_id,
					'text'     => sprintf( '%s joined the ranking above (score %.2f).', $name, (float) $other['score'] ),
				);
			} elseif ( $is_above && false === $was_above ) {
				$other_delta = round( (float) $other['score'] - (float) $previous_run[ $other_id ]['score'], 2 );
				$reasons[]   = array(
					'type'     => 'competitor',
					'entityId' => (int) $other_id,
					'delta'    => $other_delta,
					'text'     => sprintf( '%s moved past (their score %s%.2f).', $name, $other_delta >= 0 ? '+' : '', $other_delta ),
				);
			} elseif ( ! $is_above && true === $was_above ) {
				$reasons[] = array(
					'type'     => 'competitor',
					'entityId' => (int) $other_id,
					'text'     => sprintf( '%s dropped below.', $name ),
				);
			}//end if
		}//end foreach
		foreach ( $previous_run as $other_id => $other ) {
			if ( ! isset( $current_run[ $other_id ] ) && (int) $other['position'] < (int) $previous['position'] ) {
				$reasons[] = array(
					'type'     => 'left',
					'entityId' => (int) $other_id,
					'text'     => sprintf( '%s is no longer in the ranking.', $names[ $other_id ] ?? ( '#' . $other_id ) ),
				);
			}
		}
		return array(
			'previousPosition' => (int) $previous['position'],
			'previousScore'    => round( (float) $previous['score'], 2 ),
			'scoreDelta'       => $delta,
			'reasons'          => $reasons,
		);
	}

	/**
	 * Points of a component in a list.
	 *
	 * @param array<int, array<string, mixed>> $components Components.
	 * @param string                           $key        Key.
	 */
	private static function points( array $components, string $key ): float {
		foreach ( $components as $c ) {
			if ( $c['key'] === $key ) {
				return round( (float) $c['points'], 2 );
			}
		}
		return 0.0;
	}

	/**
	 * Display a value.
	 *
	 * @param mixed $value Value.
	 */
	private static function show( mixed $value ): string {
		if ( null === $value ) {
			return 'none';
		}
		if ( is_array( $value ) ) {
			return array() === $value ? 'none' : implode( ', ', array_map( 'strval', $value ) );
		}
		return is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value;
	}

	/**
	 * One-sentence summary (fixed template).
	 *
	 * @param int                              $position  Position.
	 * @param float                            $score     Score.
	 * @param array<int, array<string, mixed>> $strengths Strengths.
	 * @param array<int, array<string, mixed>> $gaps      Gaps.
	 * @param array<string, mixed>|null        $behind    Entry above.
	 */
	private static function summary( int $position, float $score, array $strengths, array $gaps, ?array $behind ): string {
		$fmt  = static fn( array $i ): string => sprintf( '%s (%s/%s)', $i['label'], rtrim( rtrim( number_format( $i['points'], 1, '.', '' ), '0' ), '.' ), rtrim( rtrim( number_format( $i['max'], 1, '.', '' ), '0' ), '.' ) );
		$text = sprintf( 'Ranks #%d with %.2f', $position, $score );
		if ( array() !== $strengths ) {
			$text .= ': strongest in ' . implode( ' and ', array_map( $fmt, $strengths ) );
		}
		if ( array() !== $gaps ) {
			$text .= ( array() === $strengths ? ': ' : '; ' ) . 'held back by ' . implode( ' and ', array_map( $fmt, $gaps ) );
		}
		if ( null !== $behind && array() !== $behind['components'] ) {
			$text .= sprintf( '. %.2f points behind #%d, mostly %s', $behind['scoreGap'], $behind['position'], strtolower( $behind['components'][0]['label'] ) );
		}
		return $text . '.';
	}
}
