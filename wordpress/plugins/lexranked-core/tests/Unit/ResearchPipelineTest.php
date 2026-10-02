<?php
/**
 * Research pipeline pure-logic tests: candidate validation, matching, job
 * lifecycle policy, log sanitizing, claim identity.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Repository\ClaimRepository;
use LexRanked\Core\Research\Backoff;
use LexRanked\Core\Research\CandidateInput;
use LexRanked\Core\Research\CandidateMatcher;
use LexRanked\Core\Research\JobPolicy;
use LexRanked\Core\Research\ResearchIngest;
use LexRanked\Core\Research\ResearchLog;
use LexRanked\Core\Schema\ValidationException;
use LexRanked\Core\Sources\SourceTiers;
use PHPUnit\Framework\TestCase;

final class ResearchPipelineTest extends TestCase {

	private const NOW = '2026-09-01T12:00:00Z';

	private static function candidate( array $overrides = array() ): array {
		return $overrides + array(
			'entity_type' => 'lawyer',
			'name'        => 'Jane A. Doe, Esq.',
			'city'        => 'Miami',
			'state'       => 'FL',
			'source_url'  => 'https://www.floridabar.org/directories/find-mbr/profile/?num=123',
			'source_type' => 'bar_association',
			'website'     => 'https://www.doe-law.example/',
		);
	}

	private static function entity( int $id, string $name, array $cities = array( 'Miami' ), array $states = array( 'FL' ), ?string $domain = null ): array {
		return array(
			'id'              => $id,
			'normalized_name' => $name,
			'cities'          => $cities,
			'states'          => $states,
			'domain'          => $domain,
		);
	}

	private static function probe( array $overrides = array() ): array {
		return $overrides + array(
			'entity_type'     => 'lawyer',
			'normalized_name' => 'jane doe',
			'city'            => 'Miami',
			'state'           => 'FL',
			'domain'          => null,
		);
	}

	// ─── CandidateInput ─────────────────────────────────────────────────────

	public function testCandidateIsNormalized(): void {
		$row = CandidateInput::validate( self::candidate( array( 'state' => 'florida' ) ), new SourceTiers() );
		$this->assertSame( 'FL', $row['state'] );
		$this->assertSame( 'jane doe', $row['normalized_name'] );
		$this->assertSame( 'Jane A. Doe, Esq.', $row['name'] );
		$this->assertSame( 40, strlen( $row['dedupe_key'] ) );

		$same = CandidateInput::validate( self::candidate( array( 'name' => 'JANE DOE' ) ), new SourceTiers() );
		$this->assertSame( $row['dedupe_key'], $same['dedupe_key'], 'Formatting differences must dedupe to one candidate.' );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalidCandidates(): array {
		return array(
			'type'          => array( array( 'entity_type' => 'judge' ), 'entity_type' ),
			'no source'     => array( array( 'source_url' => '' ), 'source_url' ),
			'ftp source'    => array( array( 'source_url' => 'ftp://example.com/x' ), 'source_url' ),
			'unknown tier'  => array( array( 'source_type' => 'rumor' ), 'source_type' ),
			'one-word name' => array( array( 'name' => 'Cher' ), 'name' ),
			'bad state'     => array( array( 'state' => 'Ontario' ), 'state' ),
			'city no state' => array( array( 'state' => '' ), 'state' ),
			'bad website'   => array( array( 'website' => 'javascript:alert(1)' ), 'website' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalidCandidates' )]
	public function testInvalidCandidatesAreRejected( array $override, string $field ): void {
		try {
			CandidateInput::validate( self::candidate( $override ), new SourceTiers() );
			$this->fail( 'Expected ValidationException' );
		} catch ( ValidationException $e ) {
			$this->assertSame( $field, $e->field_key );
		}
	}

	// ─── CandidateMatcher ───────────────────────────────────────────────────

	public function testNoExistingProfileCreatesADraft(): void {
		$this->assertSame( CandidateMatcher::CREATE, CandidateMatcher::decide( self::probe(), array() )['decision'] );
	}

	public function testSameNameSameCityMatches(): void {
		$result = CandidateMatcher::decide( self::probe(), array( self::entity( 7, 'jane doe' ) ) );
		$this->assertSame( CandidateMatcher::MATCH, $result['decision'] );
		$this->assertSame( 7, $result['entity_id'] );
	}

	public function testSameNameDifferentCityNeedsReview(): void {
		$result = CandidateMatcher::decide( self::probe(), array( self::entity( 7, 'jane doe', array( 'Tampa' ) ) ) );
		$this->assertSame( CandidateMatcher::REVIEW, $result['decision'] );
		$this->assertSame( 7, $result['entity_id'] );
	}

	public function testSameNameOtherStateNeedsReview(): void {
		$result = CandidateMatcher::decide( self::probe(), array( self::entity( 9, 'jane doe', array( 'Austin' ), array( 'TX' ) ) ) );
		$this->assertSame( CandidateMatcher::REVIEW, $result['decision'] );
	}

	public function testTwoProfilesWithTheSameNameNeedReview(): void {
		$result = CandidateMatcher::decide( self::probe(), array( self::entity( 3, 'jane doe' ), self::entity( 4, 'jane doe' ) ) );
		$this->assertSame( CandidateMatcher::REVIEW, $result['decision'] );
		$this->assertNull( $result['entity_id'] );
	}

	public function testFirmDomainMatchesAcrossNameVariants(): void {
		$probe  = self::probe(
			array(
				'entity_type'     => 'law_firm',
				'normalized_name' => 'doe partners',
				'domain'          => 'doe-law.example',
			)
		);
		$result = CandidateMatcher::decide( $probe, array( self::entity( 11, 'doe and partners', array( 'Miami' ), array( 'FL' ), 'doe-law.example' ) ) );
		$this->assertSame( CandidateMatcher::MATCH, $result['decision'] );
		$this->assertSame( 11, $result['entity_id'] );
	}

	public function testLawyerDomainAloneNeverMerges(): void {
		// Many lawyers share their firm's domain; a domain is corroboration, not identity.
		$probe  = self::probe( array( 'domain' => 'bigfirm.example' ) );
		$result = CandidateMatcher::decide( $probe, array( self::entity( 5, 'john roe', array( 'Miami' ), array( 'FL' ), 'bigfirm.example' ) ) );
		$this->assertSame( CandidateMatcher::CREATE, $result['decision'] );
	}

	public function testSimilarLawyerNameInSameCityNeedsReview(): void {
		$probe  = self::probe( array( 'normalized_name' => 'jon smith' ) );
		$result = CandidateMatcher::decide( $probe, array( self::entity( 21, 'jonathan smith' ) ) );
		$this->assertSame( CandidateMatcher::REVIEW, $result['decision'] );
		$this->assertSame( 21, $result['entity_id'] );
	}

	public function testMatcherIsOrderIndependent(): void {
		$a = array( self::entity( 2, 'jane doe', array( 'Tampa' ) ), self::entity( 1, 'jane doe', array( 'Orlando' ) ) );
		$this->assertSame( CandidateMatcher::decide( self::probe(), $a ), CandidateMatcher::decide( self::probe(), array_reverse( $a ) ) );
	}

	// ─── JobPolicy ──────────────────────────────────────────────────────────

	public function testClaimability(): void {
		$now     = new \DateTimeImmutable( self::NOW );
		$backoff = new Backoff( 300, 3600, 2 );

		$this->assertSame( JobPolicy::CLAIM_FRESH, JobPolicy::claimability( array( 'status' => 'pending' ), $backoff, $now ) );
		$this->assertNull( JobPolicy::claimability( array( 'status' => 'completed' ), $backoff, $now ) );
		$this->assertNull( JobPolicy::claimability( array( 'status' => 'cancelled' ), $backoff, $now ) );

		// Failed: only once the backoff elapsed, never when final (no next_retry_at).
		$this->assertNull(
			JobPolicy::claimability(
				array(
					'status'        => 'failed',
					'next_retry_at' => '2026-09-01T12:05:00Z',
				),
				$backoff,
				$now
			)
		);
		$this->assertSame(
			JobPolicy::CLAIM_RETRY,
			JobPolicy::claimability(
				array(
					'status'        => 'failed',
					'next_retry_at' => '2026-09-01T11:59:00Z',
				),
				$backoff,
				$now
			)
		);
		$this->assertNull(
			JobPolicy::claimability(
				array(
					'status'        => 'failed',
					'next_retry_at' => null,
				),
				$backoff,
				$now
			)
		);

		// Running: a live lease blocks; an expired lease resumes while retries remain.
		$this->assertNull(
			JobPolicy::claimability(
				array(
					'status'       => 'running',
					'locked_until' => '2026-09-01T12:01:00Z',
				),
				$backoff,
				$now
			)
		);
		$this->assertSame(
			JobPolicy::CLAIM_RESUME,
			JobPolicy::claimability(
				array(
					'status'       => 'running',
					'locked_until' => '2026-09-01T11:00:00Z',
					'retry_count'  => 1,
				),
				$backoff,
				$now
			)
		);
		$this->assertSame(
			JobPolicy::EXHAUSTED,
			JobPolicy::claimability(
				array(
					'status'       => 'running',
					'locked_until' => '2026-09-01T11:00:00Z',
					'retry_count'  => 2,
				),
				$backoff,
				$now
			)
		);
	}

	public function testFailureSchedulesExponentialRetriesThenStops(): void {
		$now     = new \DateTimeImmutable( self::NOW );
		$backoff = new Backoff( 300, 3600, 2 );

		$first = JobPolicy::after_failure( 0, true, $backoff, $now );
		$this->assertSame(
			array(
				'retry_count'   => 1,
				'next_retry_at' => '2026-09-01T12:05:00Z',
			),
			$first
		);
		$second = JobPolicy::after_failure( 1, true, $backoff, $now );
		$this->assertSame(
			array(
				'retry_count'   => 2,
				'next_retry_at' => '2026-09-01T12:10:00Z',
			),
			$second
		);
		$this->assertNull( JobPolicy::after_failure( 2, true, $backoff, $now )['next_retry_at'], 'Retries exhausted.' );
		$this->assertNull( JobPolicy::after_failure( 0, false, $backoff, $now )['next_retry_at'], 'Permanent errors are not retried.' );
	}

	public function testProgressIsSanitized(): void {
		$clean = JobPolicy::progress(
			array(
				'cursor'          => "row:42\n",
				'processed_count' => 3,
				'stats'           => array(
					'claims'     => '12',
					'Bad Key'    => 1,
					'candidates' => -5,
					'note'       => 'text',
					'__proto__'  => 1,
				),
			),
			10
		);
		$this->assertSame( 'row:42', $clean['cursor'] );
		$this->assertSame( 10, $clean['processed_count'], 'processed_count never goes backwards' );
		$this->assertSame(
			array(
				'candidates' => 0,
				'claims'     => 12,
			),
			$clean['stats']
		);
		$this->assertNull( JobPolicy::progress( array( 'cursor' => null ), 0 )['cursor'] );
	}

	public function testParamsMustBeAJsonObject(): void {
		$this->assertSame( array(), JobPolicy::params( '' ) );
		$this->assertSame( array( 'dataset' => 'fl' ), JobPolicy::params( '{"dataset":"fl"}' ) );
		$this->assertNull( JobPolicy::params( '[1,2]' ) );
		$this->assertNull( JobPolicy::params( '{broken' ) );
	}

	// ─── Log / claims / names ───────────────────────────────────────────────

	public function testLogEntriesAreValidatedAndRedacted(): void {
		$this->assertNull(
			ResearchLog::normalize(
				array(
					'level'   => 'fatal',
					'message' => 'x',
				)
			)
		);
		$this->assertNull(
			ResearchLog::normalize(
				array(
					'level'   => 'info',
					'message' => '   ',
				)
			)
		);
		$entry = ResearchLog::normalize(
			array(
				'level'   => 'warning',
				'stage'   => 'Fetch!',
				'message' => "line1\nline2",
				'context' => array(
					'url'           => 'https://example.com',
					'authorization' => 'Basic abc',
				),
			)
		);
		$this->assertSame( 'fetch', $entry['stage'] );
		$this->assertSame( 'line1 line2', $entry['message'] );
		$this->assertStringContainsString( '[redacted]', (string) $entry['context'] );
		$this->assertStringNotContainsString( 'Basic abc', (string) $entry['context'] );
	}

	public function testClaimHashIgnoresRetrievalTimeAndConfidence(): void {
		$row   = array(
			'entity_type'         => 'lawyer',
			'entity_id'           => 5,
			'field_name'          => 'phone',
			'value'               => '"+1 305 555 0100"',
			'source_id'           => null,
			'source_url'          => 'https://doe-law.example/',
			'source_type'         => 'official_website',
			'retrieved_at'        => '2026-01-01 00:00:00',
			'confidence'          => 0.8,
			'verification_status' => 'pending',
		);
		$later = array(
			'retrieved_at' => '2026-06-01 00:00:00',
			'confidence'   => 0.9,
		) + $row;
		$this->assertSame( ClaimRepository::hash( $row ), ClaimRepository::hash( $later ) );
		$this->assertNotSame( ClaimRepository::hash( $row ), ClaimRepository::hash( array( 'value' => '"+1 305 555 0199"' ) + $row ) );
		$this->assertNotSame( ClaimRepository::hash( $row ), ClaimRepository::hash( array( 'source_url' => 'https://other.example/' ) + $row ) );
	}

	public function testPersonNamesAreSplitWithoutInventingParts(): void {
		$this->assertSame( 'Jane A. Doe', ResearchIngest::display_name( 'Jane A. Doe, Esq.', false ) );
		$this->assertSame( 'John Roe', ResearchIngest::display_name( 'Hon. John  Roe', false ) );
		$this->assertSame( 'Doe & Roe, P.A.', ResearchIngest::display_name( 'Doe & Roe, P.A.', true ) );
		$this->assertSame( array( 'Jane', 'Doe' ), ResearchIngest::split_person_name( 'Jane A. Doe' ) );
		$this->assertSame( array( 'Robert', 'King Jr.' ), ResearchIngest::split_person_name( 'Robert King Jr.' ) );
		$this->assertSame( 'bar_status', ResearchIngest::freshness_category( 'license' ) );
		$this->assertSame( 'profile', ResearchIngest::freshness_category( 'identity' ) );
	}
}
