<?php
/**
 * Complete page text for a ranking, built only from the ranked lawyers' facts
 * and a verified state knowledge pack (data/knowledge/{STATE}.json).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Content;

/**
 * Pure builder: no WordPress calls, no AI. Returns null when it cannot write
 * complete, sourced content (no knowledge for the state, practice area or city),
 * so a ranking is never published as a thin page.
 */
final class RankingContentBuilder {

	/** Content contract version, stored with generated text. */
	public const VERSION = 'rc-2';

	/** Byline on generated text. */
	public const REVIEWED_BY = 'LexRanked editorial team';

	/** What the LexRank score is made of (methodology v1.2), stated the same way on every page. */
	public const SCORE_PARTS = 'experience, practice-area relevance, awards on record such as board certification, professional credentials, data quality and location';

	/** The one statement on client reviews, matching the methodology page and profiles. */
	public const REVIEWS_NOTE = 'Client reviews do not affect the LexRank score: neither star ratings nor the number of reviews is scored.';

	/**
	 * Build summary, body (HTML) and FAQ.
	 *
	 * @param array<string, mixed>                                                                                                                            $pack    State knowledge pack.
	 * @param array{area_slug: string, area_name: string, city_slug: string, city_name: string, entity_type: string, language?: string, parent_path?: string} $context Ranking context; `language` (e.g. "Spanish") for a language ranking, with the broader ranking's `parent_path`.
	 * @param array<int, array<string, mixed>>                                                                                                                $people  Ranked lawyers, in order: years ?int, languages string[], awards string[], schools string[].
	 * @param string                                                                                                                                          $checked Month the data was checked, e.g. "October 2026".
	 * @param string                                                                                                                                          $today   Review date (Y-m-d).
	 * @return array{summary: string, body: string, faq: array<int, array{question: string, answer: string}>, reviewed_by: string, reviewed_at: string}|null
	 */
	public static function build( array $pack, array $context, array $people, string $checked, string $today ): ?array {
		if ( '' === ( $context['city_slug'] ?? '' ) ) {
			return self::state( $pack, $context, $people, $checked, $today );
		}
		$area = $pack['areas'][ $context['area_slug'] ] ?? null;
		$city = $pack['cities'][ $context['city_slug'] ] ?? null;
		if ( 'lawyer' !== $context['entity_type'] || ! is_array( $area ) || ! is_array( $city ) || array() === $people ) {
			return null;
		}
		$n         = count( $people );
		$state     = (string) $pack['name'];
		$city_name = $context['city_name'];
		$noun      = self::in_sentence( (string) $context['area_name'] );
		$cert      = (string) $area['certName'];
		$stats     = self::stats( $people, (string) $area['cert'] );
		$share     = (string) $pack['certification']['share'];
		$bar       = (string) $pack['bar']['name'];
		$short     = (string) ( $pack['bar']['short'] ?? $bar );
		$e         = static fn( string $s ): string => htmlspecialchars( $s, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8' );
		$language  = isset( $context['language'] ) && '' !== $context['language'] ? (string) $context['language'] : null;
		$parent    = isset( $context['parent_path'] ) && str_starts_with( (string) $context['parent_path'], '/' ) ? (string) $context['parent_path'] : null;
		$who       = null === $language ? $noun : $language . '-speaking ' . $noun;

		// Summary: what the page is, the strongest verified common fact, the spread of experience.
		$summary = null === $language
			? sprintf( 'This ranking lists %s %s %s in %s, %s, in order of their LexRank score, calculated from their %s records and other cited sources.', $n, $noun, 1 === $n ? 'lawyer' : 'lawyers', $city_name, $state, $short )
			: sprintf( 'This ranking lists %s %s %s in %s, %s, whose %s profile lists %s, in order of their LexRank score, calculated from their %s records and other cited sources.', $n, $noun, 1 === $n ? 'lawyer' : 'lawyers', $city_name, $state, $short, $language, $short );
		if ( $stats['cert'] > 0 ) {
			$summary .= sprintf( ' %s %s, a credential held by %s of %s lawyers in any field.', self::all_or_some( $stats['cert'], $n, true ), self::verb( $stats['cert'] ) . ' ' . $cert . ' by ' . $bar, $share, $state );
		}
		if ( null !== $stats['ymin'] ) {
			$summary .= sprintf( ' Their experience ranges from %d to %d years in practice.', $stats['ymin'], $stats['ymax'] );
		}
		$spanish = $stats['languages']['Spanish'] ?? 0;
		if ( $spanish > 0 && null === $language ) {
			$summary .= sprintf( ' %d of the %d %s Spanish on their %s profile.', $spanish, $n, 1 === $spanish ? 'lists' : 'list', $short );
		}

		// Facts table about the ranked lawyers.
		$rows = array();
		if ( $stats['cert'] > 0 ) {
			$rows[] = array( $cert, sprintf( '%d of %d', $stats['cert'], $n ) );
		}
		if ( null !== $stats['ymin'] ) {
			$rows[] = array( 'Years in practice', sprintf( '%d to %d (median %s)', $stats['ymin'], $stats['ymax'], self::number( (float) $stats['ymed'] ) ) );
		}
		if ( array() !== $stats['languages'] ) {
			$langs = array();
			foreach ( array_slice( $stats['languages'], 0, 4, true ) as $lang => $count ) {
				$langs[] = sprintf( '%s (%d)', $lang, $count );
			}
			$rows[] = array( 'Languages besides English', implode( ', ', $langs ) );
		}
		$rows[] = array( 'License status with ' . $bar, 'Eligible to practice, verified for every lawyer' );
		if ( null !== $stats['school'] ) {
			$rows[] = array( 'Most common law school', sprintf( '%s (%d lawyers)', $stats['school'][0], $stats['school'][1] ) );
		}

		$lead = $stats['cert'] > 0
			? sprintf( '%s %s, which only about one in twenty %s lawyers achieves in any field.', self::all_or_some( $stats['cert'], $n, false ), self::verb( $stats['cert'] ) . ' ' . $cert . ' by ' . $bar, $state )
			: sprintf( 'Every lawyer in this ranking has a %s license verified as eligible to practice, and every fact shown links to its source.', $bar );

		$body         = array();
		$body[]       = '<h2>What sets these ' . $e( null === $language ? $city_name : $city_name . ' ' . $language . '-speaking' ) . ' lawyers apart</h2>';
		$requirements = $stats['cert'] > 0 && isset( $pack['certification']['requirements'] ) ? ' ' . $e( (string) $pack['certification']['requirements'] ) : '';
		$body[]       = '<p><strong>' . $e( $lead ) . '</strong>' . $requirements . ' The figures below come from each lawyer\'s ' . $e( $short ) . ' profile.</p>';
		$body[]       = self::table( array( 'Fact about the ' . $n . ' ranked lawyers', 'Figure' ), $rows, $e );

		$body[] = '<h2>Who is included in this ranking?</h2>';
		$body[] = '<p><strong>' . $e( sprintf( 'Lawyers eligible to practice in %s, with a %s office address and %s as a practice area on record', $state, $city_name, $noun ) . ( null === $language ? '.' : sprintf( ', whose %s profile lists %s among the languages they speak.', $short, $language ) ) ) . '</strong> '
			. $e( sprintf( 'It compares %d %s lawyers, using only facts with a cited source.', $n, $who ) ) . ' <a href="/methodology/">How we rank</a>.</p>';
		if ( null !== $parent ) {
			$body[] = '<p>' . $e( 'Whatever language they speak: ' ) . '<a href="' . $e( $parent ) . '">' . $e( sprintf( 'the full ranking of %s lawyers in %s', $noun, $city_name ) ) . '</a>.</p>';
		}

		if ( null !== $language ) {
			$body[] = '<h2>' . $e( sprintf( 'Working with a %s-speaking lawyer', $language ) ) . '</h2>';
			$body[] = '<p><strong>' . $e( sprintf( 'You can discuss your case, documents and fees directly in %s, but court hearings and filings in %s are in English.', $language, $state ) ) . '</strong></p>';
			$body[] = '<ul>';
			$body[] = '<li>' . $e( sprintf( 'Ask whether the lawyer will speak with you in %s personally or through bilingual staff.', $language ) ) . '</li>';
			$body[] = '<li>' . $e( sprintf( 'Ask for the fee agreement and key letters in %s as well as English, so you know exactly what you sign.', $language ) ) . '</li>';
			$body[] = '<li>' . $e( sprintf( 'When a witness cannot understand or speak English well enough, the judge has a qualified interpreter sworn in (section 90.606, %s Statutes).', $state ) ) . '</li>';
			$body[] = '</ul>';
		}

		$body[] = '<h2>' . $e( $state ) . ' rules to know</h2>';
		$body[] = '<p><strong>' . $e( (string) $area['lead'] ) . '</strong></p>';
		$body[] = self::table( array( 'Rule', 'What it means' ), (array) $area['rows'], $e );
		$body[] = self::table( array( 'Court', 'Where cases are heard' ), array( array( 'State court circuit', sprintf( '%s County is in %s\'s %s Judicial Circuit', $city['county'], $state, $city['circuit'] ) ) ), $e );

		$crash = 'personal-injury' === $context['area_slug'] && isset( $city['crashes'] );
		if ( $crash ) {
			$body[] = '<h2>' . $e( (string) $city['county'] ) . ' County crashes by the numbers</h2>';
			$body[] = '<p><strong>' . $e( sprintf( '%s County recorded %s traffic crashes in 2023, %s.', $city['county'], $city['crashes'], $city['crashRank'] ) ) . '</strong> ' . $e( sprintf( 'Those crashes injured %s people and killed %s, according to %s.', $city['injuries'], $city['deaths'], $pack['crashSource'][1] ) ) . '</p>';
		}

		$body[] = '<h2>How to choose among these lawyers</h2>';
		$body[] = '<p><strong>Confirm the license, ask who will handle your matter, and get the fee terms in writing before you sign.</strong></p>';
		$body[] = '<ol>';
		$body[] = '<li>' . $e( sprintf( 'Look up the lawyer in %s\'s directory and check the bar number shown on their LexRanked profile.', $bar ) ) . '</li>';
		$body[] = '<li>Ask whether the lawyer you meet will handle your matter personally, and how many matters like yours they have handled recently.</li>';
		$body[] = '<li>Ask how they charge and what costs are extra, and get the agreement in writing.</li>';
		if ( array() !== $stats['languages'] && null === $language ) {
			$body[] = '<li>If you prefer to work in another language, check the languages listed on each profile.</li>';
		}
		$body[] = '</ol>';

		$guides    = array_merge( (array) ( $area['guides'] ?? array() ), array( array( '/articles/questions-to-ask-a-lawyer/', 'Questions to ask a lawyer before hiring' ), array( '/articles/how-to-check-a-miami-lawyer-florida-bar/', 'How to check a Florida lawyer before you hire them' ) ) );
		$links     = array_map( static fn( array $g ): string => '<li><a href="' . $e( (string) $g[0] ) . '">' . $e( (string) $g[1] ) . '</a></li>', $guides );
		$body[]    = '<h2>Further reading</h2>';
		$body[]    = '<ul>' . implode( '', $links ) . '</ul>';
		$sources   = array( array( (string) $pack['bar']['directory'], $bar . ': member directory (license, certification, languages, admission dates)' ) );
		$sources[] = (array) $pack['certification']['source'];
		foreach ( (array) $area['src'] as $src ) {
			$sources[] = (array) $src;
		}
		if ( $crash ) {
			$sources[] = (array) $pack['crashSource'];
		}
		if ( null !== $language ) {
			$sources[] = array( 'https://www.flsenate.gov/Laws/Statutes/2025/90.606', $state . ' Statutes, section 90.606: interpreters and translators' );
		}
		$body[] = '<h2>Sources for the rules above</h2>';
		$body[] = '<ul>' . implode( '', array_map( static fn( array $s ): string => '<li><a href="' . $e( (string) $s[0] ) . '">' . $e( (string) $s[1] ) . '</a></li>', $sources ) ) . '</ul>';

		// FAQ: the area's verified questions, then questions answered by this ranking's own data.
		$faq = array();
		foreach ( (array) $area['faq'] as $item ) {
			$faq[] = array(
				'question' => (string) $item[0],
				'answer'   => (string) $item[1],
			);
		}
		if ( $stats['cert'] > 0 ) {
			$faq[] = array(
				'question' => sprintf( 'What does %s mean?', $cert ),
				'answer'   => sprintf( 'It is a credential from %s for lawyers with substantial experience in the field who have passed peer review and a written exam. %d of the %d lawyers in this ranking hold it; %s of %s lawyers hold a board certification in any field.', $bar, $stats['cert'], $n, $share, $state ),
			);
		}
		$faq[] = array(
			'question' => sprintf( 'Which court handles cases in %s?', $city_name ),
			'answer'   => sprintf( 'State court cases in %s County are heard in %s\'s %s Judicial Circuit.', $city['county'], $state, $city['circuit'] ) . ( 'immigration' === $context['area_slug'] ? ' Immigration cases are decided by USCIS and the federal immigration courts instead.' : '' ),
		);
		if ( null !== $language ) {
			$faq[] = array(
				'question' => sprintf( 'How do I know a lawyer really speaks %s?', $language ),
				'answer'   => sprintf( 'Every lawyer here lists %s on their %s profile, which LexRanked checked in %s. Ask at the first call whether the lawyer will handle your matter in %s personally.', $language, $short, $checked, $language ),
			);
			$faq[] = array(
				'question' => sprintf( 'Will my court hearing be in %s?', $language ),
				'answer'   => sprintf( 'No. Court proceedings in %s are held in English. When a witness cannot understand or speak English well enough, the judge has a qualified interpreter sworn in (section 90.606, %s Statutes).', $state, $state ),
			);
		} elseif ( $spanish > 0 ) {
			$faq[] = array(
				'question' => sprintf( 'Are there Spanish-speaking %s lawyers in %s?', $noun, $city_name ),
				'answer'   => sprintf( 'Yes. %d of the %d ranked lawyers %s Spanish on their %s profile. Each LexRanked profile shows the languages on record.', $spanish, $n, 1 === $spanish ? 'lists' : 'list', $short ),
			);
		}
		$faq[] = array(
			'question' => 'How is this ranking ordered?',
			'answer'   => 'By the LexRank score, calculated only from facts with a cited source: ' . self::SCORE_PARTS . '. ' . self::REVIEWS_NOTE . ' Payment never changes a position.',
		);

		return array(
			'summary'     => $summary,
			'body'        => implode( "\n", $body ),
			'faq'         => $faq,
			'reviewed_by' => self::REVIEWED_BY,
			'reviewed_at' => $today,
		);
	}

	/**
	 * Statewide ranking ("Best Personal Injury Lawyers in Florida"): the top
	 * lawyers across every city we track, with where they practice and links
	 * to each city's ranking. Context keys: area_slug, area_name, entity_type,
	 * tracked (lawyers scored statewide), city_rankings ([path, city name]).
	 * People carry `city`.
	 *
	 * @param array<string, mixed>             $pack    State knowledge pack.
	 * @param array<string, mixed>             $context Ranking context.
	 * @param array<int, array<string, mixed>> $people  Ranked lawyers, in order.
	 * @param string                           $checked Month the data was checked.
	 * @param string                           $today   Review date (Y-m-d).
	 * @return array{summary: string, body: string, faq: array<int, array{question: string, answer: string}>, reviewed_by: string, reviewed_at: string}|null
	 */
	private static function state( array $pack, array $context, array $people, string $checked, string $today ): ?array {
		$area = $pack['areas'][ $context['area_slug'] ] ?? null;
		if ( 'lawyer' !== $context['entity_type'] || ! is_array( $area ) || array() === $people ) {
			return null;
		}
		$n       = count( $people );
		$tracked = max( $n, (int) ( $context['tracked'] ?? $n ) );
		$state   = (string) $pack['name'];
		$noun    = self::in_sentence( (string) $context['area_name'] );
		$cert    = (string) $area['certName'];
		$stats   = self::stats( $people, (string) $area['cert'] );
		$share   = (string) $pack['certification']['share'];
		$bar     = (string) $pack['bar']['name'];
		$short   = (string) ( $pack['bar']['short'] ?? $bar );
		$e       = static fn( string $s ): string => htmlspecialchars( $s, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8' );

		$cities = array();
		foreach ( $people as $p ) {
			$city = (string) ( $p['city'] ?? '' );
			if ( '' !== $city ) {
				$cities[ $city ] = ( $cities[ $city ] ?? 0 ) + 1;
			}
		}
		uksort( $cities, static fn( string $a, string $b ): int => array( $cities[ $b ], $a ) <=> array( $cities[ $a ], $b ) );

		$summary = sprintf( 'This ranking lists the top %d of the %d %s lawyers LexRanked tracks across %s, in order of their LexRank score, calculated from their %s records and other cited sources.', $n, $tracked, $noun, $state, $short );
		if ( $stats['cert'] > 0 ) {
			$summary .= sprintf( ' %s %s, a credential held by %s of %s lawyers in any field.', self::all_or_some( $stats['cert'], $n, true ), self::verb( $stats['cert'] ) . ' ' . $cert . ' by ' . $bar, $share, $state );
		}
		if ( array() !== $cities ) {
			$summary .= sprintf( ' They practice in %d %s, led by %s.', count( $cities ), 1 === count( $cities ) ? 'city' : 'cities', (string) array_key_first( $cities ) );
		}

		$rows = array();
		if ( $stats['cert'] > 0 ) {
			$rows[] = array( $cert, sprintf( '%d of %d', $stats['cert'], $n ) );
		}
		if ( null !== $stats['ymin'] ) {
			$rows[] = array( 'Years in practice', sprintf( '%d to %d (median %s)', $stats['ymin'], $stats['ymax'], self::number( (float) $stats['ymed'] ) ) );
		}
		if ( array() !== $stats['languages'] ) {
			$langs = array();
			foreach ( array_slice( $stats['languages'], 0, 4, true ) as $lang => $count ) {
				$langs[] = sprintf( '%s (%d)', $lang, $count );
			}
			$rows[] = array( 'Languages besides English', implode( ', ', $langs ) );
		}
		$rows[] = array( 'License status with ' . $bar, 'Eligible to practice, verified for every lawyer' );

		$body   = array();
		$body[] = '<h2>' . $e( sprintf( 'What sets these %s %s lawyers apart', $state, $noun ) ) . '</h2>';
		$lead   = $stats['cert'] > 0
			? sprintf( '%s %s, which only about one in twenty %s lawyers achieves in any field.', self::all_or_some( $stats['cert'], $n, false ), self::verb( $stats['cert'] ) . ' ' . $cert . ' by ' . $bar, $state )
			: sprintf( 'Every lawyer in this ranking has a %s license verified as eligible to practice, and every fact shown links to its source.', $bar );
		$body[] = '<p><strong>' . $e( $lead ) . '</strong> The figures come from each lawyer\'s ' . $e( $short ) . ' profile, checked in ' . $e( $checked ) . '.</p>';
		$body[] = self::table( array( 'Fact about the ' . $n . ' ranked lawyers', 'Figure' ), $rows, $e );

		if ( array() !== $cities ) {
			$body[]    = '<h2>' . $e( sprintf( 'Where do %s\'s top %s lawyers practice?', $state, $noun ) ) . '</h2>';
			$body[]    = '<p><strong>' . $e( sprintf( '%d of the %d practice in %s; the table shows every city.', reset( $cities ), $n, (string) array_key_first( $cities ) ) ) . '</strong></p>';
			$city_rows = array();
			foreach ( $cities as $city => $count ) {
				$city_rows[] = array( (string) $city, (string) $count );
			}
			$body[] = self::table( array( 'City', 'Lawyers in this ranking' ), $city_rows, $e );
		}

		$body[] = '<h2>Who is included in this ranking?</h2>';
		$body[] = '<p><strong>' . $e( sprintf( 'Lawyers eligible to practice in %s, with an office in a %s city we cover and %s as a practice area on record.', $state, $state, $noun ) ) . '</strong> '
			. $e( sprintf( 'It ranks every %s lawyer LexRanked tracks in %s by the same score and shows the top %d.', $noun, $state, $n ) ) . ' <a href="/methodology/">How we rank</a>.</p>';

		$city_links = array_values( array_filter( (array) ( $context['city_rankings'] ?? array() ), static fn( $c ): bool => is_array( $c ) && str_starts_with( (string) ( $c[0] ?? '' ), '/' ) ) );
		if ( array() !== $city_links ) {
			$body[] = '<h2>' . $e( sprintf( 'Find a %s lawyer in your city', $noun ) ) . '</h2>';
			$body[] = '<p><strong>' . $e( sprintf( 'A lawyer near you knows the local courts; each city has its own ranking of %s lawyers.', $noun ) ) . '</strong></p>';
			$body[] = '<ul>' . implode( '', array_map( static fn( array $c ): string => '<li><a href="' . $e( (string) $c[0] ) . '">' . $e( sprintf( 'Best %s lawyers in %s', $noun, (string) $c[1] ) ) . '</a></li>', $city_links ) ) . '</ul>';
		}

		$body[] = '<h2>' . $e( $state ) . ' rules to know</h2>';
		$body[] = '<p><strong>' . $e( (string) $area['lead'] ) . '</strong></p>';
		$body[] = self::table( array( 'Rule', 'What it means' ), (array) $area['rows'], $e );
		$body[] = '<p>Cases are heard in the county where they arise; see <a href="/data/florida-judicial-circuits/">which judicial circuit covers each county</a>.</p>';

		$body[] = '<h2>How to choose among these lawyers</h2>';
		$body[] = '<p><strong>Confirm the license, prefer a lawyer who regularly works in your county, ask who will handle your matter, and get the fee terms in writing.</strong></p>';
		$body[] = '<ol>';
		$body[] = '<li>' . $e( sprintf( 'Look up the lawyer in %s\'s directory and check the bar number shown on their LexRanked profile.', $bar ) ) . '</li>';
		$body[] = '<li>Ask how many matters like yours the lawyer has handled in your county recently.</li>';
		$body[] = '<li>Ask how they charge and what costs are extra, and get the agreement in writing.</li>';
		$body[] = '</ol>';

		$guides    = array_merge( (array) ( $area['guides'] ?? array() ), array( array( '/articles/questions-to-ask-a-lawyer/', 'Questions to ask a lawyer before hiring' ), array( '/articles/how-to-check-a-miami-lawyer-florida-bar/', 'How to check a Florida lawyer before you hire them' ) ) );
		$body[]    = '<h2>Further reading</h2>';
		$body[]    = '<ul>' . implode( '', array_map( static fn( array $g ): string => '<li><a href="' . $e( (string) $g[0] ) . '">' . $e( (string) $g[1] ) . '</a></li>', $guides ) ) . '</ul>';
		$sources   = array( array( (string) $pack['bar']['directory'], $bar . ': member directory (license, certification, languages, admission dates)' ) );
		$sources[] = (array) $pack['certification']['source'];
		foreach ( (array) $area['src'] as $src ) {
			$sources[] = (array) $src;
		}
		$body[] = '<h2>Sources for the rules above</h2>';
		$body[] = '<ul>' . implode( '', array_map( static fn( array $s ): string => '<li><a href="' . $e( (string) $s[0] ) . '">' . $e( (string) $s[1] ) . '</a></li>', $sources ) ) . '</ul>';

		$faq = array();
		foreach ( (array) $area['faq'] as $item ) {
			$faq[] = array(
				'question' => (string) $item[0],
				'answer'   => (string) $item[1],
			);
		}
		if ( $stats['cert'] > 0 ) {
			$faq[] = array(
				'question' => sprintf( 'What does %s mean?', $cert ),
				'answer'   => sprintf( 'It is a credential from %s for lawyers with substantial experience in the field who have passed peer review and a written exam. %d of the %d lawyers in this ranking hold it; %s of %s lawyers hold a board certification in any field.', $bar, $stats['cert'], $n, $share, $state ),
			);
		}
		if ( array() !== $cities ) {
			$faq[] = array(
				'question' => sprintf( 'Where are the best %s lawyers in %s?', $noun, $state ),
				'answer'   => sprintf( 'Of the %d lawyers in this ranking, %s. Each city also has its own ranking.', $n, implode( ', ', array_map( static fn( string $c, int $k ): string => sprintf( '%d %s in %s', $k, 1 === $k ? 'practices' : 'practice', $c ), array_slice( array_keys( $cities ), 0, 3 ), array_slice( array_values( $cities ), 0, 3 ) ) ) ),
			);
		}
		$faq[] = array(
			'question' => sprintf( 'Do I need a %s lawyer in my own city?', $noun ),
			'answer'   => sprintf( 'Any lawyer licensed by %s may practice anywhere in %s, but a lawyer who regularly appears in your county knows its judges and procedures.', $bar, $state ),
		);
		$faq[] = array(
			'question' => 'How is this ranking ordered?',
			'answer'   => 'By the LexRank score, calculated only from facts with a cited source: ' . self::SCORE_PARTS . '. ' . self::REVIEWS_NOTE . ' Payment never changes a position.',
		);

		return array(
			'summary'     => $summary,
			'body'        => implode( "\n", $body ),
			'faq'         => $faq,
			'reviewed_by' => self::REVIEWED_BY,
			'reviewed_at' => $today,
		);
	}

	/**
	 * Figures about the ranked lawyers.
	 *
	 * @param array<int, array<string, mixed>> $people People.
	 * @param string                           $cert   Certification key matched in award names, e.g. "Civil Trial".
	 * @return array{cert: int, ymin: int|null, ymax: int|null, ymed: float|null, languages: array<string, int>, school: array{0: string, 1: int}|null}
	 */
	public static function stats( array $people, string $cert ): array {
		$years     = array();
		$languages = array();
		$schools   = array();
		$certified = 0;
		foreach ( $people as $p ) {
			if ( isset( $p['years'] ) && is_int( $p['years'] ) ) {
				$years[] = $p['years'];
			}
			foreach ( array_unique( array_map( 'strval', (array) ( $p['languages'] ?? array() ) ) ) as $lang ) {
				if ( 'English' !== $lang && '' !== $lang ) {
					$languages[ $lang ] = ( $languages[ $lang ] ?? 0 ) + 1;
				}
			}
			foreach ( (array) ( $p['awards'] ?? array() ) as $award ) {
				if ( '' !== $cert && false !== stripos( (string) $award, 'Board Certified' ) && false !== stripos( (string) $award, $cert ) ) {
					++$certified;
					break;
				}
			}
			foreach ( array_unique( array_map( 'strval', (array) ( $p['schools'] ?? array() ) ) ) as $school ) {
				if ( '' !== $school ) {
					$schools[ $school ] = ( $schools[ $school ] ?? 0 ) + 1;
				}
			}
		}//end foreach
		uksort( $languages, static fn( string $a, string $b ): int => array( $languages[ $b ], $a ) <=> array( $languages[ $a ], $b ) );
		uksort( $schools, static fn( string $a, string $b ): int => array( $schools[ $b ], $a ) <=> array( $schools[ $a ], $b ) );
		$school = null;
		if ( array() !== $schools && reset( $schools ) >= 2 ) {
			$school = array( (string) array_key_first( $schools ), (int) reset( $schools ) );
		}
		sort( $years );
		$count = count( $years );
		return array(
			'cert'      => $certified,
			'ymin'      => 0 === $count ? null : $years[0],
			'ymax'      => 0 === $count ? null : $years[ $count - 1 ],
			'ymed'      => 0 === $count ? null : ( 1 === $count % 2 ? (float) $years[ intdiv( $count, 2 ) ] : ( $years[ $count / 2 - 1 ] + $years[ $count / 2 ] ) / 2 ),
			'languages' => $languages,
			'school'    => $school,
		);
	}

	/**
	 * "All 12 lawyers in this ranking" / "7 of the 12 lawyers in this ranking".
	 *
	 * @param int  $part    Count with the fact.
	 * @param int  $n       Ranked lawyers.
	 * @param bool $leading Whether it opens a sentence after another one.
	 */
	private static function all_or_some( int $part, int $n, bool $leading ): string {
		if ( $part === $n ) {
			if ( 1 === $n ) {
				return 'The lawyer in this ranking';
			}
			return 2 === $n ? 'Both lawyers in this ranking' : sprintf( 'All %d lawyers in this ranking', $n );
		}
		return $leading ? sprintf( '%d of the %d', $part, $n ) : sprintf( '%d of the %d lawyers in this ranking', $part, $n );
	}

	/**
	 * Area name as a noun inside a sentence: lowercase, except acronyms
	 * ("Condo and HOA" becomes "condo and HOA", "DUI" stays "DUI").
	 *
	 * @param string $name Area name.
	 */
	public static function in_sentence( string $name ): string {
		return (string) preg_replace_callback(
			'/[A-Za-z\']+/',
			static fn( array $m ): string => preg_match( '/^[A-Z]{2,}$/', $m[0] ) ? $m[0] : strtolower( $m[0] ),
			$name
		);
	}

	/**
	 * Verb agreeing with a count.
	 *
	 * @param int $count Count.
	 */
	private static function verb( int $count ): string {
		return 1 === $count ? 'is' : 'are';
	}

	/**
	 * 12 or 12.5.
	 *
	 * @param float $x Number.
	 */
	private static function number( float $x ): string {
		return floor( $x ) === $x ? (string) (int) $x : number_format( $x, 1, '.', '' );
	}

	/**
	 * An escaped HTML table.
	 *
	 * @param array<int, string>             $head Header cells.
	 * @param array<int, array<int, string>> $rows Rows.
	 * @param callable                       $e    Escaper.
	 */
	private static function table( array $head, array $rows, callable $e ): string {
		$out = '<table><thead><tr>' . implode( '', array_map( static fn( string $h ): string => '<th>' . $e( $h ) . '</th>', $head ) ) . '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$out .= '<tr>' . implode( '', array_map( static fn( $c ): string => '<td>' . $e( (string) $c ) . '</td>', (array) $row ) ) . '</tr>';
		}
		return $out . '</tbody></table>';
	}
}
