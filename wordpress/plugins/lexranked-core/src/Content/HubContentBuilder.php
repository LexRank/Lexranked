<?php
/**
 * Complete page text for hubs: a practice area, a city and a state.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Content;

/**
 * Pure builder: no WordPress calls, no AI. Text comes from the published
 * rankings (paths and entry counts) and the verified state knowledge pack;
 * every section opens with a bold, direct answer to its heading. Returns null
 * when there is nothing to describe (no published ranking) or no knowledge.
 */
final class HubContentBuilder {

	/** Content contract version, stored with generated text. */
	public const VERSION = 'hc-1';

	/**
	 * Practice area hub.
	 *
	 * @param array<string, mixed>                                                                                                $pack     State knowledge pack.
	 * @param array{area_slug: string, area_name: string, rankings: array<int, array{path: string, city: ?string, entries: int}>} $context  Published rankings of the area in the state (context rankings left out); `city` null for the statewide one.
	 * @param string                                                                                                              $checked  Month the data was checked, e.g. "October 2026".
	 * @param string                                                                                                              $today    Review date (Y-m-d).
	 * @return array{summary: string, body: string, faq: array<int, array{question: string, answer: string}>, reviewed_by: string, reviewed_at: string}|null
	 */
	public static function area( array $pack, array $context, string $checked, string $today ): ?array {
		$area = $pack['areas'][ $context['area_slug'] ] ?? null;
		if ( ! is_array( $area ) ) {
			return null;
		}
		$statewide = null;
		$cities    = array();
		foreach ( $context['rankings'] as $r ) {
			if ( null === $r['city'] ) {
				$statewide = $r;
			} else {
				$cities[] = $r;
			}
		}
		if ( null === $statewide && array() === $cities ) {
			return null;
		}
		usort( $cities, static fn( array $a, array $b ): int => array( $b['entries'], $a['city'] ) <=> array( $a['entries'], $b['city'] ) );
		$e       = self::escaper();
		$state   = (string) $pack['name'];
		$noun    = RankingContentBuilder::in_sentence( $context['area_name'] );
		$hub     = is_array( $area['hub'] ?? null ) ? $area['hub'] : null;
		$cert    = (string) $area['certName'];
		$short   = (string) $pack['bar']['short'];
		$bar     = (string) $pack['bar']['name'];
		$tracked = null === $statewide ? array_sum( array_column( $cities, 'entries' ) ) : $statewide['entries'];
		$n_city  = count( $cities );
		$top     = array_slice( $cities, 0, 3 );
		$leaders = self::join( array_map( static fn( array $c ): string => sprintf( '%s (%d lawyers)', $c['city'], $c['entries'] ), $top ) );

		$summary = sprintf( 'LexRanked ranks %d %s lawyers in %s', $tracked, $noun, $state )
			. ( $n_city > 0 ? sprintf( ' in %s', self::count_noun( $n_city, 'city', 'cities' ) ) : '' )
			. ( '' !== $leaders ? sprintf( '; the largest city rankings are %s.', $leaders ) : '.' )
			. sprintf( ' Every position follows the LexRank score, calculated only from facts with a cited source, such as each lawyer\'s %s profile; payment never changes a position.', $short );

		$body = array();
		if ( null !== $hub ) {
			$body[] = '<h2>' . $e( sprintf( 'What does a %s lawyer do?', $noun ) ) . '</h2>';
			$body[] = '<p><strong>' . $e( (string) $hub['what'] ) . '</strong></p>';
			$body[] = self::bullets( (array) $hub['matters'], $e );
			$body[] = '<h2>' . $e( sprintf( 'When do you need a %s lawyer?', $noun ) ) . '</h2>';
			$body[] = '<p><strong>' . $e( (string) $hub['whenLead'] ) . '</strong></p>';
			$body[] = self::bullets( (array) $hub['when'], $e );
		}

		$body[] = '<h2>' . $e( sprintf( 'Who are the best %s lawyers in %s?', $noun, $state ) ) . '</h2>';
		if ( null !== $statewide ) {
			$body[] = '<p><strong>' . $e( sprintf( 'The statewide ranking lists the top %d of the %d %s lawyers LexRanked tracks in %s, in order of their LexRank score.', min( 25, $tracked ), $tracked, $noun, $state ) ) . '</strong> '
				. '<a href="' . $e( $statewide['path'] ) . '">' . $e( sprintf( 'See the best %s lawyers in %s', $noun, $state ) ) . '</a>.</p>';
		} else {
			$body[] = '<p><strong>' . $e( sprintf( 'LexRanked ranks %s lawyers city by city; each ranking orders lawyers with an office in that city by their LexRank score.', $noun ) ) . '</strong></p>';
		}

		if ( $n_city > 0 ) {
			$body[] = '<h2>' . $e( sprintf( 'Where can I find a %s lawyer in %s?', $noun, $state ) ) . '</h2>';
			$body[] = '<p><strong>' . $e( sprintf( '%s rankings cover %s; %s has the most lawyers compared (%d).', ucfirst( $noun ), self::count_noun( $n_city, $state . ' city', $state . ' cities' ), $cities[0]['city'], $cities[0]['entries'] ) ) . '</strong></p>';
			$rows   = array_map( static fn( array $c ): string => '<tr><td><a href="' . $e( $c['path'] ) . '">' . $e( sprintf( 'Best %s lawyers in %s', $noun, $c['city'] ) ) . '</a></td><td>' . (int) $c['entries'] . '</td></tr>', $cities );
			$body[] = '<table><thead><tr><th>City ranking</th><th>Lawyers compared</th></tr></thead><tbody>' . implode( '', $rows ) . '</tbody></table>';
		}

		$cost = null === $hub ? null : (string) $hub['cost'];
		if ( null !== $cost ) {
			$body[] = '<h2>' . $e( sprintf( 'How much does a %s lawyer cost in %s?', $noun, $state ) ) . '</h2>';
			$body[] = '<p><strong>' . $e( $cost ) . '</strong> ' . $e( sprintf( 'Under %s\'s rules, a lawyer who has not represented you before must explain the basis or rate of the fee and costs, preferably in writing, and no fee may be clearly excessive.', $bar ) ) . '</p>';
		}

		$body[] = '<h2>' . $e( sprintf( '%s %s rules to know', $state, $noun ) ) . '</h2>';
		$body[] = '<p><strong>' . $e( (string) $area['lead'] ) . '</strong></p>';
		$body[] = self::table( array( 'Rule', 'What it means' ), (array) $area['rows'], $e );

		$body[] = '<h2>' . $e( sprintf( 'What does %s mean?', $cert ) ) . '</h2>';
		$body[] = '<p><strong>' . $e( sprintf( 'It is a credential from %s for lawyers with substantial experience in the field who have passed peer review and a written exam.', $bar ) ) . '</strong> '
			. $e( (string) ( $pack['certification']['requirements'] ?? '' ) ) . ' '
			. $e( sprintf( 'About %s of %s lawyers hold a board certification in any field.', (string) $pack['certification']['share'], $state ) ) . '</p>';

		$body[] = '<h2>' . $e( sprintf( 'How to choose a %s lawyer', $noun ) ) . '</h2>';
		$body[] = '<p><strong>' . $e( sprintf( 'Confirm the license in %s\'s directory, then compare experience, certification and how often the lawyer handles cases like yours.', $bar ) ) . '</strong></p>';
		$steps  = array(
			sprintf( 'Look up the lawyer in %s\'s directory: the license must show as eligible to practice, and public discipline is listed there.', $bar ),
			sprintf( 'Check for %s, which requires peer review and an exam.', $cert ),
			'Ask for the fee agreement in writing before work starts.',
		);
		$steps  = array_merge( $steps, array_map( static fn( string $q ): string => 'Ask: ' . $q, null === $hub ? array() : (array) $hub['questions'] ) );
		$body[] = '<ol>' . implode( '', array_map( static fn( string $s ): string => '<li>' . $e( $s ) . '</li>', $steps ) ) . '</ol>';
		$body[] = '<h2>' . $e( sprintf( 'How LexRanked ranks %s lawyers', $noun ) ) . '</h2>';
		$body[] = '<p><strong>' . $e( 'By the LexRank score: experience, credentials, practice relevance, location and data quality, using only facts with a cited source.' ) . '</strong> '
			. $e( sprintf( 'Data checked %s against each lawyer\'s %s profile. Payment never changes a position.', $checked, $short ) ) . ' <a href="/methodology/">See how we rank</a>.</p>';

		$guides = array_values( array_filter( (array) ( $area['guides'] ?? array() ), static fn( $g ): bool => is_array( $g ) && self::published( $pack, (string) ( $g[0] ?? '' ) ) ) );
		if ( array() !== $guides ) {
			$body[] = '<h2>Guides</h2>';
			$body[] = '<p><strong>' . $e( sprintf( 'Read these before you hire a %s lawyer:', $noun ) ) . '</strong></p>';
			$body[] = '<ul>' . implode( '', array_map( static fn( array $g ): string => '<li><a href="' . $e( (string) $g[0] ) . '">' . $e( (string) $g[1] ) . '</a></li>', $guides ) ) . '</ul>';
		}
		$sources = array_merge( (array) $area['src'], null === $hub ? array() : (array) ( $hub['costSrc'] ?? array() ), self::fee_source( $pack, null !== $cost ), array( array( (string) $pack['bar']['directory'], $bar . ': Find a Lawyer directory' ) ) );
		$body[]  = '<h2>Sources</h2>';
		$body[]  = self::links( $sources, $e );

		$faq = array();
		if ( null !== $hub ) {
			$faq[] = self::qa( sprintf( 'What does a %s lawyer do?', $noun ), (string) $hub['what'] );
			$faq[] = self::qa( sprintf( 'How much does a %s lawyer cost in %s?', $noun, $state ), (string) $hub['cost'] );
		}
		foreach ( (array) $area['faq'] as $item ) {
			$faq[] = self::qa( (string) $item[0], (string) $item[1] );
		}
		if ( $n_city > 0 ) {
			$faq[] = self::qa(
				sprintf( 'Where are the best %s lawyers in %s?', $noun, $state ),
				sprintf( 'LexRanked ranks %s lawyers in %s; the largest rankings are %s.', $noun, self::count_noun( $n_city, 'city', 'cities' ), $leaders ) . ( null === $statewide ? '' : ' The statewide ranking compares all of them.' )
			);
		}
		$faq[] = self::qa(
			sprintf( 'How do I check a %s lawyer\'s license in %s?', $noun, $state ),
			sprintf( 'Search the lawyer\'s name in %s\'s Find a Lawyer directory: it shows whether the lawyer is eligible to practice, any board certification and public discipline. Concerns about a lawyer go to %s.', $bar, (string) $pack['bar']['complaints'] )
		);
		return self::result( $summary, $body, $faq, $today );
	}

