<?php
/**
 * Plain-text API output.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase {

	public function testTexturizedEntitiesBecomeCharacters(): void {
		$this->assertSame( 'What a Lawyer Profile’s Data Quality Score Means', Text::plain( 'What a Lawyer Profile&#8217;s Data Quality Score Means' ) );
		$this->assertSame( 'Smith & Jones “Trial” Lawyers', Text::plain( 'Smith &amp; Jones &#8220;Trial&#8221; Lawyers' ) );
		$this->assertSame( 'Plain title', Text::plain( 'Plain title' ) );
	}
}
