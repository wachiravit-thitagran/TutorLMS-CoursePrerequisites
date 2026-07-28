<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths;

use SpaceWork\TutorLearningPaths\Infrastructure\Cache\AccessCache;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once, on deactivation.
 *
 * Removes nothing durable. Deactivating a plugin is not a request to delete
 * data, and a site owner troubleshooting a conflict must be able to turn the
 * plugin off and back on without losing their prerequisite setup.
 */
final class Deactivation {

	public static function run(): void {
		AccessCache::flush_all();

		wp_clear_scheduled_hook( 'tlp_daily_reconciliation' );

		flush_rewrite_rules();
	}
}
