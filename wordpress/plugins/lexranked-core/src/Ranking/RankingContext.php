<?php
/**
 * Ranking context.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Ranking;

/**
 * What an entity is being scored for: a practice area and a location.
 * Entity-level scores use the entity's own primary practice area and city.
 */
final class RankingContext {

	/**
	 * Constructor.
	 *
	 * @param string|null $practice_area Practice-area slug.
	 * @param string|null $city          City slug.
	 * @param string|null $state         State slug.
	 */
	public function __construct(
		public readonly ?string $practice_area = null,
		public readonly ?string $city = null,
		public readonly ?string $state = null,
	) {
	}

	/**
	 * The entity's own context.
	 *
	 * @param EntityInput $input Input.
	 */
	public static function for_entity( EntityInput $input ): self {
		return new self( $input->practice_areas[0] ?? null, $input->city, $input->state );
	}

	/**
	 * Serialize.
	 *
	 * @return array{practice_area: string|null, city: string|null, state: string|null}
	 */
	public function to_array(): array {
		return array(
			'practice_area' => $this->practice_area,
			'city'          => $this->city,
			'state'         => $this->state,
		);
	}
}