	/**
	 * City hub.
	 *
	 * @param array<string, mixed>                                                                                                                                                                                      $pack    State knowledge pack.
	 * @param array{city_slug: string, city_name: string, lawyers: int, rankings: array<int, array{path: string, area: string, area_slug: string, entries: int, language?: ?string}>, statewide: array<string, string>} $context Published rankings in the city; `language` set for a language ranking; `statewide` maps area slug to the statewide ranking path.
	 * @param string                                                                                                                                                                                                    $checked Month the data was checked.
	 * @param string                                                                                                                                                                                                    $today   Review date (Y-m-d).
	 * @return array{summary: string, body: string, faq: array<int, array{question: string, answer: string}>, reviewed_by: string, reviewed_at: string}|null
	 */
	public static function city( array $pack, array $context, string $checked, string $today ): ?array {
		$city = $pack['cities'][ $context['city_slug'] ] ?? null;
		if ( ! is_array( $city ) ) {
			return null;
		}
		$main  = array_values( array_filter( $context['rankings'], static fn( array $r ): bool => null === ( $r['language'] ?? null ) ) );
		$langs = array_values( array_filter( $context['rankings'], static fn( array $r ): bool => null !== ( $r['language'] ?? null ) ) );
		if ( array() === $main ) {
			return null;
		}
		usort( $main, static fn( array $a, array $b ): int => array( $b['entries'], $a['area'] ) <=> array( $a['entries'], $b['area'] ) );
		$e       = self::escaper();
		$state   = (string) $pack['name'];
		$name    = $context['city_name'];
		$bar     = (string) $pack['bar']['name'];
		$short   = (string) $pack['bar']['short'];
		$county  = (string) $city['county'];
		$circuit = (string) $city['circuit'];
		$n_area  = count( $main );
		$leaders = self::join( array_map( static fn( array $r ): string => sprintf( '%s (%d lawyers)', $r['area'], $r['entries'] ), array_slice( $main, 0, 3 ) ) );
		$court   = sprintf( 'State court cases in %s are heard in %s County, which %s\'s %s Judicial Circuit serves.', $name, $county, $state, $circuit );
		$lawyers = max( (int) $context['lawyers'], (int) max( array_column( $main, 'entries' ) ) );
		$summary = sprintf( 'LexRanked lists %d lawyers with an office in %s, %s, and ranks them in %s; the largest rankings are %s. ', $lawyers, $name, $state, self::count_noun( $n_area, 'practice area', 'practice areas' ), $leaders ) . $court;

		$body   = array();
		$body[] = '<h2>' . $e( sprintf( 'Which practice areas does LexRanked rank in %s?', $name ) ) . '</h2>';
		$body[] = '<p><strong>' . $e( sprintf( '%s, each a separate ranking of lawyers with an office in %s, ordered by the LexRank score.', ucfirst( self::count_noun( $n_area, 'practice area', 'practice areas' ) ), $name ) ) . '</strong></p>';
		$rows   = array();
		foreach ( $main as $r ) {
			$wide   = $context['statewide'][ $r['area_slug'] ] ?? null;
			$rows[] = '<tr><td><a href="' . $e( $r['path'] ) . '">' . $e( $r['area'] ) . '</a></td><td>' . (int) $r['entries'] . '</td><td>'
				. ( null === $wide ? '-' : '<a href="' . $e( $wide ) . '">' . $e( sprintf( '%s ranking', $state ) ) . '</a>' ) . '</td></tr>';
		}
		$body[] = '<table><thead><tr><th>Practice area</th><th>Lawyers compared</th><th>Statewide ranking</th></tr></thead><tbody>' . implode( '', $rows ) . '</tbody></table>';
		if ( array() !== $langs ) {
			$body[] = '<h2>' . $e( sprintf( 'Are there lawyers in %s who speak another language?', $name ) ) . '</h2>';
			$list   = self::join( array_values( array_unique( array_map( static fn( array $r ): string => (string) $r['language'], $langs ) ) ) );
			$body[] = '<p><strong>' . $e( sprintf( 'Yes. LexRanked ranks %s-speaking lawyers in %s, based on the languages on each lawyer\'s %s profile.', $list, $name, $short ) ) . '</strong></p>';
			$body[] = '<ul>' . implode( '', array_map( static fn( array $r ): string => '<li><a href="' . $e( $r['path'] ) . '">' . $e( sprintf( '%s-speaking %s lawyers in %s', (string) $r['language'], RankingContentBuilder::in_sentence( $r['area'] ), $name ) ) . '</a> (' . (int) $r['entries'] . ')</li>', $langs ) ) . '</ul>';
		}

		$body[] = '<h2>' . $e( sprintf( 'Which court handles cases in %s?', $name ) ) . '</h2>';
		$body[] = '<p><strong>' . $e( $court ) . '</strong> ' . $e( 'Federal cases go to the U.S. District Court for the district that covers the county.' ) . ' <a href="/data/florida-judicial-circuits/">' . $e( sprintf( 'See every %s judicial circuit', $state ) ) . '</a>.</p>';
		$facts  = array( array( 'County', $county . ' County' ), array( 'State court circuit', sprintf( '%s Judicial Circuit of %s', $circuit, $state ) ) );
		if ( isset( $city['crashes'], $pack['crashSource'][1] ) ) {
			$facts[] = array( sprintf( 'Traffic crashes in %s County, 2023', $county ), sprintf( '%s (%s)', $city['crashes'], $pack['crashSource'][1] ) );
		}
		$body[] = self::table( array( 'Fact', 'Detail' ), $facts, $e );

		$body[] = '<h2>' . $e( sprintf( 'How do I choose a lawyer in %s?', $name ) ) . '</h2>';
		$body[] = '<p><strong>' . $e( sprintf( 'Start with the ranking for your type of case, confirm the license in %s\'s directory, and ask about experience with cases like yours in %s County.', $bar, $county ) ) . '</strong></p>';
		$body[] = '<ol>' . implode(
			'',
			array_map(
				static fn( string $s ): string => '<li>' . $e( $s ) . '</li>',
				array(
					'Pick the practice area that matches your problem; each ranking lists only lawyers who practice in it.',
					sprintf( 'Check the license, any board certification and public discipline in %s\'s Find a Lawyer directory.', $bar ),
					sprintf( 'Ask how often the lawyer appears before the judges of the %s Judicial Circuit.', $circuit ),
					'Get the fee agreement in writing before work starts.',
				)
			)
		) . '</ol>';

		$body[]  = '<h2>' . $e( sprintf( 'How do I check a %s lawyer\'s license?', $name ) ) . '</h2>';
		$body[]  = '<p><strong>' . $e( sprintf( '%s is the official source for license status, discipline and certification; every LexRanked profile links to it.', $bar ) ) . '</strong></p>';
		$body[]  = self::table(
			array( 'What you want to know', 'Where to find it' ),
			array(
				array( sprintf( 'Is the lawyer licensed to practice in %s?', $state ), sprintf( '%s\'s Find a Lawyer directory (status "eligible to practice")', $bar ) ),
				array( 'Any public discipline?', sprintf( 'The lawyer\'s %s profile', $short ) ),
				array( 'Is the lawyer board certified?', sprintf( '%s board certification, listed on the profile', $bar ) ),
				array( 'A concern about a lawyer\'s conduct', (string) $pack['bar']['complaints'] ),
			),
			$e
		);
		$body[]  = '<h2>' . $e( sprintf( 'How LexRanked ranks lawyers in %s', $name ) ) . '</h2>';
		$body[]  = '<p><strong>' . $e( 'By the LexRank score, calculated only from facts with a cited source; payment never changes a position.' ) . '</strong> '
			. $e( sprintf( 'Data checked %s against each lawyer\'s %s profile.', $checked, $short ) ) . ' <a href="/methodology/">See how we rank</a>.'
			. ( self::published( $pack, '/articles/questions-to-ask-a-lawyer/' ) ? ' Before you hire, read <a href="/articles/questions-to-ask-a-lawyer/">questions to ask a lawyer</a>.' : '' ) . '</p>';
		$sources = array( array( (string) $pack['bar']['directory'], $bar . ': Find a Lawyer directory' ) );
		foreach ( (array) ( $pack['courts']['src'] ?? array() ) as $src ) {
			$sources[] = $src;
			break;
		}
		if ( isset( $city['crashes'], $pack['crashSource'] ) ) {
			$sources[] = $pack['crashSource'];
		}
		$body[] = '<h2>Sources</h2>';
		$body[] = self::links( $sources, $e );

		$faq     = array();
		$faq[]   = self::qa( sprintf( 'Which practice areas does LexRanked rank in %s?', $name ), sprintf( '%s: %s.', ucfirst( self::count_noun( $n_area, 'practice area', 'practice areas' ) ), self::join( array_map( static fn( array $r ): string => $r['area'], $main ) ) ) );
		$faq[]   = self::qa( sprintf( 'Which court handles cases in %s?', $name ), $court );
		$faq[]   = self::qa( sprintf( 'How do I check if a %s lawyer is licensed?', $name ), sprintf( 'Search the lawyer\'s name in %s\'s Find a Lawyer directory; the status must read "eligible to practice in Florida". The profile also lists board certification and public discipline.', $bar ) );
		$faq[]   = self::qa( sprintf( 'Where can I complain about a %s lawyer?', $name ), sprintf( 'Contact %s.', (string) $pack['bar']['complaints'] ) );
		$faq[]   = self::qa( sprintf( 'Do I need a lawyer based in %s?', $name ), sprintf( 'Any lawyer licensed by %s may practice anywhere in %s, but a lawyer who regularly appears in %s County knows its judges and local procedures.', $bar, $state, $county ) );
		$spanish = array_values( array_filter( $langs, static fn( array $r ): bool => 'Spanish' === $r['language'] ) );
		if ( array() !== $spanish ) {
			$faq[] = self::qa( sprintf( 'Are there Spanish-speaking lawyers in %s?', $name ), sprintf( 'Yes. LexRanked ranks Spanish-speaking lawyers in %s for %s, based on the languages listed on their %s profiles.', $name, self::join( array_map( static fn( array $r ): string => RankingContentBuilder::in_sentence( $r['area'] ), $spanish ) ), $short ) );
		}
		return self::result( $summary, $body, $faq, $today );
	}

