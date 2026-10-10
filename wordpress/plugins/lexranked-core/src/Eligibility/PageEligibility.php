<?php
/**
 * Page eligibility engine (Etap G).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Eligibility;

/**
 * CAN_THIS_PAGE_EXIST? - one place that decides, from data, whether a page
 * exists and whether search engines may index it. Every decision lists its
 * checks (value, requirement, pass/fail), so the answer is explainable and
 * the same everywhere: page rendering, robots meta and the sitemap.
 *
 * Two levels:
 * - exist: without this the page is a 404 (too little data to be useful);
 * - index: the page renders but is noindex until it is backed by real,
 *   verified, well-sourced data.
 *
 * Inputs are counts from the database (entities, verified entities, real
 * vs demo, evidence coverage, words). Nothing here reads keywords or search
 * volume: a page never exists because a keyword does. Pure.
 */
final class PageEligibility {

	public const VERSION = 'pe-1.0';

	public const EXIST = 'exist';
	public const INDEX = 'index';

	/**
	 * Default rules: type => [ [key, label, required, level, description], … ].
	 * `coverage` is the average share of expected facts backed by a source
	 * (Data Quality completeness), 0-1.
	 */
	private const RULES = array(
		'hub'        => array(
			array( 'entities', 'Published lawyers', 3, self::EXIST, 'A state, city or practice-area page needs at least this many published lawyers.' ),
			array( 'real', 'Lawyers with real (non-demo) data', 3, self::INDEX, 'Demo records never make a page indexable.' ),
			array( 'verified', 'Verified lawyers', 1, self::INDEX, 'At least one lawyer on the page has a fully verified profile.' ),
			array( 'coverage', 'Evidence coverage', 0.5, self::INDEX, 'Average share of expected facts backed by a source.' ),
		),
		'ranking'    => array(
			array( 'entities', 'Ranked entities', 5, self::EXIST, 'Fewer entries than this make a thin ranking (configurable per ranking).' ),
			array( 'real', 'Built from real (non-demo) data', 1, self::INDEX, 'Demo rankings are never indexed.' ),
			array( 'verified', 'Verified entries', 3, self::INDEX, 'Entries with fully verified profiles.' ),
			array( 'coverage', 'Evidence coverage', 0.6, self::INDEX, 'Average share of expected facts backed by a source across the entries.' ),
		),
		'profile'    => array(
			array( 'real', 'Real (non-demo) record', 1, self::INDEX, 'Demo profiles are never indexed.' ),
			array( 'coverage', 'Evidence coverage', 0.4, self::INDEX, 'Share of expected facts backed by a source; thinner profiles stay noindex.' ),
		),
		'article'    => array(
			array( 'real', 'Real (non-demo) content', 1, self::INDEX, 'Demo articles are never indexed.' ),
			array( 'words', 'Words', 300, self::INDEX, 'Shorter guides are thin.' ),
		),
		'comparison' => array(
			array( 'entities', 'Compared entities', 2, self::EXIST, 'A comparison needs two to four entities.' ),
			array( 'curated', 'Curated for search', 1, self::INDEX, 'Comparisons are built on request for any pair and stay noindex until an editor curates them.' ),
		),
		'listing'    => array(
			array( 'real', 'Real (non-demo) profiles listed', 1, self::INDEX, 'Listing pages are noindex until they list real profiles.' ),
		),
	);

	/**
	 * Page types.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		return array_keys( self::RULES );
	}

	/**
	 * The published rules (methodology page, API).
	 *
	 * @return array{version: string, types: array<string, array<int, array<string, mixed>>>}
	 */
	public static function model(): array {
		$types = array();
		foreach ( self::RULES as $type => $rules ) {
			foreach ( $rules as [ $key, $label, $required, $level, $description ] ) {
				$types[ $type ][] = array(
					'key'         => $key,
					'label'       => $label,
					'required'    => $required,
					'level'       => $level,
					'description' => $description,
				);
			}
		}
		$types['ranking'][] = array(
			'key'         => 'context',
			'label'       => 'Context confirmed by data',
			'required'    => 1,
			'level'       => self::EXIST,
			'description' => 'Contextual ("best for") rankings only: enough entities confirmed by facts, enough of them verified, and different from the broader ranking.',
		);
		return array(
			'version' => self::VERSION,
			'types'   => $types,
		);
	}

