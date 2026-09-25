<?php
/**
 * Field sanitization tests.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Schema\Field;
use LexRanked\Core\Schema\FieldSanitizer;
use LexRanked\Core\Schema\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FieldSanitizerTest extends TestCase {

	private static function field( string $type, array $extra = array() ): Field {
		return new Field( 'f', $type, 'Field', ...$extra );
	}

	public function testEmptyInputIsUnknownNotGuessed(): void {
		foreach ( array( Field::TYPE_STRING, Field::TYPE_INT, Field::TYPE_FLOAT, Field::TYPE_URL, Field::TYPE_ENUM, Field::TYPE_DATE ) as $type ) {
			$this->assertNull( FieldSanitizer::sanitize( self::field( $type ), '' ), $type );
			$this->assertNull( FieldSanitizer::sanitize( self::field( $type ), '   ' ), $type );
			$this->assertNull( FieldSanitizer::sanitize( self::field( $type ), null ), $type );
		}
		$this->assertNull( FieldSanitizer::sanitize( self::field( Field::TYPE_STRING_LIST ), "\n \n" ) );
	}

	public function testStringsAreStrippedAndCollapsed(): void {
		$this->assertSame( 'John Smith', FieldSanitizer::sanitize( self::field( Field::TYPE_STRING ), "  <b>John</b>\t  Smith\x07 " ) );
	}

	public function testTextKeepsParagraphs(): void {
		$this->assertSame( "a\n\nb", FieldSanitizer::sanitize( self::field( Field::TYPE_TEXT ), "a\r\n\r\n\r\n\r\nb" ) );
	}

	public function testStringMaxLength(): void {
		$this->expectException( ValidationException::class );
		FieldSanitizer::sanitize( new Field( 'f', Field::TYPE_STRING, 'F', max: 3 ), 'abcd' );
	}

	/**
	 * @return array<string, array{string, array<string, mixed>, mixed, mixed}>
	 */
	public static function validValues(): array {
		return array(
			'int'            => array( Field::TYPE_INT, array(), '42', 42 ),
			'negative int'   => array( Field::TYPE_INT, array(), '-3', -3 ),
			'float'          => array( Field::TYPE_FLOAT, array(), '4.85', 4.85 ),
			'float comma'    => array( Field::TYPE_FLOAT, array(), '4,5', 4.5 ),
			'date'           => array( Field::TYPE_DATE, array(), '2026-09-23', '2026-09-23' ),
			'datetime Z'     => array( Field::TYPE_DATETIME, array(), '2026-09-23T19:14:23Z', '2026-09-23T19:14:23Z' ),
			'datetime local' => array( Field::TYPE_DATETIME, array(), '2026-09-23T19:14', '2026-09-23T19:14:00Z' ),
			'datetime tz'    => array( Field::TYPE_DATETIME, array(), '2026-09-23T21:14:23+02:00', '2026-09-23T19:14:23Z' ),
			'url'            => array( Field::TYPE_URL, array(), 'https://example.com/a?b=c', 'https://example.com/a?b=c' ),
			'email'          => array( Field::TYPE_EMAIL, array(), 'John@Example.COM', 'john@example.com' ),
			'phone'          => array( Field::TYPE_PHONE, array(), '(305) 555-0100', '(305) 555-0100' ),
			'phone intl'     => array( Field::TYPE_PHONE, array(), '+1 305 555 0100', '+1 305 555 0100' ),
			'enum'           => array( Field::TYPE_ENUM, array( 'options' => array( 'a', 'b' ) ), 'b', 'b' ),
			'post ref'       => array( Field::TYPE_POST_REF, array(), '17', 17 ),
			'bool on'        => array( Field::TYPE_BOOL, array(), 'on', true ),
			'bool zero'      => array( Field::TYPE_BOOL, array(), '0', false ),
		);
	}

	#[DataProvider( 'validValues' )]
	public function testValidValues( string $type, array $extra, mixed $input, mixed $expected ): void {
		$this->assertSame( $expected, FieldSanitizer::sanitize( self::field( $type, $extra ), $input ) );
	}

	/**
	 * @return array<string, array{string, array<string, mixed>, mixed}>
	 */
	public static function invalidValues(): array {
		return array(
			'int text'          => array( Field::TYPE_INT, array(), '12abc' ),
			'int decimal'       => array( Field::TYPE_INT, array(), '1.5' ),
			'int below min'     => array( Field::TYPE_INT, array( 'min' => 0.0 ), '-1' ),
			'float above max'   => array( Field::TYPE_FLOAT, array( 'max' => 5.0 ), '5.01' ),
			'float text'        => array( Field::TYPE_FLOAT, array(), 'high' ),
			'date format'       => array( Field::TYPE_DATE, array(), '09/23/2026' ),
			'date impossible'   => array( Field::TYPE_DATE, array(), '2026-02-30' ),
			'datetime junk'     => array( Field::TYPE_DATETIME, array(), 'yesterday' ),
			'url no scheme'     => array( Field::TYPE_URL, array(), 'example.com' ),
			'url javascript'    => array( Field::TYPE_URL, array(), 'javascript:alert(1)' ),
			'url ftp'           => array( Field::TYPE_URL, array(), 'ftp://example.com' ),
			'email'             => array( Field::TYPE_EMAIL, array(), 'not-an-email' ),
			'phone letters'     => array( Field::TYPE_PHONE, array(), 'call me' ),
			'phone short'       => array( Field::TYPE_PHONE, array(), '12345' ),
			'enum unknown'      => array( Field::TYPE_ENUM, array( 'options' => array( 'a' ) ), 'z' ),
			'post ref zero'     => array( Field::TYPE_POST_REF, array(), '0' ),
			'scalar gets array' => array( Field::TYPE_STRING, array(), array( 'x' ) ),
		);
	}

	#[DataProvider( 'invalidValues' )]
	public function testInvalidValuesAreRejected( string $type, array $extra, mixed $input ): void {
		$this->expectException( ValidationException::class );
		FieldSanitizer::sanitize( self::field( $type, $extra ), $input );
	}

	public function testStringListFromTextareaIsTrimmedAndDeduplicated(): void {
		$this->assertSame(
			array( 'English', 'Spanish' ),
			FieldSanitizer::sanitize( self::field( Field::TYPE_STRING_LIST ), "English\n  Spanish \n\nEnglish" )
		);
	}

	public function testObjectListParsesPipeSeparatedLines(): void {
		$field = new Field( 'education', Field::TYPE_OBJECT_LIST, 'Education', options: array( 'institution', 'degree', 'year' ) );
		$this->assertSame(
			array(
				array(
					'institution' => 'Example Law School',
					'degree'      => 'J.D.',
					'year'        => '2004',
				),
				array(
					'institution' => 'Other University',
					'degree'      => null,
					'year'        => null,
				),
			),
			FieldSanitizer::sanitize( $field, "Example Law School | J.D. | 2004\nOther University\n" )
		);
	}

	public function testObjectListRejectsTooManyParts(): void {
		$this->expectException( ValidationException::class );
		FieldSanitizer::sanitize( new Field( 'awards', Field::TYPE_OBJECT_LIST, 'Awards', options: array( 'name', 'year' ) ), 'a | b | c' );
	}

	public function testListLengthIsBounded(): void {
		$this->expectException( ValidationException::class );
		FieldSanitizer::sanitize( self::field( Field::TYPE_STRING_LIST ), implode( "\n", range( 1, 51 ) ) );
	}

	public function testValidationExceptionCarriesFieldKey(): void {
		try {
			FieldSanitizer::sanitize( new Field( 'rating', Field::TYPE_FLOAT, 'Rating', max: 5.0 ), '9' );
			$this->fail( 'Expected exception' );
		} catch ( ValidationException $e ) {
			$this->assertSame( 'rating', $e->field_key );
			$this->assertSame( 'must be at most 5', $e->reason );
		}
	}
}
