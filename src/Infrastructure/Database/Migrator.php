<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Infrastructure\Database;

use SpaceWork\TutorLearningPaths\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Ordered, idempotent migrations.
 *
 * Each step is keyed by the version it brings the site up to. Steps must be
 * safe to run twice: a large site can time out mid-upgrade, and the next
 * request has to be able to pick up where it left off.
 */
final class Migrator {

	/**
	 * Run every outstanding step.
	 */
	public static function run(): void {
		$from = (string) get_option( Schema::OPTION_DB_VERSION, '0' );

		if ( version_compare( $from, Schema::DB_VERSION, '>=' ) ) {
			return;
		}

		// dbDelta is itself idempotent and handles the create-or-alter case.
		Schema::install();

		foreach ( self::steps() as $version => $callback ) {
			if ( version_compare( $from, (string) $version, '>=' ) ) {
				continue;
			}

			try {
				$callback();
				update_option( Schema::OPTION_DB_VERSION, (string) $version, false );
			} catch ( \Throwable $e ) {
				Logger::error(
					'Migration step failed.',
					array(
						'version' => (string) $version,
						'error'   => $e->getMessage(),
					)
				);

				// Stop rather than skip: later steps may assume this one ran.
				return;
			}
		}

		update_option( Schema::OPTION_DB_VERSION, Schema::DB_VERSION, false );
	}

	/**
	 * Version => migration callable, in ascending order.
	 *
	 * @return array<string, callable>
	 */
	private static function steps(): array {
		return array(
			// 1.0.0 is covered entirely by Schema::install().
			'1.0.0' => static function (): void {},
		);
	}
}
