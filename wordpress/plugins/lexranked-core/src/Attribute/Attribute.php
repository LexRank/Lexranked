<?php
/**
 * Attribute definition.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Attribute;

/**
 * One named property an entity can have, independent of where it is stored.
 *
 * Layers (docs/knowledge-base.md):
 *  - fact:    comes from a source through evidence claims (years_experience = 18);
 *  - derived: calculated by LexRanked from facts (review_strength = 18.7/20).
 * Interpretations (text) are never attributes.
 */
final class Attribute {

	public const LAYER_FACT    = 'fact';
	public const LAYER_DERIVED = 'derived';

	/**
	 * Constructor.
	 *
	 * @param string             $key          Attribute key (matches the claim field_name for facts).
	 * @param string             $label        Human label.
	 * @param string             $value_type   string|text|integer|number|boolean|url|phone|email|code|enum|list|object_list|reference.
	 * @param array<int, string> $entity_types Entity types that can have it.
	 * @param string             $category     identity|location|organization|contact|practice|credentials|experience|language|reviews|score.
	 * @param string             $layer        fact|derived.
	 * @param string             $freshness    Freshness category (Settings → freshness rules).
	 * @param string|null        $unit         Unit, e.g. "years", "stars (0–5)", "points".
	 * @param string             $description  What it means.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $value_type,
		public readonly array $entity_types,
		public readonly string $category,
		public readonly string $layer = self::LAYER_FACT,
		public readonly string $freshness = 'profile',
		public readonly ?string $unit = null,
		public readonly string $description = ''
	) {
	}

	/**
	 * Public, machine-readable description.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'key'         => $this->key,
			'label'       => $this->label,
			'valueType'   => $this->value_type,
			'entityTypes' => $this->entity_types,
			'category'    => $this->category,
			'layer'       => $this->layer,
			'freshness'   => $this->freshness,
			'unit'        => $this->unit,
			'description' => $this->description,
		);
	}
}
