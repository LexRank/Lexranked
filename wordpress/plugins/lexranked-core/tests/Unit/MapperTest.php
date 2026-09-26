<?php
/**
 * DTO mapper tests.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\REST\DTO\EntityMapper;
use LexRanked\Core\REST\DTO\LocationMapper;
use LexRanked\Core\REST\DTO\RankingMapper;
use LexRanked\Core\REST\DTO\SourceMapper;
use LexRanked\Core\Sources\SourceTiers;
use PHPUnit\Framework\TestCase;

final class MapperTest extends TestCase {

	private const FL = array(
		'id'         => 1,
		'slug'       => 'florida',
		'name'       => 'Florida',
		'parent'     => 0,
		'state_code' => 'FL',
	);

	private const MIAMI = array(
		'id'         => 2,
		'slug'       => 'miami',
		'name'       => 'Miami',
		'parent'     => 1,
		'state_code' => null,
	);

	private static function lawyer( int $id, ?float $score, string $commercial = 'free', array $overrides = array() ): array {
		return array(
			'id'             => $id,
			'type'           => 'lr_lawyer',
			'slug'           => 'lawyer-' . $id,
			'title'          => 'Lawyer ' . $id,
			'content'        => '',
			'status'         => 'publish',
			'created_at'     => '2026-09-01T00:00:00Z',
			'updated_at'     => '2026-09-02T00:00:00Z',
			'fields'         => array_merge(
				array(
					'first_name'          => 'L',
					'last_name'           => (string) $id,
					'title'               => null,
					'firm_id'             => null,
					'zip_code'            => null,
					'country'             => 'US',
					'website'             => null,
					'phone'               => null,
					'email'               => 'private@example.com',
					'years_experience'    => 10,
					'rating'              => 4.8,
					'review_count'        => 100,
					'bar_state'           => 'FL',
					'bar_number'          => null,
					'bar_status'          => 'active',
					'education'           => array(),
					'awards'              => array(),
					'languages'           => array(),
					'commercial_status'   => $commercial,
					'score'               => $score,
					'score_version'       => null === $score ? null : 'v1.0',
					'score_calculated_at' => null,
					'is_demo'             => false,
				),
				$overrides
			),
			'locations'      => array( self::MIAMI, self::FL ),
			'practice_areas' => array(
				array(
					'id'   => 9,
					'slug' => 'personal-injury',
					'name' => 'Personal Injury',
				),
			),
		);
	}

	private static function verification(): array {
		return array(
			'status'      => 'verified',
			'verified_at' => '2026-09-01T00:00:00Z',
			'types'       => array(),
		);
	}

	public function testLocationPrefersCityAndResolvesStateName(): void {
		$this->assertSame(
			array(
				'city'      => 'Miami',
				'citySlug'  => 'miami',
				'state'     => 'Florida',
				'stateSlug' => 'florida',
				'stateCode' => 'FL',
			),
			LocationMapper::from_terms( array( self::MIAMI, self::FL ) )
		);
		$this->assertSame( 'Florida', LocationMapper::from_terms( array( self::FL ) )['state'] );
		$this->assertNull( LocationMapper::from_terms( array() ) );
	}

	public function testPublicDtoNeverContainsPrivateEmail(): void {
		$dto  = EntityMapper::lawyer_detail( self::lawyer( 1, 90.0 ), null, self::verification(), array(), array(), '' );
		$json = (string) json_encode( $dto ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		$this->assertStringNotContainsString( 'private@example.com', $json );
		$this->assertArrayNotHasKey( 'private', $dto );

		$edit = EntityMapper::lawyer_detail( self::lawyer( 1, 90.0 ), null, self::verification(), array(), array(), '', true );
		$this->assertSame( 'private@example.com', $edit['private']['email'] );
	}

	public function testRankingAndCommercialAreSeparateBlocks(): void {
		$dto = EntityMapper::lawyer_summary( self::lawyer( 1, 90.0, 'sponsored' ), null, self::verification() );
		$this->assertSame(
			array(
				'score'        => 90.0,
				'scoreVersion' => 'v1.0',
				'calculatedAt' => null,
			),
			$dto['ranking']
		);
		$this->assertSame(
			array(
				'status'          => 'sponsored',
				'isPaidPlacement' => true,
				'claimed'         => true,
				'premium'         => false,
			),
			$dto['commercial']
		);
		$this->assertSame( 'free', EntityMapper::commercial( array( 'commercial_status' => 'bogus' ) )['status'] );
		$this->assertSame(
			array(
				'status'          => 'premium',
				'isPaidPlacement' => false,
				'claimed'         => true,
				'premium'         => true,
			),
			EntityMapper::commercial( array( 'commercial_status' => 'premium' ) )
		);
		$this->assertFalse( EntityMapper::commercial( array( 'commercial_status' => null ) )['claimed'] );
	}

	private static function snapshot_row( int $entity_id, int $position, float $score ): array {
		return array(
			'entity_id'     => $entity_id,
			'entity_type'   => 'lawyer',
			'position'      => $position,
			'score'         => $score,
			'score_version' => 'v1.0',
			'components'    => array(
				array(
					'key'         => 'experience',
					'label'       => 'Experience',
					'weight'      => 15,
					'factor'      => 0.6,
					'points'      => 9.0,
					'explanation' => '15 years in practice (full credit at 25).',
					'missing'     => array(),
				),
			),
		);
	}

	public function testSnapshotEntriesKeepEngineOrderAndReportMovement(): void {
		$summaries = array();
		foreach ( array( 1, 2, 3 ) as $id ) {
			$summaries[ $id ] = EntityMapper::lawyer_summary( self::lawyer( $id, 50.0, 3 === $id ? 'sponsored' : 'free' ), null, self::verification() );
		}
		$rows    = array( self::snapshot_row( 3, 1, 90.5 ), self::snapshot_row( 1, 2, 80.25 ), self::snapshot_row( 2, 3, 70.0 ) );
		$entries = RankingMapper::entries_from_snapshots(
			$rows,
			$summaries,
			array(
				1 => 1,
				3 => 2,
			)
		);

		$this->assertSame( array( 3, 1, 2 ), array_map( static fn( array $e ): int => $e['entity']['id'], $entries ) );
		$this->assertSame( array( 90.5, 80.25, 70.0 ), array_column( $entries, 'score' ) );
		$this->assertSame( array( 1, -1, null ), array_column( $entries, 'movement' ) );
		$this->assertSame( array( false, false, true ), array_column( $entries, 'isNew' ) );
		$this->assertSame( 9.0, $entries[0]['breakdown'][0]['points'] );
		$this->assertSame( 15.0, $entries[0]['breakdown'][0]['max'] );
		// Commercial status is displayed, but the engine's order stands.
		$this->assertSame( 'sponsored', $entries[0]['entity']['commercial']['status'] );
	}

	public function testUnpublishedEntitiesAreSkippedAndPositionsCompacted(): void {
		$summaries = array( 2 => EntityMapper::lawyer_summary( self::lawyer( 2, 50.0 ), null, self::verification() ) );
		$entries   = RankingMapper::entries_from_snapshots( array( self::snapshot_row( 1, 1, 90.0 ), self::snapshot_row( 2, 2, 80.0 ) ), $summaries, null );
		$this->assertCount( 1, $entries );
		$this->assertSame( 1, $entries[0]['position'] );
		$this->assertNull( $entries[0]['movement'] );
		$this->assertFalse( $entries[0]['isNew'], 'No previous run means no "new" badge' );
	}

	private static function ranking_record( bool $demo = false ): array {
		return array(
			'id'             => 50,
			'slug'           => 'best-pi-miami',
			'title'          => 'Best Personal Injury Lawyers in Miami, Florida',
			'updated_at'     => '2026-09-23T00:00:00Z',
			'fields'         => array(
				'entity_type'   => 'lawyer',
				'score_version' => 'v1.0',
				'min_entities'  => 3,
				'max_entities'  => 2,
				'is_demo'       => $demo,
			),
			'locations'      => array( self::MIAMI, self::FL ),
			'practice_areas' => array(
				array(
					'id'   => 9,
					'slug' => 'personal-injury',
					'name' => 'Personal Injury',
				),
			),
		);
	}

	public function testThinRankingsHaveNoEntriesAndAreNotIndexable(): void {
		$entries = RankingMapper::entries_from_snapshots(
			array( self::snapshot_row( 1, 1, 90.0 ) ),
			array( 1 => EntityMapper::lawyer_summary( self::lawyer( 1, 90.0 ), null, self::verification() ) ),
			null
		);
		$dto     = RankingMapper::ranking( self::ranking_record(), $entries, 5 );
		$this->assertTrue( $dto['isThin'] );
		$this->assertFalse( $dto['indexable'] );
		$this->assertSame( array(), $dto['entries'] );
		$this->assertSame( '/rankings/florida/miami/personal-injury/', $dto['path'] );
	}

	public function testRankingCapsEntriesAndDemoIsNeverIndexable(): void {
		$summaries = array();
		foreach ( array( 1, 2, 3 ) as $id ) {
			$summaries[ $id ] = EntityMapper::lawyer_summary( self::lawyer( $id, 50.0 + $id ), null, self::verification() );
		}
		$entries = RankingMapper::entries_from_snapshots(
			array( self::snapshot_row( 3, 1, 53.0 ), self::snapshot_row( 2, 2, 52.0 ), self::snapshot_row( 1, 3, 51.0 ) ),
			$summaries,
			null
		);

		$dto = RankingMapper::ranking( self::ranking_record(), $entries, 5, '', true, '2026-09-26T10:00:00Z' );
		$this->assertSame( '2026-09-26T10:00:00Z', $dto['calculatedAt'] );
		$this->assertSame( '2026-09-26T10:00:00Z', $dto['updatedAt'], 'A newer calculation also updates the page date' );
		$this->assertFalse( $dto['isThin'] );
		$this->assertTrue( $dto['indexable'] );
		$this->assertCount( 2, $dto['entries'] );

		$this->assertFalse( RankingMapper::ranking( self::ranking_record( true ), $entries, 5 )['indexable'] );
	}

	public function testRankingCarriesEditorialContentAndOnlyCompleteFaqItems(): void {
		$record                          = self::ranking_record();
		$record['fields']['summary']     = 'Short answer-first summary.';
		$record['fields']['faq']         = array(
			array(
				'question' => 'Q1?',
				'answer'   => 'A1.',
			),
			array(
				'question' => 'Q2 without answer?',
				'answer'   => null,
			),
		);
		$record['fields']['reviewed_by'] = 'Editor';
		$record['fields']['reviewed_at'] = '2026-09-20';

		$dto = RankingMapper::ranking( $record, array(), 5, '<p>Body</p>' );
		$this->assertSame( 'Short answer-first summary.', $dto['summary'] );
		$this->assertSame( '<p>Body</p>', $dto['body'] );
		$this->assertSame( $dto['body'], $dto['intro'] );
		$this->assertSame(
			array(
				array(
					'question' => 'Q1?',
					'answer'   => 'A1.',
				),
			),
			$dto['faq']
		);
		$this->assertSame(
			array(
				'reviewedBy' => 'Editor',
				'reviewedAt' => '2026-09-20',
			),
			$dto['editorial']
		);

		$list = RankingMapper::ranking( $record, array(), 5, '<p>Body</p>', false );
		$this->assertArrayNotHasKey( 'faq', $list, 'List items stay light' );
	}

	public function testEvidenceIsSortedByFieldThenSourceTier(): void {
		$claim  = static fn( string $field, string $type, string $at ): array => array(
			'field_name'          => $field,
			'value'               => 'x',
			'source_id'           => null,
			'source_url'          => 'https://example.com',
			'source_type'         => $type,
			'retrieved_at'        => $at,
			'confidence'          => 0.9,
			'verification_status' => 'verified',
		);
		$result = SourceMapper::evidence(
			array(
				$claim( 'rating', 'review_platform', '2026-09-01T00:00:00Z' ),
				$claim( 'bar_status', 'official_website', '2026-09-01T00:00:00Z' ),
				$claim( 'bar_status', 'official_registry', '2026-08-01T00:00:00Z' ),
			),
			array(),
			new SourceTiers()
		);
		$this->assertSame(
			array( array( 'bar_status', 1 ), array( 'bar_status', 2 ), array( 'rating', 4 ) ),
			array_map( static fn( array $e ): array => array( $e['field'], $e['source']['tier'] ), $result )
		);
	}
}