	/**
	 * State hub.
	 *
	 * @param array<string, mixed>                                                                                                                                              $pack    State knowledge pack.
	 * @param array{lawyers: int, statewide: array<int, array{path: string, area: string, entries: int}>, cities: array<int, array{path: string, city: string, rankings: int}>} $context Statewide rankings and city hubs with their ranking counts.
	 * @param string                                                                                                                                                            $checked Month the data was checked.
	 * @param string                                                                                                                                                            $today   Review date (Y-m-d).
	 * @return array{summary: string, body: string, faq: array<int, array{question: string, answer: string}>, reviewed_by: string, reviewed_at: string}|null
	 */
	public static function state( array $pack, array $context, string $checked, string $today ): ?array {
		$wide   = $context['statewide'];
		$cities = $context['cities'];
		if ( array() === $wide && array() === $cities ) {
			return null;
		}
		usort( $wide, static fn( array $a, array $b ): int => array( $b['entries'], $a['area'] ) <=> array( $a['entries'], $b['area'] ) );
		usort( $cities, static fn( array $a, array $b ): int => array( $b['rankings'], $a['city'] ) <=> array( $a['rankings'], $b['city'] ) );
		$e        = self::escaper();
		$state    = (string) $pack['name'];
		$bar      = (string) $pack['bar']['name'];
		$short    = (string) $pack['bar']['short'];
		$rankings = array_sum( array_column( $cities, 'rankings' ) ) + count( $wide );
		$courts   = is_array( $pack['courts'] ?? null ) ? $pack['courts'] : null;
		$summary  = sprintf( 'LexRanked lists %d %s lawyers and ranks them in %d rankings: %s statewide and city rankings in %s. ', (int) $context['lawyers'], $state, $rankings, self::count_noun( count( $wide ), 'practice area', 'practice areas' ), self::count_noun( count( $cities ), 'city', 'cities' ) )
			. sprintf( 'Every lawyer is matched to a %s profile, and positions follow the LexRank score; payment never changes a position.', $short );

		$body = array();
		if ( array() !== $wide ) {
			$body[] = '<h2>' . $e( sprintf( 'What are the best lawyer rankings in %s?', $state ) ) . '</h2>';
			$body[] = '<p><strong>' . $e( sprintf( 'Each statewide ranking compares every lawyer LexRanked tracks in one practice area across %s and shows the top 25; %s has the most (%d).', $state, $wide[0]['area'], $wide[0]['entries'] ) ) . '</strong></p>';
			$rows   = array_map( static fn( array $r ): string => '<tr><td><a href="' . $e( $r['path'] ) . '">' . $e( sprintf( 'Best %s lawyers in %s', RankingContentBuilder::in_sentence( $r['area'] ), $state ) ) . '</a></td><td>' . (int) $r['entries'] . '</td></tr>', $wide );
			$body[] = '<table><thead><tr><th>Statewide ranking</th><th>Lawyers tracked</th></tr></thead><tbody>' . implode( '', $rows ) . '</tbody></table>';
		}
		if ( array() !== $cities ) {
			$body[] = '<h2>' . $e( sprintf( 'Which %s cities have lawyer rankings?', $state ) ) . '</h2>';
			$body[] = '<p><strong>' . $e( sprintf( '%s; %s has the most rankings (%d).', ucfirst( self::count_noun( count( $cities ), 'city', 'cities' ) ), $cities[0]['city'], $cities[0]['rankings'] ) ) . '</strong></p>';
			$rows   = array_map( static fn( array $c ): string => '<tr><td><a href="' . $e( $c['path'] ) . '">' . $e( $c['city'] ) . '</a></td><td>' . (int) $c['rankings'] . '</td></tr>', $cities );
			$body[] = '<table><thead><tr><th>City</th><th>Rankings</th></tr></thead><tbody>' . implode( '', $rows ) . '</tbody></table>';
		}
		if ( null !== $courts ) {
			$body[] = '<h2>' . $e( sprintf( 'How are %s courts organized?', $state ) ) . '</h2>';
			$body[] = '<p><strong>' . $e( sprintf( '%s has %d judicial circuits for trial courts and %d district courts of appeal above them, with the %s Supreme Court at the top.', $state, (int) $courts['circuits'], (int) $courts['appellateDistricts'], $state ) ) . '</strong> <a href="/data/florida-judicial-circuits/">' . $e( 'See which circuit covers each county' ) . '</a>.</p>';
		}
		$body[]  = '<h2>' . $e( sprintf( 'How do I check a lawyer in %s?', $state ) ) . '</h2>';
		$body[]  = '<p><strong>' . $e( sprintf( 'Search the lawyer in %s\'s Find a Lawyer directory: it shows whether the lawyer is eligible to practice, board certification and public discipline.', $bar ) ) . '</strong> ' . $e( sprintf( 'Concerns about a lawyer go to %s.', (string) $pack['bar']['complaints'] ) ) . '</p>';
		$body[]  = '<h2>' . $e( 'What does board certification mean?' ) . '</h2>';
		$body[]  = '<p><strong>' . $e( sprintf( 'It is a credential from %s held by about %s of %s lawyers in any field.', $bar, (string) $pack['certification']['share'], $state ) ) . '</strong> ' . $e( (string) ( $pack['certification']['requirements'] ?? '' ) ) . '</p>';
		$body[]  = '<h2>' . $e( 'How does LexRanked rank lawyers?' ) . '</h2>';
		$body[]  = '<p><strong>' . $e( 'By the LexRank score: experience, credentials, practice relevance, location and data quality, using only facts with a cited source.' ) . '</strong> ' . $e( sprintf( 'Data checked %s. Payment never changes a position.', $checked ) ) . ' <a href="/methodology/">See how we rank</a>.</p>';
		$sources = array( array( (string) $pack['bar']['directory'], $bar . ': Find a Lawyer directory' ), (array) $pack['certification']['source'] );
		foreach ( (array) ( $courts['src'] ?? array() ) as $src ) {
			$sources[] = $src;
		}
		$body[] = '<h2>Sources</h2>';
		$body[] = self::links( $sources, $e );

		$faq = array();
		if ( array() !== $wide ) {
			$faq[] = self::qa( sprintf( 'Which practice areas does LexRanked rank in %s?', $state ), sprintf( '%s statewide: %s.', ucfirst( self::count_noun( count( $wide ), 'practice area', 'practice areas' ) ), self::join( array_map( static fn( array $r ): string => $r['area'], $wide ) ) ) );
		}
		if ( array() !== $cities ) {
			$faq[] = self::qa( sprintf( 'Which %s cities does LexRanked cover?', $state ), sprintf( '%s: %s.', ucfirst( self::count_noun( count( $cities ), 'city', 'cities' ) ), self::join( array_map( static fn( array $c ): string => $c['city'], $cities ) ) ) );
		}
		if ( null !== $courts ) {
			$faq[] = self::qa( sprintf( 'How many judicial circuits does %s have?', $state ), sprintf( '%d judicial circuits, with %d district courts of appeal above them.', (int) $courts['circuits'], (int) $courts['appellateDistricts'] ) );
		}
		$faq[] = self::qa( sprintf( 'How do I check if a lawyer is licensed in %s?', $state ), sprintf( 'Search the lawyer\'s name in %s\'s Find a Lawyer directory; the status must read "eligible to practice in Florida".', $bar ) );
		$faq[] = self::qa( 'How are LexRanked rankings ordered?', 'By the LexRank score, calculated only from facts with a cited source. Payment never changes a position.' );
		return self::result( $summary, $body, $faq, $today );
	}

