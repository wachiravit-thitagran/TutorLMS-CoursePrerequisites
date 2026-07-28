<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin severity-aware logger.
 *
 * Writes through `error_log()` only. Durable, queryable events belong in the
 * activity log table instead - this is for developer diagnostics.
 */
final class Logger {

	private const LEVELS = array(
		'error'   => 40,
		'warning' => 30,
		'info'    => 20,
		'debug'   => 10,
	);

	/**
	 * @param string               $level   One of error|warning|info|debug.
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Extra data, JSON encoded.
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		$threshold = self::LEVELS[ (string) Settings::get( 'log_level', 'warning' ) ] ?? 30;
		$severity  = self::LEVELS[ $level ] ?? 20;

		if ( $severity < $threshold ) {
			return;
		}

		$line = sprintf( '[TLP][%s] %s', strtoupper( $level ), $message );

		if ( array() !== $context ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}

	/**
	 * @param array<string, mixed> $context Extra data.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context Extra data.
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context Extra data.
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::log( 'debug', $message, $context );
	}
}
