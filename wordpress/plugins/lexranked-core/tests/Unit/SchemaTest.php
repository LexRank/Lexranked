<?php
/**
 * Custom table schema tests.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

namespace LexRanked\Core\Tests\Unit;

use LexRanked\Core\Database\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {

	public function testReferenceDdlIsInSyncWithCode(): void {
		$file = dirname( __DIR__, 5 ) . '/database/schema/lexranked_tables.sql';
		if ( ! is_readable( $file ) ) {
			$this->markTestSkipped( 'Reference DDL is only available in the monorepo.' );
		}
		$reference = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$this->assertStringContainsString( 'Schema version: ' . Schema::VERSION, $reference );
		foreach ( Schema::statements( 'wp_', 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' ) as $sql ) {
			$this->assertStringContainsString( $sql, $reference, 'Regenerate database/schema/lexranked_tables.sql' );
		}
	}

	public function testClaimsTableCarriesFullProvenance(): void {
		$sql = Schema::statements( 'wp_', '' )['wp_lr_claims'];
		foreach ( array( 'entity_id', 'field_name', 'value', 'source_id', 'source_url', 'source_type', 'retrieved_at', 'confidence', 'verification_status' ) as $column ) {
			$this->assertMatchesRegularExpression( '/^\s+' . $column . ' /m', $sql, $column );
		}
	}
}
