<?php
/**
 * Generated hub text.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Content\HubContentBuilder;
use LexRanked\Core\Content\KnowledgeStore;
use LexRanked\Core\Content\RankingContentService;
use PHPUnit\Framework\TestCase;

final class HubContentTest extends TestCase {

	/**
	 * Every H2 is followed by a paragraph that opens with a bold answer.
	 *
	 * @param string $body Body HTML.
	 */
	private function assertAnswerFirst( string $body ): void {
		preg_match_all( '#<h2>[^<]+</h2>\n(<[a-z]+[^>]*>)(<strong>)?#', $body, $m, PREG_SET_ORDER );
		$this->assertNotEmpty( $m );
		foreach ( $m as $match ) {
			if ( str_contains( $match[0], 'Sources' ) ) {
				continue;
			}
			$this->assertSame( '<p>', $match[1], $match[0] );
			$this->assertSame( '<strong>', $match[2] ?? '', $match[0] );
		}
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $body );
	}

	public function testAreaHubAnswersEveryQuestionFromTheRankings(): void {
		$c = HubContentBuilder::area(
			RankingContentService::load_pack( 'FL' ),
			array(
				'area_slug' => 'family-law',
				'area_name' => 'Family',
				'rankings'  => array(
					array(
						'path'    => '/rankings/florida/family-law/',
						'city'    => null,
						'entries' => 63,
					),
					array(
						'path'    => '/rankings/florida/tampa/family-law/',
						'city'    => 'Tampa',
						'entries' => 12,
					),
					array(
						'path'    => '/rankings/florida/miami/family-law/',
						'city'    => 'Miami',
						'entries' => 7,
					),
				),
			),
			'October 2026',
			'2026-10-10'
		);
		$this->assertNotNull( $c );
		$this->assertStringStartsWith( 'LexRanked ranks 63 family lawyers in Florida in 2 cities; the largest city rankings are Tampa (12 lawyers) and Miami (7 lawyers).', $c['summary'] );
		$this->assertAnswerFirst( $c['body'] );
		foreach ( array( 'What does a family lawyer do?', 'When do you need a family lawyer?', 'Who are the best family lawyers in Florida?', 'Where can I find a family lawyer in Florida?', 'How much does a family lawyer cost in Florida?', 'Florida family rules to know', 'How to choose a family lawyer', 'Sources' ) as $heading ) {
			$this->assertStringContainsString( '<h2>' . $heading . '</h2>', $c['body'] );
		}
		$this->assertStringContainsString( 'the top 25 of the 63 family lawyers', $c['body'] );
		$this->assertStringContainsString( 'href="/rankings/florida/tampa/family-law/"', $c['body'] );
		$this->assertStringContainsString( 'section 61.16', $c['body'] );
		$this->assertStringContainsString( 'Rule 4-1.5', $c['body'] );
		$questions = array_column( $c['faq'], 'question' );
		$this->assertContains( 'How much does a family lawyer cost in Florida?', $questions );
		$this->assertContains( 'Where are the best family lawyers in Florida?', $questions );
		foreach ( $c['faq'] as $item ) {
			$this->assertNotSame( '', trim( $item['answer'] ) );
		}
	}

	public function testOnlyPublishedGuidesAreLinked(): void {
		$pack             = RankingContentService::load_pack( 'FL' );
		$pack['articles'] = array( '/articles/florida-divorce-process/' );
		$c                = HubContentBuilder::area(
			$pack,
			array(
				'area_slug' => 'family-law',
				'area_name' => 'Family',
				'rankings'  => array(
					array(
						'path'    => '/rankings/florida/tampa/family-law/',
						'city'    => 'Tampa',
						'entries' => 12,
					),
				),
			),
			'October 2026',
			'2026-10-10'
		);
		$this->assertStringContainsString( 'href="/articles/florida-divorce-process/"', $c['body'] );
		$this->assertStringNotContainsString( 'href="/articles/florida-child-custody-time-sharing/"', $c['body'] );
		$pack['articles'] = array();
		$c                = HubContentBuilder::area(
			$pack,
			array(
				'area_slug' => 'family-law',
				'area_name' => 'Family',
				'rankings'  => array(
					array(
						'path'    => '/rankings/florida/tampa/family-law/',
						'city'    => 'Tampa',
						'entries' => 12,
					),
				),
			),
			'October 2026',
			'2026-10-10'
		);
		$this->assertStringNotContainsString( '<h2>Guides</h2>', $c['body'] );
		$this->assertStringNotContainsString( '/articles/', $c['body'] );
	}

	public function testNothingToDescribeMeansNoText(): void {
		$pack = RankingContentService::load_pack( 'FL' );
		$this->assertNull(
			HubContentBuilder::area(
				$pack,
				array(
					'area_slug' => 'family-law',
					'area_name' => 'Family',
					'rankings'  => array(),
				),
				'October 2026',
				'2026-10-10'
			)
		);
		$this->assertNull(
			HubContentBuilder::area(
				$pack,
				array(
					'area_slug' => 'dui',
					'area_name' => 'DUI',
					'rankings'  => array(
						array(
							'path'    => '/x/',
							'city'    => 'Miami',
							'entries' => 5,
						),
					),
				),
				'October 2026',
				'2026-10-10'
			),
			'no knowledge for the area'
		);
		$this->assertNull(
			HubContentBuilder::city(
				$pack,
				array(
					'city_slug' => 'miami',
					'city_name' => 'Miami',
					'lawyers'   => 10,
					'rankings'  => array(
						array(
							'path'      => '/x/',
							'area'      => 'Real Estate',
							'area_slug' => 'real-estate',
							'entries'   => 5,
							'language'  => 'Spanish',
						),
					),
					'statewide' => array(),
				),
				'October 2026',
				'2026-10-10'
			),
			'a language ranking alone is not a hub'
		);
	}

	public function testCityHubCoversCourtsLanguagesAndStatewideLinks(): void {
		$c = HubContentBuilder::city(
			RankingContentService::load_pack( 'FL' ),
			array(
				'city_slug' => 'miami',
				'city_name' => 'Miami',
				'lawyers'   => 246,
				'rankings'  => array(
					array(
						'path'      => '/rankings/florida/miami/real-estate/',
						'area'      => 'Real Estate',
						'area_slug' => 'real-estate',
						'entries'   => 16,
						'language'  => null,
					),
					array(
						'path'      => '/rankings/florida/miami/real-estate/spanish-speaking/',
						'area'      => 'Real Estate',
						'area_slug' => 'real-estate',
						'entries'   => 5,
						'language'  => 'Spanish',
					),
				),
				'statewide' => array( 'real-estate' => '/rankings/florida/real-estate/' ),
			),
			'October 2026',
			'2026-10-10'
		);
		$this->assertNotNull( $c );
		$this->assertStringContainsString( 'Miami-Dade County, which Florida\'s Eleventh Judicial Circuit serves', $c['summary'] );
		$this->assertAnswerFirst( $c['body'] );
		$this->assertStringContainsString( 'href="/rankings/florida/real-estate/"', $c['body'] );
		$this->assertStringContainsString( 'Spanish-speaking real estate lawyers in Miami', $c['body'] );
		$this->assertStringContainsString( '64,009', $c['body'], 'crash figure from the pack' );
		$this->assertContains( 'Are there Spanish-speaking lawyers in Miami?', array_column( $c['faq'], 'question' ) );
	}

	public function testStateHubListsStatewideRankingsAndCities(): void {
		$c = HubContentBuilder::state(
			RankingContentService::load_pack( 'FL' ),
			array(
				'lawyers'   => 1800,
				'statewide' => array(
					array(
						'path'    => '/rankings/florida/construction-law/',
						'area'    => 'Construction',
						'entries' => 304,
					),
				),
				'cities'    => array(
					array(
						'path'     => '/cities/tampa/',
						'city'     => 'Tampa',
						'rankings' => 15,
					),
				),
			),
			'October 2026',
			'2026-10-10'
		);
		$this->assertNotNull( $c );
		$this->assertStringStartsWith( 'LexRanked lists 1800 Florida lawyers and ranks them in 16 rankings', $c['summary'] );
		$this->assertAnswerFirst( $c['body'] );
		$this->assertStringContainsString( '20 judicial circuits', $c['body'] );
		$this->assertStringContainsString( 'href="/cities/tampa/"', $c['body'] );
	}

	public function testHubTextInThePackAndOverTheApiMeetsTheRules(): void {
		$pack = RankingContentService::load_pack( 'FL' );
		foreach ( $pack['areas'] as $slug => $area ) {
			$this->assertArrayHasKey( 'hub', $area, $slug );
			$this->assertSame( array(), KnowledgeStore::hub_errors( $area['hub'] ), $slug );
		}
		$hub            = $pack['areas']['family-law']['hub'];
		$hub['matters'] = array();
		$this->assertNotSame( array(), KnowledgeStore::hub_errors( $hub ) );
		$hub            = $pack['areas']['family-law']['hub'];
		$hub['costSrc'] = array( array( 'http://insecure.test/', 'x' ) );
		$this->assertNotSame( array(), KnowledgeStore::hub_errors( $hub ) );
	}
}
