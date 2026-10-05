<?php
/**
 * Source type taxonomy and priority tiers.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Sources;

/**
 * Maps source types to priority tiers (1 = most authoritative).
 *
 * The mapping is configuration (Settings → Source tiers). No specific
 * third-party source is ever assumed to be correct by code.
 */
final class SourceTiers {

	public const DEFAULT_TIERS = array(
		'official_registry'        => 1,
		'government'               => 1,
		'bar_association'          => 1,
		'official_website'         => 2,
		'professional_association' => 2,
		'professional_directory'   => 3,
		'review_platform'          => 4,
		'editorial'                => 4,
		'secondary'                => 5,
		'social'                   => 5,
		'other'                    => 5,
		'lexranked_reviews'        => 5,
	);

	/** What each tier means (docs/knowledge-base.md). */
	public const TIER_LABELS = array(
		1 => 'Official / regulatory',
		2 => 'Official business / professional',
		3 => 'Reputable third-party directory',
		4 => 'Review platform / editorial',
		5 => 'Secondary / unclassified',
	);

	public const LOWEST_TIER = 5;

	/**
	 * Label of a tier.
	 *
	 * @param int $tier Tier.
	 */
	public static function label( int $tier ): string {
		return self::TIER_LABELS[ $tier ] ?? self::TIER_LABELS[ self::LOWEST_TIER ];
	}

	/**
	 * Constructor.
	 *
	 * @param array<string, int> $tiers Source type => tier.
	 */
	public function __construct( private readonly array $tiers = self::DEFAULT_TIERS ) {
	}

	/**
	 * Configured source types.
	 *
	 * @return array<int, string>
	 */
	public function types(): array {
		return array_keys( $this->tiers );
	}

	/**
	 * Tier for a source type. Unknown types get the lowest priority.
	 *
	 * @param string $source_type Source type.
	 */
	public function tier_for( string $source_type ): int {
		return (int) ( $this->tiers[ $source_type ] ?? self::LOWEST_TIER );
	}

	/**
	 * Parse "type = tier" lines (settings textarea) into a validated map.
	 *
	 * @param string $text Configuration text.
	 * @return array<string, int>
	 */
	public static function parse( string $text ): array {
		$tiers = array();
		$lines = preg_split( '/\R/', $text );
		foreach ( false === $lines ? array() : $lines as $line ) {
			if ( ! preg_match( '/^\s*([a-z][a-z0-9_]{0,63})\s*=\s*([1-5])\s*$/', $line, $m ) ) {
				continue;
			}
			$tiers[ $m[1] ] = (int) $m[2];
		}
		return $tiers;
	}

	/**
	 * Format a map as "type = tier" lines.
	 *
	 * @param array<string, int> $tiers Map.
	 */
	public static function format( array $tiers ): string {
		$lines = array();
		foreach ( $tiers as $type => $tier ) {
			$lines[] = $type . ' = ' . $tier;
		}
		return implode( "\n", $lines );
	}
}
