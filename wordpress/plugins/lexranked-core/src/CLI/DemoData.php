<?php
/**
 * Demo / mock data definitions.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\CLI;

/**
 * Clearly-labelled MOCK data for local development and connection testing.
 *
 * Every name ends with "(Demo)", websites use example.com, phone numbers use
 * the reserved fictional 555-01xx range, and every record is flagged
 * `is_demo`, which the API exposes as `isDemo: true`. Demo rankings are never
 * indexable. None of this describes a real person or firm.
 */
final class DemoData {

	public const STATE    = array(
		'name' => 'Florida',
		'slug' => 'florida',
		'code' => 'FL',
	);
	public const CITY     = array(
		'name' => 'Miami',
		'slug' => 'miami',
	);
	public const PRACTICE = array(
		'name' => 'Personal Injury',
		'slug' => 'personal-injury',
	);

	public const RANKING_SUMMARY = 'Demo content: this sample ranking compares eight fictional personal injury lawyers in Miami using mock data, to show how LexRanked pages present rankings, sources and verification.';

	public const ARTICLE_TITLE = 'How to Read a Lawyer Ranking (Demo)';

	public const CITY_SUMMARY = 'Demo content: Miami, Florida is the sample city used to show how LexRanked hub pages combine rankings, lawyer profiles and firm profiles built from sourced data.';

	/**
	 * Demo city FAQ.
	 *
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function city_faq(): array {
		return array(
			array(
				'question' => 'Is this real data? (Demo)',
				'answer'   => 'No. Every lawyer, firm and ranking on this demo page is fictional mock data used to demonstrate the layout.',
			),
		);
	}

	/**
	 * Demo article body (plain paragraphs, over 300 words so the page is not thin).
	 */
	public static function article_body(): string {
		$paragraphs = array(
			'This demo article explains how to read a LexRanked ranking. It is sample content: every name and number it refers to belongs to fictional demo data.',
			'A ranking lists lawyers or law firms for one location and one practice area. Positions follow the LexRank score, a deterministic score calculated from verified data, the number and quality of sources, experience and review data. The same inputs always produce the same score, and every calculation is stored so it can be reproduced later.',
			'Payment never changes a position. Commercial features such as sponsored placements are labelled as paid and kept separate from the organic score, so a reader can always tell what is editorial and what is advertising.',
			'Each entry shows the score, the verification status and how the position moved since the previous calculation. Open a profile to see the score breakdown: every component explains how many points it contributed, what the maximum is and which inputs were missing. Missing data scores zero and is never estimated.',
			'Verification matters. A profile is marked as verified only when the required checks, such as licence and bar status, are confirmed by an authoritative source and have not expired. Pending or expired checks are shown as such, and the sources section lists where every fact came from, with the date it was retrieved.',
			'Use a ranking as a starting point, not as the only reason to hire someone. Check the practice areas, read the sources, look at the dates, and contact more than one lawyer. Ask about experience with cases like yours, how fees work, who will handle the case day to day and how you will be kept informed.',
			'Finally, remember that rankings change as new data arrives. The methodology page explains every weight and the version used, and older calculations stay available so changes can be traced over time.',
		);
		return '<p>' . implode( "</p>\n<p>", $paragraphs ) . '</p>';
	}

	/**
	 * Demo editorial body shown below the ranking. Generic guidance only — no
	 * jurisdiction-specific legal facts are asserted.
	 */
	public static function ranking_body(): string {
		return implode(
			"\n\n",
			array(
				'<h2>How to use this ranking (demo content)</h2>',
				'<p>A ranking is a starting point for building a shortlist. Use it to compare verified credentials, client ratings and experience side by side, then speak with more than one lawyer before you decide.</p>',
				'<h2>What to ask a personal injury lawyer (demo content)</h2>',
				'<ul><li>How many cases like mine have you handled, and how were they resolved?</li><li>Who will work on my case day to day?</li><li>How are fees calculated, and which costs am I responsible for?</li><li>How will you keep me informed about progress?</li></ul>',
				'<h2>How scores are calculated</h2>',
				'<p>Scores are calculated with the published LexRank methodology from stored, sourced data. Payment never changes a score or a position.</p>',
			)
		);
	}

