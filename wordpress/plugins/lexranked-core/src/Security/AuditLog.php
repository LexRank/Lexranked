<?php
/**
 * Audit log.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Security;

use LexRanked\Core\Database\Schema;

/**
 * Append-only log of administrative changes. Never stores secrets, raw
 * request bodies or IP addresses.
 */
final class AuditLog {

	private const REDACT = array( 'password', 'secret', 'token', 'key', 'authorization' );

	/**
	 * Record an action.
	 *
	 * @param string               $action      e.g. "lawyer.updated".
	 * @param string               $object_type e.g. "lr_lawyer".
	 * @param int                  $object_id   Object ID.
	 * @param array<string, mixed> $details     Extra context (redacted).
	 */
	public static function log( string $action, string $object_type = '', int $object_id = 0, array $details = array() ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom append-only table.
			$wpdb->prefix . Schema::AUDIT_LOG,
			array(
				'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => get_current_user_id(),
				'action'      => substr( $action, 0, 64 ),
				'object_type' => substr( $object_type, 0, 32 ),
				'object_id'   => $object_id,
				'details'     => wp_json_encode( self::redact( $details ) ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Remove secret-looking keys recursively.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return array<string, mixed>
	 */
	public static function redact( array $data ): array {
		foreach ( $data as $key => $value ) {
			foreach ( self::REDACT as $needle ) {
				if ( false !== stripos( (string) $key, $needle ) ) {
					$data[ $key ] = '[redacted]';
					continue 2;
				}
			}
			if ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value );
			}
		}
		return $data;
	}

	/**
	 * Most recent entries.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, object>
	 */
	public static function recent( int $limit = 20 ): array {
		global $wpdb;
		$table = $wpdb->prefix . Schema::AUDIT_LOG;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal; limit is prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, min( 100, $limit ) ) ) );
		return is_array( $rows ) ? $rows : array();
	}
}
