<?php
/**
 * Meta codec tests.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Schema\Field;
use LexRanked\Core\Schema\MetaCodec;
use PHPUnit\Framework\TestCase;

final class MetaCodecTest extends TestCase {

	public function testRoundTrips(): void {
		$cases = array(
			array( new Field( 'a', Field::TYPE_INT, 'A' ), 42 ),
			array( new Field( 'b', Field::TYPE_FLOAT, 'B' ), 94.21 ),
			array( new Field( 'c', Field::TYPE_STRING, 'C' ), 'Miami' ),
			array( new Field( 'd', Field::TYPE_BOOL, 'D' ), true ),
			array( new Field( 'e', Field::TYPE_STRING_LIST, 'E' ), array( 'English', 'Español' ) ),
			array(
				new Field( 'f', Field::TYPE_OBJECT_LIST, 'F', options: array( 'name', 'year' ) ),
				array(
					array(
						'name' => 'Award',
						'year' => '2020',
					),
				),
			),
		);
		foreach ( $cases as [ $field, $value ] ) {
			$this->assertSame( $value, MetaCodec::decode( $field, MetaCodec::encode( $field, $value ) ), $field->key );
		}
	}

	public function testAbsentMetaDecodesToNullOrEmpty(): void {
		$this->assertNull( MetaCodec::decode( new Field( 'a', Field::TYPE_FLOAT, 'A' ), '' ) );
		$this->assertSame( array(), MetaCodec::decode( new Field( 'b', Field::TYPE_STRING_LIST, 'B' ), '' ) );
		$this->assertFalse( MetaCodec::decode( new Field( 'c', Field::TYPE_BOOL, 'C' ), '' ) );
	}

	public function testCorruptJsonDecodesToEmptyList(): void {
		$this->assertSame( array(), MetaCodec::decode( new Field( 'a', Field::TYPE_STRING_LIST, 'A' ), '{not json' ) );
	}

	public function testFieldRejectsInvalidKeys(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Field( 'Bad-Key', Field::TYPE_STRING, 'Bad' );
	}

	public function testMetaKeysArePrefixedAndHidden(): void {
		$this->assertSame( '_lr_bar_status', ( new Field( 'bar_status', Field::TYPE_STRING, 'Bar status' ) )->meta_key() );
	}
}
