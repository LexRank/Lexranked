<?php
/**
 * Integration tests only (never deployed): capture outgoing email in
 * /tmp/it-mail.log instead of sending it, so the test can follow the claim
 * confirmation link. Installed as an mu-plugin by scripts/wp-integration-test.sh.
 *
 * @package LexRanked\Core
 */

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, array $atts ) {
		file_put_contents( '/tmp/it-mail.log', wp_json_encode( array( 'to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message'] ) ) . "\n", FILE_APPEND ); // phpcs:ignore
		return true;
	},
	10,
	2
);