	/**
	 * Evaluate a page.
	 *
	 * @param string                    $type      One of types().
	 * @param array<string, mixed>      $stats     Values by rule key (numbers; booleans count as 1/0).
	 * @param array<string, int|float>  $overrides Required values by rule key (e.g. a ranking's own minimum).
	 * @param array<string, mixed>|null $context   ContextEligibility result for a contextual ranking.
	 * @return array{version: string, type: string, exists: bool, indexable: bool, checks: array<int, array<string, mixed>>, reasons: array<int, string>}
	 * @throws \InvalidArgumentException On an unknown page type.
	 */
	public static function evaluate( string $type, array $stats, array $overrides = array(), ?array $context = null ): array {
		if ( ! isset( self::RULES[ $type ] ) ) {
			throw new \InvalidArgumentException( 'Unknown page type: ' . $type );
		}
		$checks = array();
		foreach ( self::RULES[ $type ] as [ $key, $label, $required, $level ] ) {
			$required = $overrides[ $key ] ?? $required;
			$value    = $stats[ $key ] ?? 0;
			$value    = is_bool( $value ) ? (int) $value : ( is_numeric( $value ) ? $value + 0 : 0 );
			$checks[] = array(
				'key'      => $key,
				'label'    => $label,
				'value'    => is_float( $value ) ? round( $value, 3 ) : $value,
				'required' => $required,
				'level'    => $level,
				'passed'   => $value >= $required,
			);
		}
		if ( null !== $context ) {
			$checks[] = array(
				'key'      => 'context',
				'label'    => 'Context confirmed by data',
				'value'    => (int) $context['eligible'],
				'required' => 1,
				'level'    => self::EXIST,
				'passed'   => (bool) $context['eligible'],
				'reasons'  => array_values( (array) ( $context['reasons'] ?? array() ) ),
			);
		}

		$exists    = true;
		$indexable = true;
		$reasons   = array();
		foreach ( $checks as $check ) {
			$exists = $exists && ( $check['passed'] || self::EXIST !== $check['level'] );
		}
		foreach ( $checks as $check ) {
			// A page that cannot exist lists only what stops it from existing.
			if ( $check['passed'] || ( ! $exists && self::EXIST !== $check['level'] ) ) {
				$indexable = $indexable && $check['passed'];
				continue;
			}
			$indexable = false;
			if ( 'context' === $check['key'] ) {
				array_push( $reasons, ...$check['reasons'] );
				continue;
			}
			$reasons[] = self::reason( $check );
		}
		return array(
			'version'   => self::VERSION,
			'type'      => $type,
			'exists'    => $exists,
			'indexable' => $exists && $indexable,
			'checks'    => $checks,
			'reasons'   => $reasons,
		);
	}

	/**
	 * Compact form for list DTOs.
	 *
	 * @param array<string, mixed> $result evaluate() result.
	 * @return array{exists: bool, indexable: bool, reasons: array<int, string>}
	 */
	public static function compact( array $result ): array {
		return array(
			'exists'    => $result['exists'],
			'indexable' => $result['indexable'],
			'reasons'   => $result['reasons'],
		);
	}

	/**
	 * Human reason for a failed check.
	 *
	 * @param array<string, mixed> $check Check.
	 */
	private static function reason( array $check ): string {
		$prefix = self::EXIST === $check['level'] ? 'No page: ' : 'Not indexed: ';
		if ( 'coverage' === $check['key'] ) {
			return sprintf( '%s%s %d%% (needs %d%%).', $prefix, strtolower( $check['label'] ), (int) round( $check['value'] * 100 ), (int) round( $check['required'] * 100 ) );
		}
		if ( 'curated' === $check['key'] ) {
			return $prefix . 'not curated for search.';
		}
		if ( 'real' === $check['key'] && 1 === $check['required'] ) {
			return $prefix . 'demo data.';
		}
		return sprintf( '%s%s %s (needs %s).', $prefix, strtolower( $check['label'] ), (string) $check['value'], (string) $check['required'] );
	}
}
