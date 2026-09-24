<?php
/**
 * PHPUnit bootstrap for pure unit tests (no WordPress runtime).
 *
 * Classes under test must not call WordPress functions from the code paths
 * exercised here. WordPress integration tests are added in Phase 2.
 *
 * @package LexRanked\Core\Tests
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'LEXRANKED_CORE_VERSION', '0.0.0-test' );
define( 'LEXRANKED_CORE_FILE', dirname( __DIR__ ) . '/lexranked-core.php' );
define( 'LEXRANKED_CORE_DIR', dirname( __DIR__ ) );
define( 'LEXRANKED_CORE_MIN_PHP', '8.2' );

require_once dirname( __DIR__ ) . '/src/Autoloader.php';
\LexRanked\Core\Autoloader::register( dirname( __DIR__ ) . '/src' );
