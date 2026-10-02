<?php
/**
 * Status endpoint tests.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\REST\StatusController;
use PHPUnit\Framework\TestCase;

final class StatusControllerTest extends TestCase {

	public function testPayloadHasStableShape(): void {
		$payload = ( new StatusController( '1.2.3' ) )->payload();

		$this->assertSame(
			array( 'status', 'service', 'pluginVersion', 'apiVersion', 'namespace' ),
			array_keys( $payload )
		);
		$this->assertSame( 'ok', $payload['status'] );
		$this->assertSame( '1.2.3', $payload['pluginVersion'] );
		$this->assertSame( 'lexranked/v1', $payload['namespace'] );
	}

	public function testPayloadExposesNoConfigurationOrSecrets(): void {
		$json = (string) json_encode( ( new StatusController( '1.2.3' ) )->payload() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.

		foreach ( array( 'password', 'key', 'secret', 'token', 'db_', 'path' ) as $needle ) {
			$this->assertStringNotContainsStringIgnoringCase( $needle, $json );
		}
	}
}
