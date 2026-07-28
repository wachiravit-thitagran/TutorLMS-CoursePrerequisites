<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths;

use SpaceWork\TutorLearningPaths\Infrastructure\Database\Migrator;
use SpaceWork\TutorLearningPaths\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once, on activation.
 */
final class Activation {

	public static function run(): void {
		Migrator::run();

		add_option( Settings::OPTION, Settings::defaults(), '', false );

		// Keeping data is the safe default; the site owner can change it later.
		add_option( 'tlp_uninstall_policy', 'keep', '', false );

		self::add_capabilities();

		flush_rewrite_rules();
	}

	/**
	 * Grant the plugin capabilities to the roles that should have them.
	 *
	 * Instructors get nothing by default beyond editing their own courses;
	 * overriding another person's progress is an administrator action.
	 */
	public static function add_capabilities(): void {
		$admin_caps = array(
			'manage_tlp_settings',
			'manage_tlp_paths',
			'edit_tlp_paths',
			'publish_tlp_paths',
			'delete_tlp_paths',
			'view_tlp_reports',
			'override_tlp_access',
			'recalculate_tlp_progress',
		);

		$role = get_role( 'administrator' );

		if ( $role instanceof \WP_Role ) {
			foreach ( $admin_caps as $cap ) {
				$role->add_cap( $cap );
			}
		}

		$instructor = get_role( 'tutor_instructor' );

		if ( $instructor instanceof \WP_Role ) {
			foreach ( array( 'edit_tlp_paths', 'view_tlp_reports' ) as $cap ) {
				$instructor->add_cap( $cap );
			}
		}
	}
}
