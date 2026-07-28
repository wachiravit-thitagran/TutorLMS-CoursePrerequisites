<?php
/**
 * PHPUnit bootstrap for the pure-domain unit tests.
 *
 * These tests deliberately do not load WordPress. Everything under
 * src/Domain/ is written so it can be exercised without it, which is the whole
 * point of keeping the rule logic away from hooks and templates.
 *
 * @package SpaceWork\TutorLearningPaths
 */

declare( strict_types = 1 );

// The plugin files guard themselves with `defined( 'ABSPATH' ) || exit`.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'SpaceWork\\TutorLearningPaths\\';

		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$path = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// Minimal stubs for the handful of WordPress functions the domain layer calls.
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return $value;
	}
}
