<?php
/**
 * Plugin settings.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Settings;

use LexRanked\Core\Domain\VerificationType;
use LexRanked\Core\Ranking\ScoreVersions;
use LexRanked\Core\Research\Backoff;
use LexRanked\Core\Sources\SourceTiers;
use LexRanked\Core\Verification\Freshness;
use LexRanked\Core\Verification\VerificationPolicy;

/**
 * Typed access to the `lexranked_settings` option.
 *
 * Everything the spec calls "configurable" (source tiers, freshness rules,
 * verification requirements, thresholds) lives here, not in code.
 */
final class Settings {

	public const OPTION = 'lexranked_settings';

	/**
	 * Cached, sanitized values.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $values = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null $values Values to use instead of the stored option (tests).
	 */
	public function __construct( ?array $values = null ) {
		if ( null !== $values ) {
			$this->values = self::sanitize( $values );
		}
	}

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'frontend_url'              => '',
			'headless_redirect'         => false,
			'source_tiers'              => SourceTiers::DEFAULT_TIERS,
			'freshness_rules'           => Freshness::DEFAULT_RULES,
			'required_verifications'    => array(
				'lawyer'   => array( 'identity', 'license', 'bar_status' ),
				'law_firm' => array( 'business', 'website' ),
			),
			'min_ranking_entities'      => 5,
			'rate_limit_per_minute'     => 120,
			'search_rate_per_minute'    => 30,
			'trust_proxy_header'        => false,
			'score_version'             => ScoreVersions::DEFAULT_VERSION,
			'research_max_retries'      => 3,
			'research_backoff_base'     => 300,
			'research_lease_minutes'    => 10,
			'ai_enabled'                => false,
			'research_autonomy'         => false,
			'disable_xmlrpc'            => true,
			'claims_enabled'            => true,
			'max_sponsored_per_ranking' => 2,
			'max_featured_per_page'     => 3,
		);
	}

	/**
	 * Sanitize raw input (settings form or stored option) into a complete, valid set.
	 * Invalid values fall back to defaults.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$out      = $defaults;

		if ( isset( $input['frontend_url'] ) && is_string( $input['frontend_url'] ) ) {
			$url    = trim( $input['frontend_url'] );
			$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress-independent by design.
			if ( '' === $url || ( in_array( $scheme, array( 'http', 'https' ), true ) && false !== filter_var( $url, FILTER_VALIDATE_URL ) ) ) {
				$out['frontend_url'] = rtrim( $url, '/' );
			}
		}

		foreach ( array( 'headless_redirect', 'trust_proxy_header', 'ai_enabled', 'research_autonomy', 'disable_xmlrpc', 'claims_enabled' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = in_array( $input[ $key ], array( true, 1, '1', 'on', 'true' ), true );
			}
		}

		if ( isset( $input['source_tiers'] ) ) {
			$tiers = is_string( $input['source_tiers'] ) ? SourceTiers::parse( $input['source_tiers'] ) : self::int_map( $input['source_tiers'], 1, 5 );
			if ( array() !== $tiers ) {
				$out['source_tiers'] = $tiers;
			}
		}

		if ( isset( $input['freshness_rules'] ) ) {
			$rules = is_string( $input['freshness_rules'] ) ? self::parse_int_lines( $input['freshness_rules'], 1, 3650 ) : self::int_map( $input['freshness_rules'], 1, 3650 );
			if ( array() !== $rules ) {
				// "profile" is the fallback category and must always exist.
				$out['freshness_rules'] = $rules + array( 'profile' => Freshness::DEFAULT_RULES['profile'] );
			}
		}

		if ( isset( $input['required_verifications'] ) && is_array( $input['required_verifications'] ) ) {
			foreach ( array( 'lawyer', 'law_firm' ) as $entity ) {
				if ( ! array_key_exists( $entity, $input['required_verifications'] ) ) {
					continue;
				}
				$types = $input['required_verifications'][ $entity ];
				$types = is_string( $types ) ? preg_split( '/[\s,]+/', $types ) : $types;
				$types = is_array( $types ) ? $types : array();
				$valid = array_values( array_intersect( VerificationType::values(), array_map( 'strval', $types ) ) );

				$out['required_verifications'][ $entity ] = $valid;
			}
		}

		if ( isset( $input['score_version'] ) && is_string( $input['score_version'] ) && array_key_exists( $input['score_version'], ScoreVersions::builtin() ) ) {
			$out['score_version'] = $input['score_version'];
		}

		$ints = array(
			'min_ranking_entities'      => array( 1, 100 ),
			'rate_limit_per_minute'     => array( 10, 10000 ),
			'search_rate_per_minute'    => array( 5, 1000 ),
			'research_max_retries'      => array( 0, 10 ),
			'research_backoff_base'     => array( 30, 86400 ),
			'research_lease_minutes'    => array( 1, 120 ),
			'max_sponsored_per_ranking' => array( 0, 5 ),
			'max_featured_per_page'     => array( 0, 6 ),
		);
		foreach ( $ints as $key => [ $min, $max ] ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = max( $min, min( $max, (int) $input[ $key ] ) );
			}
		}

		return $out;
	}

	/**
	 * All values.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->values ) {
			$this->values = self::sanitize( get_option( self::OPTION, array() ) );
		}
		return $this->values;
	}

	/**
	 * One value.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Configured source tiers.
	 */
	public function source_tiers(): SourceTiers {
		// Types added in later versions appear with their default tier; configured tiers win.
		return new SourceTiers( (array) $this->get( 'source_tiers' ) + SourceTiers::DEFAULT_TIERS );
	}

	/**
	 * Configured freshness rules.
	 */
	public function freshness(): Freshness {
		return new Freshness( $this->get( 'freshness_rules' ) );
	}

	/**
	 * Verification policy for an entity type ("lawyer" or "law_firm").
	 *
	 * @param string $entity_type Entity type.
	 */
	public function verification_policy( string $entity_type ): VerificationPolicy {
		return new VerificationPolicy( $this->get( 'required_verifications' )[ $entity_type ] ?? array() );
	}

	/**
	 * Retry policy for research jobs.
	 */
	public function research_backoff(): Backoff {
		return new Backoff( (int) $this->get( 'research_backoff_base' ), 21600, (int) $this->get( 'research_max_retries' ) );
	}

	/**
	 * Clear the cache (after the option changes).
	 */
	public function reset(): void {
		$this->values = null;
	}

	/**
	 * Parse "key = number" lines.
	 *
	 * @param string $text Text.
	 * @param int    $min  Min value.
	 * @param int    $max  Max value.
	 * @return array<string, int>
	 */
	private static function parse_int_lines( string $text, int $min, int $max ): array {
		$map   = array();
		$lines = preg_split( '/\R/', $text );
		foreach ( false === $lines ? array() : $lines as $line ) {
			if ( preg_match( '/^\s*([a-z][a-z0-9_]{0,63})\s*=\s*(\d+)\s*$/', $line, $m ) && (int) $m[2] >= $min && (int) $m[2] <= $max ) {
				$map[ $m[1] ] = (int) $m[2];
			}
		}
		return $map;
	}

	/**
	 * Validate an array of key => int.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Min.
	 * @param int   $max   Max.
	 * @return array<string, int>
	 */
	private static function int_map( mixed $value, int $min, int $max ): array {
		$map = array();
		if ( ! is_array( $value ) ) {
			return $map;
		}
		foreach ( $value as $key => $number ) {
			if ( is_string( $key ) && preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $key ) && is_numeric( $number ) && (int) $number >= $min && (int) $number <= $max ) {
				$map[ $key ] = (int) $number;
			}
		}
		return $map;
	}
}
