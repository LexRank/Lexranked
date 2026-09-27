<?php
/**
 * Entity layer (Etap A): stable identities independent of names.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Database\Schema;
use LexRanked\Core\Entity\EntityNames;
use LexRanked\Core\Entity\EntityType;
use LexRanked\Core\Research\CandidateMatcher;
use PHPUnit\Framework\TestCase;

final class EntityTest extends TestCase {

	public function testTypesMapToWordPressStorage(): void {
		$this->assertSame( array( 'lawyer', 'law_firm', 'location', 'practice_area' ), EntityType::values() );
		$this->assertSame( 'post', EntityType::Lawyer->wp_object() );
		$this->assertSame( 'term', EntityType::PracticeArea->wp_object() );
		$this->assertSame( EntityType::LawFirm, EntityType::from_wp_kind( 'lr_law_firm' ) );
		$this->assertSame( EntityType::Location, EntityType::from_wp_kind( 'lr_location' ) );
		$this->assertNull( EntityType::from_wp_kind( 'lr_ranking' ), 'Rankings are functions over entities, not entities' );
	}

	public function testPaths(): void {
		$this->assertSame( '/lawyers/jane-doe/', EntityType::Lawyer->path( 'jane-doe' ) );
		$this->assertSame( '/states/florida/', EntityType::Location->path( 'florida' ) );
		$this->assertSame( '/cities/miami/', EntityType::Location->path( 'miami', true ) );
		$this->assertSame( '/practice-areas/personal-injury/', EntityType::PracticeArea->path( 'personal-injury' ) );
	}

	public function testStatusFollowsPublication(): void {
		$this->assertSame( EntityNames::ACTIVE, EntityNames::status_for_post( 'publish' ) );
		$this->assertSame( EntityNames::DRAFT, EntityNames::status_for_post( 'pending' ) );
		$this->assertSame( EntityNames::ARCHIVED, EntityNames::status_for_post( 'trash' ) );
	}

	public function testRenameProducesAliasesNotANewIdentity(): void {
		$row = array(
			'canonical_name' => 'Smith Law',
			'slug'           => 'smith-law',
			'status'         => EntityNames::ACTIVE,
		);
		$this->assertFalse( EntityNames::changed( $row, 'Smith Law', 'smith-law', EntityNames::ACTIVE ) );
		$this->assertTrue( EntityNames::changed( $row, 'Smith Law Group', 'smith-law-group', EntityNames::ACTIVE ) );
		$this->assertFalse( EntityNames::changed( array( 'status' => EntityNames::MERGED ) + $row, 'Smith Law', 'smith-law', EntityNames::ACTIVE ), 'A merged entity stays merged' );

		$aliases = EntityNames::aliases( EntityType::LawFirm, 'Smith Law Group, P.A.', 'smith-law-group' );
		$this->assertSame( array( 'name', 'slug' ), array_column( $aliases, 'alias_type' ) );
		$this->assertSame( EntityNames::normalize( EntityType::LawFirm, 'Smith Law Group PA' ), $aliases[0]['normalized'] );
		$this->assertSame( 'miami beach', EntityNames::normalize( EntityType::Location, '  Miami   Beach ' ) );
	}

	public function testResearchMatchesAFormerName(): void {
		$entity    = array(
			'id'              => 7,
			'normalized_name' => 'smith injury group',
			'cities'          => array( 'Miami' ),
			'states'          => array( 'FL' ),
			'domain'          => null,
			'aliases'         => array( 'smith injury group', 'smith law' ),
		);
		$candidate = array(
			'entity_type'     => 'law_firm',
			'normalized_name' => 'smith law',
			'city'            => 'Miami',
			'state'           => 'FL',
			'domain'          => null,
		);
		$result    = CandidateMatcher::decide( $candidate, array( $entity ) );
		$this->assertSame( CandidateMatcher::MATCH, $result['decision'] );
		$this->assertSame( 7, $result['entity_id'] );
		unset( $entity['aliases'] );
		$this->assertSame( CandidateMatcher::CREATE, CandidateMatcher::decide( $candidate, array( $entity ) )['decision'], 'Without the alias it would be a new entity' );
	}

	public function testRegistryTablesKeepIdentityOutOfNames(): void {
		$sql = Schema::statements( 'wp_', '' );
		$this->assertStringContainsString( 'UNIQUE KEY wp_ref (wp_object,wp_id)', $sql['wp_lr_entities'] );
		$this->assertStringNotContainsString( 'UNIQUE KEY type_slug', $sql['wp_lr_entities'], 'Slugs and names are not identity' );
		$this->assertStringContainsString( 'merged_into', $sql['wp_lr_entities'] );
		$this->assertStringContainsString( 'is_current', $sql['wp_lr_entity_aliases'] );
	}
}
