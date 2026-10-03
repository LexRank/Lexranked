<?php
/**
 * AI-readable structured summaries (Etap H).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Content;

/**
 * A short, answer-first summary of a lawyer or firm profile, built only from
 * the profile's own DTO (facts, score, verification). Each statement is also
 * returned as a structured fact with its status and date, so people and AI
 * answer engines get the answer and the evidence together.
 *
 * Wording follows the evidence: a fact is "verified" only when its fact
 * status is verified; otherwise it is "on record". Missing data is left
 * out, never guessed. Commercial status is never read. No LLM. Pure.
 */
final class StructuredSummary {

	public const VERSION = 'sum-1.1';

	/**
	 * Summary for a lawyer or firm detail DTO.
	 *
	 * @param array<string, mixed> $d Detail DTO (EntityPresenter).
	 * @return array{version: string, text: string, facts: array<int, array<string, mixed>>, asOf: string|null}
	 */
	public static function for_detail( array $d ): array {
		$facts = array_column( (array) ( $d['facts'] ?? array() ), null, 'attribute' );
		$firm  = 'law_firm' === ( $d['type'] ?? 'lawyer' );
		$items = array();
		$where = self::where( (array) ( $d['location'] ?? array() ) );

		$practices = array_values( array_filter( array_map( static fn( $p ): string => (string) ( $p['name'] ?? '' ), (array) ( $d['practiceAreas'] ?? array() ) ) ) );
		$cases     = array_map( array( self::class, 'humanize' ), array_map( 'strval', (array) ( $facts['case_types']['value'] ?? array() ) ) );

		// Identity sentence.
		if ( $firm ) {
			$lead = sprintf( '%s is a law firm%s', $d['name'], '' === $where ? '' : ' in ' . $where );
		} else {
			$role = (string) ( $d['title'] ?? '' );
			$lead = sprintf(
				'%s is %s%s%s',
				$d['name'],
				'' === $role ? 'a lawyer' : self::article( $role ) . ' ' . $role,
				isset( $d['firm']['name'] ) ? ' at ' . $d['firm']['name'] : '',
				'' === $where ? '' : ' in ' . $where
			);
		}
		if ( array() !== $practices ) {
			$lead .= ', focused on ' . strtolower( self::join( $practices ) ) . ' law';
			if ( array() !== $cases ) {
				$lead .= ' (' . strtolower( self::join( $cases ) ) . ')';
			}
		}
		$sentences = array( $lead . '.' );

		// LexRank score.
		$score = $d['ranking']['score'] ?? null;
		if ( null !== $score ) {
			$version     = (string) ( $d['ranking']['scoreVersion'] ?? '' );
			$sentences[] = sprintf( 'LexRank score: %s/100%s.', number_format( (float) $score, 2 ), '' === $version ? '' : ' (methodology ' . $version . ')' );
			$items[]     = self::item( 'lexrank_score', 'LexRank score', number_format( (float) $score, 2 ) . '/100', 'derived', $d['ranking']['calculatedAt'] ?? null );
		}

		// Reviews.
		$rating  = $facts['rating'] ?? null;
		$reviews = $facts['review_count'] ?? null;
		if ( self::usable( $rating ) ) {
			$count       = self::usable( $reviews ) ? (int) $reviews['value'] : null;
			$source      = $rating['source']['name'] ?? null;
			$sentences[] = sprintf(
				'Client rating: %s/5%s%s.',
				number_format( (float) $rating['value'], 1 ),
				null === $count ? '' : sprintf( ' from %s %s', number_format( $count ), 1 === $count ? 'review' : 'reviews' ),
				null === $source ? '' : ' (' . $source . ')'
			);
			$items[]     = self::fact_item( 'rating', 'Client rating', number_format( (float) $rating['value'], 1 ) . '/5' . ( null === $count ? '' : ' from ' . number_format( $count ) . ' reviews' ), $rating );
		}

		// Experience.
		$years = $facts['years_experience'] ?? null;
		if ( ! $firm && self::usable( $years ) ) {
			$sentences[] = sprintf( '%d %s in practice.', (int) $years['value'], 1 === (int) $years['value'] ? 'year' : 'years' );
			$items[]     = self::fact_item( 'years_experience', 'Experience', (int) $years['value'] . ' years', $years );
		}

		// Bar status: verified when the fact is, or when the profile's bar-status
		// check passed against an official source (the badge shows the same).
		$bar = $facts['bar_status'] ?? null;
		if ( ! $firm && self::usable( $bar ) ) {
			$state   = self::usable( $facts['bar_state'] ?? null ) ? ' (' . $facts['bar_state']['value'] . ')' : '';
			$checked = 'verified' === ( $d['verification']['checks']['bar_status'] ?? null );
			if ( 'verified' !== $bar['status'] && $checked ) {
				$bar['status']     = 'verified';
				$bar['verifiedAt'] = $d['verification']['verifiedAt'] ?? null;
			}
			$verified    = 'verified' === $bar['status'];
			$date        = self::date( $bar['verifiedAt'] ?? null );
			$sentences[] = $verified
				? sprintf( 'Bar status: %s%s, verified%s.', $bar['value'], $state, null === $date ? '' : ' ' . $date )
				: sprintf( 'Bar status on record: %s%s (not yet verified).', $bar['value'], $state );
			$items[]     = self::fact_item( 'bar_status', 'Bar status', ucfirst( (string) $bar['value'] ) . $state, $bar );
		}

		// Awards and certifications, with the body that granted them.
		$awards = $facts['awards'] ?? null;
		if ( ! $firm && self::usable( $awards ) ) {
			$names = array();
			foreach ( (array) $awards['value'] as $award ) {
				$name = trim( (string) ( is_array( $award ) ? ( $award['name'] ?? '' ) : $award ) );
				if ( '' === $name ) {
					continue;
				}
				$issuer  = is_array( $award ) ? trim( (string) ( $award['issuer'] ?? '' ) ) : '';
				$names[] = '' === $issuer ? $name : $name . ' (' . $issuer . ')';
			}
			if ( array() !== $names ) {
				$sentences[] = ( 'verified' === $awards['status'] ? 'Verified awards: ' : 'Awards on record: ' ) . self::join( $names ) . '.';
				$items[]     = self::fact_item( 'awards', 'Awards', implode( '; ', $names ), $awards );
			}
		}

		// Practice areas: say "verified" only when the fact is.
		$practice = $facts['practice_areas'] ?? null;
		if ( self::usable( $practice ) && array() !== $practices ) {
			$sentences[] = ( 'verified' === $practice['status'] ? 'Verified practice areas: ' : 'Practice areas on record: ' ) . self::join( $practices ) . '.';
			$items[]     = self::fact_item( 'practice_areas', 'Practice areas', self::join( $practices ), $practice );
		}
		$case_fact = $facts['case_types'] ?? null;
		if ( self::usable( $case_fact ) && array() !== $cases ) {
			$sentences[] = ( 'verified' === $case_fact['status'] ? 'Verified case types: ' : 'Case types on record: ' ) . self::join( $cases ) . '.';
			$items[]     = self::fact_item( 'case_types', 'Case types', self::join( $cases ), $case_fact );
		}

		// Languages.
		$languages = $facts['languages'] ?? null;
		if ( ! $firm && self::usable( $languages ) && count( (array) $languages['value'] ) > 1 ) {
			$sentences[] = 'Languages: ' . self::join( array_map( 'strval', (array) $languages['value'] ) ) . '.';
			$items[]     = self::fact_item( 'languages', 'Languages', implode( ', ', array_map( 'strval', (array) $languages['value'] ) ), $languages );
		}

		// Firm size (directory data).
		if ( $firm && isset( $d['lawyers'] ) ) {
			$n = count( (array) $d['lawyers'] );
			if ( $n > 0 ) {
				$sentences[] = sprintf( '%d %s listed on LexRanked.', $n, 1 === $n ? 'lawyer' : 'lawyers' );
			}
		}

		// Verification.
		$verified_at = $d['verification']['verifiedAt'] ?? null;
		$status      = (string) ( $d['verification']['status'] ?? 'unverified' );
		if ( 'verified' === $status && null !== self::date( $verified_at ) ) {
			$sentences[] = 'Data verified: ' . self::date( $verified_at ) . '.';
		}
		$items[] = self::item( 'verification', 'Verification', ucfirst( $status ), 'verified' === $status ? 'verified' : 'derived', $verified_at );

		return array(
			'version' => self::VERSION,
			'text'    => implode( ' ', $sentences ),
			'facts'   => $items,
			'asOf'    => self::latest( $items ),
		);
	}

