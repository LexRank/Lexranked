<?php
/**
 * Article DTO tests.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\REST\DTO\ArticleMapper;
use PHPUnit\Framework\TestCase;

final class ArticleMapperTest extends TestCase {

	private static function record( array $fields = array() ): array {
		return array(
			'id'         => 5,
			'slug'       => 'how-to-read',
			'title'      => 'How to read a ranking',
			'created_at' => '2026-09-01T10:00:00Z',
			'updated_at' => '2026-09-02T10:00:00Z',
			'fields'     => $fields + array(
				'related_ranking' => 12,
				'reviewed_by'     => 'Editor',
				'reviewed_at'     => '2026-09-02',
				'is_demo'         => false,
			),
		);
	}

	public function testSummaryCountsWordsAndFlagsThinArticles(): void {
		$body = '<p>' . str_repeat( 'word ', 299 ) . '</p>';
		$dto  = ArticleMapper::summary( self::record(), '', $body, array( 'name' => 'Ana' ), null, array() );
		$this->assertSame( '/articles/how-to-read/', $dto['path'] );
		$this->assertSame( 299, $dto['wordCount'] );
		$this->assertTrue( $dto['isThin'] );
		$this->assertSame( 1, $dto['readingMinutes'] );
		$this->assertSame( 12, $dto['relatedRankingId'] );
		$this->assertStringEndsWith( '…', $dto['excerpt'] );

		$this->assertFalse( $dto['eligibility']['indexable'], 'Etap G: thin articles are noindex' );
		$this->assertSame( array( 'Not indexed: words 299 (needs 300).' ), $dto['eligibility']['reasons'] );

		$long = ArticleMapper::summary( self::record(), 'Manual excerpt.', $body . '<p>more</p>', array( 'name' => 'Ana' ), null, array() );
		$this->assertFalse( $long['isThin'] );
		$this->assertTrue( $long['eligibility']['indexable'] );
		$this->assertSame( 'Manual excerpt.', $long['excerpt'] );
	}

	public function testDetailAddsBodyAndRelatedRanking(): void {
		$summary = ArticleMapper::summary( self::record( array( 'is_demo' => true ) ), '', '<p>Hi &amp; bye</p>', array( 'name' => 'Ana' ), null, array() );
		$detail  = ArticleMapper::detail(
			$summary,
			'<p>Hi &amp; bye</p>',
			array(
				'id'    => 12,
				'title' => 'R',
				'path'  => '/rankings/florida/',
			)
		);
		$this->assertTrue( $detail['isDemo'] );
		$this->assertSame( 3, $detail['wordCount'] );
		$this->assertSame( '/rankings/florida/', $detail['relatedRanking']['path'] );
	}
}