	/**
	 * Demo FAQ items.
	 *
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function ranking_faq(): array {
		return array(
			array(
				'question' => 'How is this ranking calculated?',
				'answer'   => 'Each lawyer receives a LexRank score from seven weighted factors, including reputation, review strength, experience and verified credentials. Entries are ordered by that score.',
			),
			array(
				'question' => 'Can lawyers pay to improve their position?',
				'answer'   => 'No. Commercial status is stored separately from the organic score and never affects a score or position. Paid placements are always labelled.',
			),
			array(
				'question' => 'Is this real data?',
				'answer'   => 'No. This is demo content with fictional lawyers, created to test the LexRanked platform. Demo pages are hidden from search engines.',
			),
		);
	}

	/**
	 * Demo sources.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function sources(): array {
		return array(
			'registry' => array(
				'title'       => 'Example State Bar Registry (Demo)',
				'url'         => 'https://example.com/demo/bar-registry',
				'source_type' => 'official_registry',
			),
			'website'  => array(
				'title'       => 'Example Firm Websites (Demo)',
				'url'         => 'https://example.com/demo/firms',
				'source_type' => 'official_website',
			),
			'reviews'  => array(
				'title'       => 'Example Review Platform (Demo)',
				'url'         => 'https://example.com/demo/reviews',
				'source_type' => 'review_platform',
			),
		);
	}

	/**
	 * Demo firms.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function firms(): array {
		return array(
			'harbor'  => array(
				'title'        => 'Harbor Example Injury Law (Demo)',
				'content'      => 'Sample firm description used for development. This firm does not exist.',
				'website'      => 'https://example.com/demo/harbor',
				'phone'        => '305-555-0100',
				'address'      => '100 Example Ave',
				'zip_code'     => '33101',
				'rating'       => 4.7,
				'review_count' => 212,
			),
			'bayside' => array(
				'title'        => 'Bayside Sample Legal Group (Demo)',
				'content'      => 'Sample firm description used for development. This firm does not exist.',
				'website'      => 'https://example.com/demo/bayside',
				'phone'        => '305-555-0101',
				'address'      => '200 Sample Blvd',
				'zip_code'     => '33130',
				'rating'       => 4.5,
				'review_count' => 98,
			),
			'coral'   => array(
				'title'        => 'Coral Placeholder Attorneys (Demo)',
				'content'      => 'Sample firm description used for development. This firm does not exist.',
				'website'      => 'https://example.com/demo/coral',
				'phone'        => '305-555-0102',
				'address'      => '300 Placeholder St',
				'zip_code'     => '33125',
				'rating'       => 4.9,
				'review_count' => 41,
			),
		);
	}

	/**
	 * Demo lawyers. `verified` controls the demo verification records.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function lawyers(): array {
		$rows = array(
			// first, last, firm, title, years, rating, reviews, verified. Scores are calculated by the engine.
			array( 'Avery', 'Example', 'harbor', 'Founding Partner', 22, 4.9, 387, 'verified' ),
			array( 'Blake', 'Sample', 'harbor', 'Partner', 15, 4.8, 154, 'verified' ),
			array( 'Casey', 'Placeholder', 'bayside', 'Partner', 18, 4.7, 201, 'verified' ),
			array( 'Drew', 'Specimen', 'bayside', 'Associate', 7, 4.9, 63, 'verified' ),
			array( 'Emery', 'Mockwell', 'coral', 'Managing Attorney', 12, 4.6, 120, 'verified' ),
			array( 'Finley', 'Testa', 'coral', 'Associate', 4, 5.0, 12, 'pending' ),
			array( 'Gray', 'Dummond', 'harbor', 'Associate', 9, 4.4, 77, 'pending' ),
			array( 'Harper', 'Exemplar', 'bayside', 'Of Counsel', 30, 4.2, 45, 'failed' ),
		);
		$out  = array();
		foreach ( $rows as $i => [ $first, $last, $firm, $title, $years, $rating, $reviews, $verified ] ) {
			$out[] = array(
				'title'            => sprintf( '%s %s (Demo)', $first, $last ),
				'content'          => sprintf( '%s %s is a fictional lawyer profile used to test LexRanked. None of this information is real.', $first, $last ),
				'first_name'       => $first,
				'last_name'        => $last,
				'firm'             => $firm,
				'title_field'      => $title,
				'years_experience' => $years,
				'rating'           => $rating,
				'review_count'     => $reviews,
				'bar_state'        => 'FL',
				'bar_number'       => sprintf( 'DEMO-%04d', $i + 1 ),
				'bar_status'       => 'active',
				'phone'            => sprintf( '305-555-01%02d', 10 + $i ),
				'website'          => sprintf( 'https://example.com/demo/lawyers/%s-%s', strtolower( $first ), strtolower( $last ) ),
				'languages'        => 0 === $i % 3 ? array( 'English', 'Spanish' ) : array( 'English' ),
				'education'        => array(
					array(
						'institution' => 'Example University School of Law (Demo)',
						'degree'      => 'J.D.',
						'year'        => (string) ( 2026 - $years - 1 ),
					),
				),
				'awards'           => 0 === $i % 2 ? array(
					array(
						'name'   => 'Example Trial Advocacy Award (Demo)',
						'issuer' => 'Example Bar Foundation (Demo)',
						'year'   => '2024',
					),
				) : array(),
				'verified'         => $verified,
			);
		}//end foreach
		return $out;
	}
}