	/**
	 * Whether a fact can be stated (present, sourced, not in conflict).
	 *
	 * @param array<string, mixed>|null $fact Fact DTO.
	 */
	private static function usable( ?array $fact ): bool {
		return null !== $fact && in_array( $fact['status'] ?? null, array( 'verified', 'unverified' ), true ) && null !== $fact['value'] && '' !== $fact['value'] && array() !== $fact['value'];
	}

	/**
	 * Structured item from a fact.
	 *
	 * @param string               $key   Key.
	 * @param string               $label Label.
	 * @param string               $value Display value.
	 * @param array<string, mixed> $fact  Fact DTO.
	 * @return array<string, mixed>
	 */
	private static function fact_item( string $key, string $label, string $value, array $fact ): array {
		$item           = self::item( $key, $label, $value, 'verified' === $fact['status'] ? 'verified' : 'sourced', $fact['verifiedAt'] ?? $fact['observedAt'] ?? null );
		$item['source'] = $fact['source']['name'] ?? null;
		return $item;
	}

	/**
	 * Structured item.
	 *
	 * @param string      $key    Key.
	 * @param string      $label  Label.
	 * @param string      $value  Display value.
	 * @param string      $status verified|sourced|derived.
	 * @param string|null $as_of  ISO date.
	 * @return array<string, mixed>
	 */
	private static function item( string $key, string $label, string $value, string $status, ?string $as_of ): array {
		return array(
			'key'    => $key,
			'label'  => $label,
			'value'  => $value,
			'status' => $status,
			'asOf'   => $as_of,
			'source' => null,
		);
	}

