<?php
/**
 * Etap G: page eligibility engine.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Eligibility\PageEligibility;
use LexRanked\Core\Ranking\ContextEligibility;
use PHPUnit\Framework\TestCase;

final class PageEligibilityTest extends TestCase {

	public function testTheBriefsExamples(): void {
		// "Miami · Personal Injury · 127 lawyers · 82 verified → eligible".
		$miami = PageEligibility::evaluate(
			'hub',
			array(
				'entities' => 127,
				'real'     => 127,
				'verified' => 82,
				'coverage' => 0.74,
			)
		);
		$this->assertTrue( $miami['exists'] );
		$this->assertTrue( $miami['indexable'] );
		$this->assertSame( array(), $miami['reasons'] );

		// "Small town · rare practice area · 2 profiles · 0 verified → not eligible".
		$small = PageEligibility::evaluate(
			'hub',
			array(
				'entities' => 2,
				'real'     => 2,
				'verified' => 0,
				'coverage' => 0.2,
			)
		);
		$this->assertFalse( $small['exists'] );
		$this->assertFalse( $small['indexable'] );
		$this->assertSame( array( 'No page: published lawyers 2 (needs 3).' ), $small['reasons'], 'A page that cannot exist lists only what stops it' );
	}

	public function testExistingButNotIndexable(): void {
		$demo = PageEligibility::evaluate(
			'ranking',
			array(
				'entities' => 8,
				'real'     => false,
				'verified' => 5,
				'coverage' => 1.0,
			)
		);
		$this->assertTrue( $demo['exists'] );
		$this->assertFalse( $demo['indexable'] );
		$this->assertSame( array( 'Not indexed: demo data.' ), $demo['reasons'] );

		$thin_evidence = PageEligibility::evaluate(
			'ranking',
			array(
				'entities' => 8,
				'real'     => true,
				'verified' => 5,
				'coverage' => 0.3,
			)
		);
		$this->assertTrue( $thin_evidence['exists'] );
		$this->assertFalse( $thin_evidence['indexable'] );
		$this->assertSame( array( 'Not indexed: evidence coverage 30% (needs 60%).' ), $thin_evidence['reasons'] );
	}

	public function testOverridesAndChecksAreExplained(): void {
		$result = PageEligibility::evaluate(
			'ranking',
			array(
				'entities' => 3,
				'real'     => true,
				'verified' => 3,
				'coverage' => 0.9,
			),
			array( 'entities' => 3 )
		);
		$this->assertTrue( $result['indexable'], "A ranking's own minimum overrides the default" );
		$this->assertSame( 'pe-1.0', $result['version'] );
		foreach ( $result['checks'] as $check ) {
			$this->assertSame( array( 'key', 'label', 'value', 'required', 'level', 'passed' ), array_keys( $check ) );
		}
	}

	public function testContextualRankingsNeedTheirContext(): void {
		$context = ContextEligibility::evaluate(
			array(
				'parent'    => 8,
				'qualified' => 3,
				'verified'  => 0,
			),
			5,
			3
		);
		$result  = PageEligibility::evaluate(
			'ranking',
			array(
				'entities' => 6,
				'real'     => true,
				'verified' => 6,
				'coverage' => 1.0,
			),
			array(),
			$context
		);
		$this->assertFalse( $result['exists'] );
		$this->assertSame( $context['reasons'], $result['reasons'] );
		$this->assertSame( 'context', end( $result['checks'] )['key'] );
	}

	public function testProfilesArticlesComparisonsListings(): void {
		$this->assertTrue(
			PageEligibility::evaluate(
				'profile',
				array(
					'real'     => true,
					'coverage' => 0.2,
				)
			)['exists'],
			'Published profiles always exist'
		);
		$this->assertFalse(
			PageEligibility::evaluate(
				'profile',
				array(
					'real'     => true,
					'coverage' => 0.2,
				)
			)['indexable'],
			'A profile with little sourced evidence stays noindex'
		);
		$this->assertTrue(
			PageEligibility::evaluate(
				'profile',
				array(
					'real'     => true,
					'coverage' => 0.8,
				)
			)['indexable']
		);
		$this->assertFalse(
			PageEligibility::evaluate(
				'article',
				array(
					'real'  => true,
					'words' => 120,
				)
			)['indexable']
		);
		$comparison = PageEligibility::evaluate(
			'comparison',
			array(
				'entities' => 2,
				'curated'  => 0,
			)
		);
		$this->assertTrue( $comparison['exists'] );
		$this->assertFalse( $comparison['indexable'] );
		$this->assertSame( array( 'Not indexed: not curated for search.' ), $comparison['reasons'] );
		$this->assertTrue( PageEligibility::evaluate( 'listing', array( 'real' => 1 ) )['indexable'] );

		$this->expectException( \InvalidArgumentException::class );
		PageEligibility::evaluate( 'keyword_page', array() );
	}

	public function testThePublishedModelMatchesTheRules(): void {
		$model = PageEligibility::model();
		$this->assertSame( 'pe-1.0', $model['version'] );
		$this->assertSame( PageEligibility::types(), array_values( array_diff( array_keys( $model['types'] ), array() ) ) );
		$keys = array_column( $model['types']['ranking'], 'key' );
		$this->assertSame( array( 'entities', 'real', 'verified', 'coverage', 'context' ), $keys );
		foreach ( $model['types'] as $rules ) {
			foreach ( $rules as $rule ) {
				$this->assertNotSame( '', $rule['description'] );
				$this->assertContains( $rule['level'], array( PageEligibility::EXIST, PageEligibility::INDEX ) );
			}
		}
	}

	public function testNoKeywordOrSearchVolumeInput(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Eligibility/PageEligibility.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$code   = preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $source );
		$this->assertDoesNotMatchRegularExpression( '/keyword|search_volume|traffic/i', (string) $code );
	}
}
