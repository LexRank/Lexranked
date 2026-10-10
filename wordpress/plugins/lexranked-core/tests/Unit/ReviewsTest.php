<?php
/**
 * Client reviews: validation, display names, aggregates, lifecycle.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Reviews\ReviewRequest;
use LexRanked\Core\Reviews\ReviewStatus;
use LexRanked\Core\Schema\ValidationException;
use PHPUnit\Framework\TestCase;

final class ReviewsTest extends TestCase {

	private static function input( array $over = array() ): array {
		return $over + array(
			'entityType'  => 'lawyer',
			'entityId'    => 12,
			'rating'      => 5,
			'title'       => 'Clear and responsive',
			'body'        => "She explained every step of my case and answered my calls the same day.\nI would hire her again.",
			'name'        => 'maria   gonzalez',
			'email'       => 'Maria@Example.com',
			'serviceYear' => 2024,
			'client'      => true,
		);
	}

	public function testValidReviewIsNormalised(): void {
		$r = ReviewRequest::validate( self::input() );
		$this->assertSame( 'lawyer', $r['entity_type'] );
		$this->assertSame( 12, $r['entity_id'] );
		$this->assertSame( 5, $r['rating'] );
		$this->assertSame( 'maria@example.com', $r['reviewer_email'] );
		$this->assertSame( 'maria gonzalez', $r['reviewer_name'] );
		$this->assertStringContainsString( "\n", $r['body'], 'Line breaks are kept' );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalidInputs' )]
	public function testInvalidReviewsAreRejected( array $over, string $field ): void {
		try {
			ReviewRequest::validate( self::input( $over ) );
			$this->fail( 'Expected a validation error for ' . $field );
		} catch ( ValidationException $e ) {
			$this->assertSame( $field, $e->field_key );
		}
	}

	public static function invalidInputs(): array {
		return array(
			'rating 0'     => array( array( 'rating' => 0 ), 'rating' ),
			'rating 6'     => array( array( 'rating' => 6 ), 'rating' ),
			'short body'   => array( array( 'body' => 'Great lawyer.' ), 'body' ),
			'link in body' => array( array( 'body' => str_repeat( 'Good work overall. ', 3 ) . 'See https://example.com' ), 'body' ),
			'bad email'    => array( array( 'email' => 'nope' ), 'email' ),
			'future year'  => array( array( 'serviceYear' => 2999 ), 'serviceYear' ),
			'not a client' => array( array( 'client' => false ), 'client' ),
			'unknown type' => array( array( 'entityType' => 'judge' ), 'entityType' ),
		);
	}

	public function testPublicNameIsFirstNameAndInitial(): void {
		$this->assertSame( 'Maria G.', ReviewRequest::display_name( 'maria   gonzalez' ) );
		$this->assertSame( 'José L.', ReviewRequest::display_name( 'JOSÉ antonio LÓPEZ' ) );
		$this->assertSame( 'Ann', ReviewRequest::display_name( 'ann' ) );
		$this->assertSame( 'Client', ReviewRequest::display_name( '   ' ) );
	}

	public function testAggregateIsAverageToOneDecimalAndCount(): void {
		$this->assertSame(
			array(
				'count'   => 0,
				'average' => null,
			),
			ReviewRequest::aggregate( array() )
		);
		$this->assertSame(
			array(
				'count'   => 3,
				'average' => 4.3,
			),
			ReviewRequest::aggregate( array( 5, 4, 4 ) )
		);
		$this->assertSame(
			array(
				'count'   => 2,
				'average' => 3.0,
			),
			ReviewRequest::aggregate( array( 2, 4, 9 ) ),
			'Out-of-range ratings are ignored'
		);
	}

	public function testLifecycle(): void {
		$this->assertTrue( ReviewStatus::PendingEmail->can_become( ReviewStatus::PendingReview ) );
		$this->assertFalse( ReviewStatus::PendingEmail->can_become( ReviewStatus::Approved ), 'Unconfirmed reviews are never approved' );
		$this->assertTrue( ReviewStatus::PendingReview->can_become( ReviewStatus::Approved ) );
		$this->assertTrue( ReviewStatus::Approved->can_become( ReviewStatus::Rejected ), 'An editor can withdraw an approved review' );
	}
}
