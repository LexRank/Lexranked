<?php
/**
 * Per-job research log.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core\Research;

use LexRanked\Core\Database\Schema;
use LexRanked\Core\Security\AuditLog;

/**
 * Append-only log in {prefix}lr_research_log. Written by the server (lease
 * expiry, retries, conflicts) and by workers through the heartbeat endpoint.
 * Context is redacted like the audit log so secrets can never be persisted.
 */
final class ResearchLog {

	public const LEVELS = array( 'debug', 'info', 'warning', 'error' );

	public const MAX_CONTEXT_BYTES = 4000;

	/**
	 * Table name.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::JOB_LOG;
	}

	/**
	 * Normalize one log entry; null when invalid.
	 *
	 * @param array<string, mixed> $entry Raw entry {level, stage?, message, context?}.
	 * @return array{level: string, stage: string, message: string, context: string|null}|null
	 */
	public static function normalize( array $entry ): ?array {
		$level   = (string) ( $entry['level'] ?? 'info' );
		$message = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) ( $entry['message'] ?? '' ) ) );
		if ( ! in_array( $level, self::LEVELS, true ) || '' === $message ) {
			return null;
		}
		$stage   = substr( (string) preg_replace( '/[^a-z0-9_.-]/', '', strtolower( (string) ( $entry['stage'] ?? '' ) ) ), 0, 40 );
		$context = null;
		if ( isset( $entry['context'] ) && is_array( $entry['context'] ) && array() !== $entry['context'] ) {
			$json    = (string) json_encode( AuditLog::redact( $entry['context'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-independent by design.
			$context = strlen( $json ) > self::MAX_CONTEXT_BYTES ? (string) json_encode( array( 'truncated' => true ) ) : $json; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-independent by design.
		}
		return array(
			'level'   => $level,
			'stage'   => $stage,
			'message' => mb_substr( $message, 0, 500 ),
			'context' => $context,
		);
	}

	/**
	 * Append an entry.
	 *
	 * @param int                  $job_id  Job ID.
	 * @param string               $level   debug|info|warning|error.
	 * @param string               $stage   Pipeline stage.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public function add( int $job_id, string $level, string $stage, string $message, array $context = array() ): void {
		$entry = self::normalize(
			array(
				'level'   => $level,
				'stage'   => $stage,
				'message' => $message,
				'context' => $context,
			)
		);
		if ( null === $entry ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom append-only table.
		$wpdb->insert(
			$this->table(),
			array(
				'job_id'     => $job_id,
				'level'      => $entry['level'],
				'stage'      => $entry['stage'],
				'message'    => $entry['message'],
				'context'    => $entry['context'],
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Append several raw entries (from a worker). Invalid ones are skipped.
	 *
	 * @param int               $job_id  Job ID.
	 * @param array<int, mixed> $entries Entries.
	 * @return int Entries written.
	 */
	public function add_many( int $job_id, array $entries ): int {
		$written = 0;
		foreach ( array_slice( $entries, 0, 100 ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$normalized = self::normalize( $entry );
			if ( null === $normalized ) {
				continue;
			}
			$this->add( $job_id, $normalized['level'], $normalized['stage'], $normalized['message'], isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array() );
			++$written;
		}
		return $written;
	}

	/**
	 * Entries for a job, oldest first.
	 *
	 * @param int $job_id   Job ID.
	 * @param int $limit    Max rows.
	 * @param int $after_id Only entries with a larger ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_job( int $job_id, int $limit = 200, int $after_id = 0 ): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d AND id > %d ORDER BY id DESC LIMIT %d", $job_id, $after_id, $limit ), ARRAY_A );
		$rows = array_reverse( is_array( $rows ) ? $rows : array() );
		return array_map(
			static fn( array $r ): array => array(
				'id'        => (int) $r['id'],
				'level'     => (string) $r['level'],
				'stage'     => (string) $r['stage'],
				'message'   => (string) $r['message'],
				'context'   => null === $r['context'] ? null : json_decode( (string) $r['context'], true ),
				'createdAt' => str_replace( ' ', 'T', (string) $r['created_at'] ) . 'Z',
			),
			$rows
		);
	}

	/**
	 * Count entries per level for a job.
	 *
	 * @param int $job_id Job ID.
	 * @return array<string, int>
	 */
	public function counts( int $job_id ): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT level, COUNT(*) AS n FROM {$table} WHERE job_id = %d GROUP BY level", $job_id ), ARRAY_A );
		$out  = array_fill_keys( self::LEVELS, 0 );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['level'] ] = (int) $row['n'];
		}
		return $out;
	}
}
