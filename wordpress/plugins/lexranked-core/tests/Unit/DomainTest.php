<?php
/**
 * Domain enums, post type definitions and demo data tests.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\CLI\DemoData;
use LexRanked\Core\Domain\CommercialStatus;
use LexRanked\Core\Domain\ResearchJobStatus;
use LexRanked\Core\PostTypes\LawFirm;
use LexRanked\Core\PostTypes\Lawyer;
use LexRanked\Core\PostTypes\Ranking;
use LexRanked\Core\PostTypes\ResearchJob;
use LexRanked\Core\PostTypes\Source;
use LexRanked\Core\PostTypes\VerificationRecord;
use LexRanked\Core\Services;
use LexRanked\Core\Sources\SourceTiers;
use PHPUnit\Framework\TestCase;

final class DomainTest extends TestCase {

	public function testResearchJobLifecycle(): void {
		$this->assertTrue( ResearchJobStatus::Pending->can_transition_to( ResearchJobStatus::Running ) );
		$this->assertTrue( ResearchJobStatus::Running->can_transition_to( ResearchJobStatus::Failed ) );
		$this->assertTrue( ResearchJobStatus::Failed->can_transition_to( ResearchJobStatus::Pending ) );
		$this->assertFalse( ResearchJobStatus::Pending->can_transition_to( ResearchJobStatus::Completed ) );
		$this->assertFalse( ResearchJobStatus::Completed->can_transition_to( ResearchJobStatus::Running ) );
		$this->assertFalse( ResearchJobStatus::Cancelled->can_transition_to( ResearchJobStatus::Pending ) );
	}

	public function testPaidPlacementStatuses(): void {
		$paid = array_values( array_filter( CommercialStatus::cases(), static fn( CommercialStatus $s ): bool => $s->is_paid_placement() ) );
		$this->assertSame( array( CommercialStatus::Featured, CommercialStatus::Sponsored ), $paid );
	}

	/**
	 * @return array<int, \LexRanked\Core\PostTypes\PostType>
	 */
	private static function types(): array {
		return array( new Lawyer(), new LawFirm(), new Ranking(), new Source( new SourceTiers() ), new VerificationRecord(), new ResearchJob() );
	}

	public function testPostTypeDefinitionsAreConsistent(): void {
		foreach ( self::types() as $type ) {
			$this->assertLessThanOrEqual( 20, strlen( $type->slug() ), $type->slug() );
			$keys = array_map( static fn( $f ): string => $f->key, $type->fields() );
			$this->assertSame( $keys, array_unique( $keys ), $type->slug() . ' has duplicate fields' );
			$this->assertFalse( $type->args()['show_in_rest'], 'CPTs are exposed only via lexranked/v1 DTOs' );
			$this->assertFalse( $type->args()['public'] );
		}
	}

	public function testPrivateAndSystemFields(): void {
		$lawyer = new Lawyer();
		$this->assertFalse( $lawyer->field( 'email' )?->is_public );
		$this->assertTrue( $lawyer->field( 'score' )?->read_only, 'Scores are engine-written only' );
		$this->assertTrue( $lawyer->field( 'score_version' )?->read_only );
		$this->assertTrue( $lawyer->field( 'commercial_status' )?->read_only, 'Derived from claims and placements, never typed in' );
		$this->assertFalse( ( new VerificationRecord() )->field( 'notes' )?->is_public );
		$this->assertSame( array( 'lr_research_job', 'lr_research_jobs' ), ( new ResearchJob() )->capability_type() );
	}

	public function testClaimsCannotTargetSystemOrCommercialFields(): void {
		$fields = Services::traceable_fields( new Lawyer() );
		$this->assertContains( 'bar_status', $fields );
		foreach ( array( 'score', 'score_version', 'commercial_status', 'is_demo', 'email' ) as $forbidden ) {
			$this->assertNotContains( $forbidden, $fields );
		}
	}

	public function testDemoDataIsUnmistakablyFake(): void {
		foreach ( DemoData::lawyers() as $lawyer ) {
			$this->assertStringEndsWith( '(Demo)', $lawyer['title'] );
			$this->assertMatchesRegularExpression( '/^305-555-01\d\d$/', $lawyer['phone'] );
			$this->assertStringStartsWith( 'https://example.com/', $lawyer['website'] );
			$this->assertStringStartsWith( 'DEMO-', $lawyer['bar_number'] );
		}
		foreach ( DemoData::firms() as $firm ) {
			$this->assertStringEndsWith( '(Demo)', $firm['title'] );
			$this->assertStringStartsWith( 'https://example.com/', $firm['website'] );
		}
		foreach ( DemoData::sources() as $source ) {
			$this->assertStringEndsWith( '(Demo)', $source['title'] );
		}
	}
}
