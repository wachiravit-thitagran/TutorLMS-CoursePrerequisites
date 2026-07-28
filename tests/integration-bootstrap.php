<?php
/**
 * PHPUnit bootstrap for the integration suite.
 *
 * Loads the WordPress test suite, then Tutor LMS, then this plugin - in that
 * order, because the plugin's compatibility check reads Tutor's constants and
 * would stand down if it booted first.
 *
 * @package SpaceWork\TutorLearningPaths
 */

declare( strict_types = 1 );

$tlp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $tlp_tests_dir ) {
	$tlp_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $tlp_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test suite at {$tlp_tests_dir}.\n" .
		"Run: bin/install-wp-tests.sh wordpress_test root '' localhost latest\n"
	);
	exit( 1 );
}

require_once $tlp_tests_dir . '/includes/functions.php';

/**
 * Load the plugins under test before WordPress finishes booting.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$core_dir = getenv( 'WP_CORE_DIR' );

		if ( ! $core_dir ) {
			$core_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress';
		}

		$tutor = $core_dir . '/wp-content/plugins/tutor/tutor.php';

		if ( ! file_exists( $tutor ) ) {
			fwrite(
				STDERR,
				"Tutor LMS is not installed at {$tutor}.\n" .
				"Run: bin/install-tutor.sh\n"
			);
			exit( 1 );
		}

		require_once $tutor;
		require_once dirname( __DIR__ ) . '/tutor-learning-paths.php';
	}
);

/**
 * Install Tutor's tables once the test database exists.
 *
 * The WordPress test suite runs activation for nothing, so anything a plugin
 * normally does in `register_activation_hook` has to be triggered by hand.
 */
tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( function_exists( 'tutor_utils' ) && class_exists( '\TUTOR\Tutor' ) ) {
			// Tutor creates its own tables on activation.
			do_action( 'activate_tutor/tutor.php' );
		}

		\SpaceWork\TutorLearningPaths\Activation::run();
	}
);

require $tlp_tests_dir . '/includes/bootstrap.php';

// Test-only helpers, loaded after WordPress so they can extend WP_UnitTestCase.
require_once __DIR__ . '/Fixtures/Seeder.php';
require_once __DIR__ . '/Fixtures/Scenarios.php';
require_once __DIR__ . '/Integration/IntegrationTestCase.php';
