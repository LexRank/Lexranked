<?php
/**
 * Plugin bootstrap tests.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {

	public function testRestNamespaceIsStable(): void {
		$this->assertSame( 'lexranked/v1', Plugin::REST_NAMESPACE );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function phpVersions(): array {
		return array(
			'too old'     => array( '8.1.29', false ),
			'minimum'     => array( '8.2.0', true ),
			'newer minor' => array( '8.4.1', true ),
		);
	}

	#[DataProvider( 'phpVersions' )]
	public function testPhpRequirement( string $version, bool $expected ): void {
		$this->assertSame( $expected, Plugin::meets_php_requirement( $version ) );
	}
}
