<?php
/**
 * Autoloader tests.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Autoloader;
use LexRanked\Core\REST\StatusController;
use PHPUnit\Framework\TestCase;

final class AutoloaderTest extends TestCase {

	public function testResolvesNamespacedClassToSrcPath(): void {
		$path = Autoloader::resolve( 'LexRanked\\Core\\REST\\StatusController' );
		$this->assertNotNull( $path );
		$this->assertStringEndsWith( '/src/REST/StatusController.php', $path );
		$this->assertFileExists( $path );
	}

	public function testIgnoresForeignNamespaces(): void {
		$this->assertNull( Autoloader::resolve( 'Other\\Vendor\\Thing' ) );
		$this->assertNull( Autoloader::resolve( 'LexRanked\\CoreExtra\\Thing' ) );
	}

	public function testRejectsPathTraversal(): void {
		$this->assertNull( Autoloader::resolve( 'LexRanked\\Core\\..\\..\\etc\\passwd' ) );
		$this->assertNull( Autoloader::resolve( 'LexRanked\\Core\\' ) );
	}

	public function testLoadsClassesOnDemand(): void {
		$this->assertTrue( class_exists( StatusController::class ) );
	}
}