	/**
	 * Whether an internal guide link may be used: the caller lists the
	 * published article paths in `articles`; without the list every link is
	 * allowed (the pack's guides are published articles).
	 *
	 * @param array<string, mixed> $pack Pack, optionally with `articles` (paths).
	 * @param string               $path Site path.
	 */
	private static function published( array $pack, string $path ): bool {
		return ! isset( $pack['articles'] ) || in_array( $path, (array) $pack['articles'], true );
	}

	/**
	 * The fee rule as a source, when the page cites it.
	 *
	 * @param array<string, mixed> $pack Pack.
	 * @param bool                 $used Whether the fee section is shown.
	 * @return array<int, array<int, string>>
	 */
	private static function fee_source( array $pack, bool $used ): array {
		return $used && is_array( $pack['feeRule'] ?? null ) ? array( $pack['feeRule'] ) : array();
	}

	/**
	 * Final shape, shared with ranking text.
	 *
	 * @param string                                              $summary Summary.
	 * @param array<int, string>                                  $body    Body parts.
	 * @param array<int, array{question: string, answer: string}> $faq     FAQ.
	 * @param string                                              $today   Review date.
	 * @return array{summary: string, body: string, faq: array<int, array{question: string, answer: string}>, reviewed_by: string, reviewed_at: string}
	 */
	private static function result( string $summary, array $body, array $faq, string $today ): array {
		return array(
			'summary'     => $summary,
			'body'        => implode( "\n", $body ),
			'faq'         => array_slice( $faq, 0, 12 ),
			'reviewed_by' => RankingContentBuilder::REVIEWED_BY,
			'reviewed_at' => $today,
		);
	}

