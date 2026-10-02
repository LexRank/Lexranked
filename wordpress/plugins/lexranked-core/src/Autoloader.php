<?php
/**
 * PSR-4 autoloader for the LexRanked\Core namespace.
 *
 * The plugin ships without a vendor/ directory in production, so it carries
 * its own tiny autoloader. Composer is used for development tooling only.
 *
 * @package LexRanked\Core
 */

declare(strict_types=1);

namespace LexRanked\Core;

/**
 * Maps LexRanked\Core\Foo\Bar to <base_dir>/Foo/Bar.php.
 */
final class Autoloader {

	public const PREFIX = 'LexRanked\\Core\\';

	/**
	 * Base directory for the namespace prefix.
	 *
	 * @var string
	 */
	private static string $base_dir = '';

	/**
	 * Register the autoloader. Safe to call more than once.
	 *
	 * @param string $base_dir Absolute path to the src/ directory.
	 */
	public static function register( string $base_dir ): void {
		self::$base_dir = rtrim( $base_dir, '/\\' ) . '/';
		spl_autoload_register( array( self::class, 'load' ), true, false );
	}

	/**
	 * Resolve a class name to a file path, or null if outside the namespace.
	 *
	 * @param string $class_name Fully-qualified class name.
	 */
	public static function resolve( string $class_name ): ?string {
		if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
			return null;
		}
		$relative = substr( $class_name, strlen( self::PREFIX ) );
		// Reject anything that is not a plain PHP identifier path.
		if ( '' === $relative || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $relative ) ) {
			return null;
		}
		return self::$base_dir . str_replace( '\\', '/', $relative ) . '.php';
	}

	/**
	 * Autoload callback.
	 *
	 * @param string $class_name Fully-qualified class name.
	 */
	public static function load( string $class_name ): void {
		$file = self::resolve( $class_name );
		if ( null !== $file && is_readable( $file ) ) {
			require_once $file;
		}
	}
}
