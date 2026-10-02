<?php
/**
 * Data freshness rules.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Verification;

/**
 * Decides whether a data point is stale given configurable max ages.
 */
final class Freshness {

	/** Default max age in days per data category (configurable in settings). */
	public const DEFAULT_RULES = array(
		'bar_status'  => 30,
		'review_data' => 7,
		'website'     => 30,
		'profile'     => 90,
	);

	/**
	 * Constructor.
	 *
	 * @param array<string, int> $rules Max age in days per category.
	 */
	public function __construct( private readonly array $rules = self::DEFAULT_RULES ) {
	}

	/**
	 * Max age in days per category (merged with the defaults).
	 *
	 * @return array<string, int>
	 */
	public function rules(): array {
		return array_map( 'intval', $this->rules + self::DEFAULT_RULES );
	}

	/**
	 * Evaluate freshness.
	 *
	 * @param string             $category         Rule category, e.g. "profile".
	 * @param string|null        $last_verified_at ISO 8601 timestamp or null.
	 * @param \DateTimeImmutable $now              Current time.
	 * @return array{category: string, maxAgeDays: int, lastVerifiedAt: string|null, isStale: bool, staleAt: string|null}
	 */
	public function evaluate( string $category, ?string $last_verified_at, \DateTimeImmutable $now ): array {
		$max_age  = (int) ( $this->rules[ $category ] ?? $this->rules['profile'] ?? self::DEFAULT_RULES['profile'] );
		$stale_at = null;
		$is_stale = true;
		// Never verified means stale.

		if ( null !== $last_verified_at && '' !== $last_verified_at ) {
			try {
				$stale    = ( new \DateTimeImmutable( $last_verified_at ) )->modify( sprintf( '+%d days', $max_age ) );
				$stale_at = $stale->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
				$is_stale = $stale <= $now;
			} catch ( \Exception $e ) {
				$is_stale = true;
			}
		}

		return array(
			'category'       => $category,
			'maxAgeDays'     => $max_age,
			'lastVerifiedAt' => $last_verified_at,
			'isStale'        => $is_stale,
			'staleAt'        => $stale_at,
		);
	}
}
