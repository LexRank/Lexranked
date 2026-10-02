<?php
/**
 * AI content draft validation tests.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\PostTypes\ContentDraft;
use LexRanked\Core\Research\ContentDraftInput;
use LexRanked\Core\Schema\ValidationException;
use PHPUnit\Framework\TestCase;

final class ContentDraftInputTest extends TestCase {

	private static function payload( array $overrides = array() ): array {
		return array_replace_recursive(
			array(
				'content_type'   => 'ranking_content',
				'target_id'      => 12,
				'content'        => array(
					'summary'  => 'Three lawyers are ranked.',
					'sections' => array(
						array(
							'heading'    => 'How the ranking works',
							'paragraphs' => array( array( 'text' => 'Scores use methodology v1.0.' ) ),
						),
					),
					'faq'      => array(
						array(
							'question' => 'Who is first?',
							'answer'   => 'A | B',
						),
					),
				),
				'facts'          => array(
					array(
						'id'    => 'F1',
						'label' => 'Methodology',
						'value' => 'v1.0',
					),
				),
				'qa'             => array(
					'status' => 'ready_for_review',
					'issues' => array(),
				),
				'model'          => 'test-model',
				'prompt_version' => 'ranking-content/1',
			),
			$overrides
		);
	}

	public function testValidDraftIsNormalized(): void {
		$draft = ContentDraftInput::validate( self::payload() );
		$this->assertSame( ContentDraft::QA_READY, $draft['qa_status'] );
		$this->assertSame( array( 'Scores use methodology v1.0.' ), $draft['sections'][0]['paragraphs'] );
		$this->assertSame( "<h2>How the ranking works</h2>\n<p>Scores use methodology v1.0.</p>\n", ContentDraftInput::to_html( $draft['sections'] ) );
		$this->assertSame(
			"<h2>What to bring</h2>\n<p>Bring your documents.</p>\n<ul>\n<li>Police report</li>\n<li>&lt;b&gt;Bills&lt;/b&gt;</li>\n</ul>\n<p>More detail.</p>\n",
			ContentDraftInput::to_html(
				array(
					array(
						'heading'    => 'What to bring',
						'paragraphs' => array( 'Bring your documents.', 'More detail.' ),
						'bullets'    => array( 'Police report', '<b>Bills</b>' ),
					),
				)
			),
			'Bullets follow the answering paragraph and are escaped'
		);
		$this->assertSame( 'A / B', ContentDraftInput::faq_for_field( $draft['faq'] )[0]['answer'] );
	}

	public function testErrorIssuesForceNeedsReview(): void {
		$draft = ContentDraftInput::validate(
			self::payload(
				array(
					'qa' => array(
						'status' => 'ready_for_review',
						'issues' => array(
							array(
								'code'     => 'unsupported_number',
								'severity' => 'error',
								'message'  => '42 is not in the facts',
							),
						),
					),
				)
			)
		);
		$this->assertSame( ContentDraft::QA_NEEDS_REVIEW, $draft['qa_status'] );
		$this->assertSame( ContentDraft::QA_NEEDS_REVIEW, ContentDraftInput::validate( self::payload( array( 'qa' => array( 'status' => 'applied' ) ) ) )['qa_status'] );
	}

	public function testMarkupIsRejected(): void {
		$this->expectException( ValidationException::class );
		ContentDraftInput::validate( self::payload( array( 'content' => array( 'summary' => 'Click <a href="https://x.test">here</a>' ) ) ) );
	}

	public function testFactsKeepTheirStatusAndOrigin(): void {
		$payload                   = self::payload();
		$payload['facts'][0]       = array(
			'id'     => 'F1',
			'label'  => 'Average client rating',
			'value'  => '4.7 out of 5 across 8 lawyers',
			'status' => 'computed',
			'origin' => 'market mkt-1.0',
		);
		$payload['prompt_version'] = 'interp/1+ranking-content/2';
		$draft                     = ContentDraftInput::validate( $payload );
		$this->assertSame( 'computed', $draft['facts'][0]['status'] );
		$this->assertSame( 'market mkt-1.0', $draft['facts'][0]['origin'] );
		$this->assertSame( 'interp/1+ranking-content/2', $draft['prompt_version'] );

		$this->assertSame( '', ContentDraftInput::validate( self::payload() )['facts'][0]['status'], 'Older workers send no status' );

		$payload['facts'][0]['status'] = 'guessed';
		$this->expectException( ValidationException::class );
		ContentDraftInput::validate( $payload );
	}

	public function testFactsAreRequired(): void {
		$payload          = self::payload();
		$payload['facts'] = array();
		$this->expectException( ValidationException::class );
		ContentDraftInput::validate( $payload );
	}

	public function testUnknownContentTypeIsRejected(): void {
		$this->expectException( ValidationException::class );
		ContentDraftInput::validate( self::payload( array( 'content_type' => 'press_release' ) ) );
	}

	public function testTooManySectionsAreRejected(): void {
		$payload                        = self::payload();
		$payload['content']['sections'] = array_fill( 0, 9, $payload['content']['sections'][0] );
		$this->expectException( ValidationException::class );
		ContentDraftInput::validate( $payload );
	}

	public function testProfileSummaryIsOnlyASummary(): void {
		$payload                        = self::payload( array( 'content_type' => 'profile_summary' ) );
		$payload['content']['sections'] = array();
		$payload['content']['faq']      = array();
		$this->assertSame( 'profile_summary', ContentDraftInput::validate( $payload )['content_type'] );
		$this->expectException( ValidationException::class );
		ContentDraftInput::validate( self::payload( array( 'content_type' => 'profile_summary' ) ) );
	}

	public function testHubContentNeedsATerm(): void {
		$payload = self::payload(
			array(
				'content_type'    => 'hub_content',
				'target_term'     => 7,
				'target_taxonomy' => 'lr_location',
			)
		);
		$this->assertSame( 7, ContentDraftInput::validate( $payload )['target_term'] );
		$this->expectException( ValidationException::class );
		ContentDraftInput::validate(
			self::payload(
				array(
					'content_type'    => 'hub_content',
					'target_taxonomy' => 'category',
				)
			)
		);
	}

	public function testArticleNeedsATitleAndTwoSections(): void {
		$payload                          = self::payload( array( 'content_type' => 'article' ) );
		$payload['target_id']             = null;
		$payload['content']['title']      = 'Choosing a lawyer';
		$payload['content']['sections'][] = $payload['content']['sections'][0];
		$draft                            = ContentDraftInput::validate( $payload );
		$this->assertSame( 'Choosing a lawyer', $draft['title'] );
		$this->assertNull( $draft['target_id'] );
		unset( $payload['content']['title'] );
		$this->expectException( ValidationException::class );
		ContentDraftInput::validate( $payload );
	}
}
