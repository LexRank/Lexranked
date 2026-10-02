<?php
/**
 * Research job statuses.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Domain;

/**
 * Research job lifecycle.
 *
 * Transitions: pending → running → completed | failed | cancelled. Failed
 * jobs can be retried (→ pending) and resume from their stored cursor.
 */
enum ResearchJobStatus: string {
	case Pending   = 'pending';
	case Running   = 'running';
	case Completed = 'completed';
	case Failed    = 'failed';
	case Cancelled = 'cancelled';

	/**
	 * All values.
	 *
	 * @return array<int, string>
	 */
	public static function values(): array {
		return array_map( static fn( self $s ): string => $s->value, self::cases() );
	}

	/**
	 * Whether a transition to $next is allowed.
	 *
	 * @param self $next Target status.
	 */
	public function can_transition_to( self $next ): bool {
		return in_array(
			$next,
			match ( $this ) {
				self::Pending   => array( self::Running, self::Cancelled ),
				self::Running   => array( self::Completed, self::Failed, self::Cancelled ),
				self::Failed    => array( self::Pending, self::Cancelled ),
				self::Completed, self::Cancelled => array(),
			},
			true
		);
	}
}
