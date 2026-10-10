<?php
/**
 * Contact form validation.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Contact\ContactException;
use LexRanked\Core\Contact\ContactService;
use PHPUnit\Framework\TestCase;

final class ContactTest extends TestCase {

	private static function input( array $over = array() ): array {
		return $over + array(
			'name'    => '  Jane   Doe ',
			'email'   => 'Jane@Example.com',
			'topic'   => 'correction',
			'page'    => '/lawyers/jane-doe/',
			'message' => "The years in practice on my profile are wrong.\nI was admitted in 2004.",
		);
	}

	public function testValidMessageIsNormalised(): void {
		$data = ContactService::validate( self::input() );
		$this->assertSame( 'Jane Doe', $data['name'] );
		$this->assertSame( 'jane@example.com', $data['email'] );
		$this->assertSame( 'correction', $data['topic'] );
		$this->assertStringContainsString( "\n", $data['message'] );
	}

	public function testEveryFieldIsChecked(): void {
		try {
			ContactService::validate(
				array(
					'name'    => 'J',
					'email'   => 'not-an-email',
					'topic'   => 'sales',
					'page'    => 'javascript:alert(1)',
					'message' => 'Too short',
				)
			);
			$this->fail( 'Expected a ContactException.' );
		} catch ( ContactException $e ) {
			$this->assertSame( 400, $e->status );
			$this->assertSame( array( 'name', 'email', 'topic', 'page', 'message' ), array_keys( $e->errors ) );
		}
	}

	public function testPageIsOptionalAndMayBeAFullUrl(): void {
		$this->assertSame( '', ContactService::validate( self::input( array( 'page' => '' ) ) )['page'] );
		$this->assertSame( 'https://lexranked.com/rankings/', ContactService::validate( self::input( array( 'page' => 'https://lexranked.com/rankings/' ) ) )['page'] );
	}
}