	/**
	 * "Miami, Florida".
	 *
	 * @param array<string, mixed> $location Location DTO.
	 */
	private static function where( array $location ): string {
		return implode( ', ', array_filter( array( $location['city'] ?? null, $location['state'] ?? null ) ) );
	}

	/**
	 * "September 27, 2026" from an ISO timestamp.
	 *
	 * @param mixed $iso Timestamp.
	 */
	private static function date( mixed $iso ): ?string {
		if ( ! is_string( $iso ) || '' === $iso ) {
			return null;
		}
		$time = strtotime( $iso );
		return false === $time ? null : gmdate( 'F j, Y', $time );
	}

	/**
	 * Latest date among items.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 */
	private static function latest( array $items ): ?string {
		$dates = array_filter( array_column( $items, 'asOf' ) );
		if ( array() === $dates ) {
			return null;
		}
		rsort( $dates );
		return (string) $dates[0];
	}

	/**
	 * "a" or "an".
	 *
	 * @param string $word Word.
	 */
	private static function article( string $word ): string {
		return preg_match( '/^[aeiou]/i', $word ) ? 'an' : 'a';
	}

	/**
	 * Slug to words.
	 *
	 * @param string $slug Slug.
	 */
	private static function humanize( string $slug ): string {
		return ucwords( str_replace( '-', ' ', $slug ) );
	}

	/**
	 * "a, b and c".
	 *
	 * @param array<int, string> $items Items.
	 */
	private static function join( array $items ): string {
		$items = array_values( array_unique( $items ) );
		if ( count( $items ) < 2 ) {
			return $items[0] ?? '';
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ' and ' . $last;
	}
}