	/**
	 * One FAQ item.
	 *
	 * @param string $question Question.
	 * @param string $answer   Answer.
	 * @return array{question: string, answer: string}
	 */
	private static function qa( string $question, string $answer ): array {
		return array(
			'question' => $question,
			'answer'   => $answer,
		);
	}

	/**
	 * HTML escaper.
	 */
	private static function escaper(): callable {
		return static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * "1 city" or "5 cities".
	 *
	 * @param int    $n        Count.
	 * @param string $singular Singular.
	 * @param string $plural   Plural.
	 */
	private static function count_noun( int $n, string $singular, string $plural ): string {
		return $n . ' ' . ( 1 === $n ? $singular : $plural );
	}

	/**
	 * "a, b and c".
	 *
	 * @param array<int, string> $items Items.
	 */
	private static function join( array $items ): string {
		if ( count( $items ) <= 1 ) {
			return implode( '', $items );
		}
		return implode( ', ', array_slice( $items, 0, -1 ) ) . ' and ' . $items[ count( $items ) - 1 ];
	}

	/**
	 * Escaped bullet list.
	 *
	 * @param array<int, string> $items Items.
	 * @param callable           $e     Escaper.
	 */
	private static function bullets( array $items, callable $e ): string {
		return '<ul>' . implode( '', array_map( static fn( $i ): string => '<li>' . $e( (string) $i ) . '</li>', $items ) ) . '</ul>';
	}

	/**
	 * Escaped list of links.
	 *
	 * @param array<int, array<int, string>> $links [url, label] pairs.
	 * @param callable                       $e     Escaper.
	 */
	private static function links( array $links, callable $e ): string {
		$seen = array();
		$out  = array();
		foreach ( $links as $l ) {
			if ( ! isset( $l[0], $l[1] ) || isset( $seen[ $l[0] ] ) ) {
				continue;
			}
			$seen[ $l[0] ] = true;
			$out[]         = '<li><a href="' . $e( (string) $l[0] ) . '">' . $e( (string) $l[1] ) . '</a></li>';
		}
		return '<ul>' . implode( '', $out ) . '</ul>';
	}

	/**
	 * Escaped table.
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
