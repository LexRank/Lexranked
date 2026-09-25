<?php
/**
 * Uninstall handler.
 *
 * Deleting the plugin removes only its settings and schema version. Content
 * (lawyers, firms, rankings, evidence, audit log) is deliberately kept so
 * that removing the plugin can never silently destroy research data; drop
 * it manually if that is really intended (see README).
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'lexranked_settings' );
delete_option( 'lexranked_db_version' );
remove_role( 'lexranked_api' );
