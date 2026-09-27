<?php
/**
 * Plugin Name:       LexRanked Core
 * Plugin URI:        https://lexranked.com
 * Description:       Core data model, ranking engine and REST API for LexRanked. Contains all LexRanked business logic; the public site is rendered by the headless Next.js frontend.
 * Version:           0.13.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            LexRanked
 * License:           Proprietary
 * Text Domain:       lexranked-core
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEXRANKED_CORE_VERSION', '0.13.0' );
define( 'LEXRANKED_CORE_FILE', __FILE__ );
define( 'LEXRANKED_CORE_DIR', __DIR__ );
define( 'LEXRANKED_CORE_MIN_PHP', '8.2' );

require_once __DIR__ . '/src/Autoloader.php';

\LexRanked\Core\Autoloader::register( __DIR__ . '/src' );

register_activation_hook( __FILE__, array( \LexRanked\Core\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \LexRanked\Core\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \LexRanked\Core\Plugin::class, 'boot' ) );
