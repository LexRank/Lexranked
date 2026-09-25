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

	public const STATE         = array(
		'name' => 'Florida',
		'slug' => 'florida',
		'code' => 'FL',
	);
	public const CITY          = array(
		'name' => 'Miami',
		'slug' => 'miami',
	);
	public const PRACTICE      = array(
		'name' => 'Personal Injury',
		'slug' => 'personal-injury',
	);
	public const SCORE_VERSION = 'demo';

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
				'score'        => 88.5,
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
				'score'        => 81.2,
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
				'score'        => 79.9,
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
			// first, last, firm, title, years, rating, reviews, score, verified.
			array( 'Avery', 'Example', 'harbor', 'Founding Partner', 22, 4.9, 387, 94.21, 'verified' ),
			array( 'Blake', 'Sample', 'harbor', 'Partner', 15, 4.8, 154, 90.4, 'verified' ),
			array( 'Casey', 'Placeholder', 'bayside', 'Partner', 18, 4.7, 201, 88.75, 'verified' ),
			array( 'Drew', 'Specimen', 'bayside', 'Associate', 7, 4.9, 63, 84.1, 'verified' ),
			array( 'Emery', 'Mockwell', 'coral', 'Managing Attorney', 12, 4.6, 120, 83.3, 'verified' ),
			array( 'Finley', 'Testa', 'coral', 'Associate', 4, 5.0, 12, 76.8, 'pending' ),
			array( 'Gray', 'Dummond', 'harbor', 'Associate', 9, 4.4, 77, 74.05, 'pending' ),
			array( 'Harper', 'Exemplar', 'bayside', 'Of Counsel', 30, 4.2, 45, 71.6, 'failed' ),
		);
		$out  = array();
		foreach ( $rows as $i => [ $first, $last, $firm, $title, $years, $rating, $reviews, $score, $verified ] ) {
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
				'score'            => $score,
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
				'verified'         => $verified,
			);
		}//end foreach
		return $out;
	}
}
