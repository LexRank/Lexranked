<?php
/**
 * Source post type.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\PostTypes;

use LexRanked\Core\Schema\Field;
use LexRanked\Core\Sources\SourceTiers;

/**
 * A registered information source (registry, website, directory...).
 * Its tier is derived from source_type via the configurable tier map.
 */
final class Source extends PostType {

	public const SLUG = 'lr_source';

	public const STATUSES = array( 'active', 'unreachable', 'moved', 'retired' );

	/**
	 * Constructor.
	 *
	 * @param SourceTiers $tiers Configured source tiers.
	 */
	public function __construct( private readonly SourceTiers $tiers ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function singular(): string {
		return 'Source';
	}

	/**
	 * {@inheritDoc}
	 */
	public function plural(): string {
		return 'Sources';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon(): string {
		return 'dashicons-admin-links';
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields(): array {
		return array(
			new Field( 'url', Field::TYPE_URL, 'URL', required: true ),
			new Field( 'source_type', Field::TYPE_ENUM, 'Source type', required: true, options: $this->tiers->types(), help: 'Tier is derived from the type (Settings → Source tiers): 1 official/regulatory · 2 official business/professional · 3 reputable directory · 4 review platform/editorial · 5 secondary.' ),
			new Field( 'publisher', Field::TYPE_STRING, 'Publisher', max: 200, help: 'Who publishes it, e.g. "The Florida Bar".' ),
			new Field( 'retrieved_at', Field::TYPE_DATETIME, 'First retrieved', help: 'When LexRanked first read this source.' ),
			new Field( 'last_checked_at', Field::TYPE_DATETIME, 'Last checked', help: 'When the source was last re-read or checked.' ),
			new Field( 'status', Field::TYPE_ENUM, 'Source status', options: self::STATUSES, help: 'active: reachable and current · unreachable · moved · retired. A source is never assumed true because of its status: each claim is verified on its own.' ),
			new Field( 'notes', Field::TYPE_TEXT, 'Internal notes', is_public: false ),
			self::demo_field(),
		);
	}
}
