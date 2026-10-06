<?php
/**
 * Plugin Name:       Tutor Learning Paths
 * Plugin URI:        https://github.com/wachiravit-thitagran/TutorLMS-CoursePrerequisites
 * Description:       Learning paths and course prerequisites for Tutor LMS, with server-side enforcement of enrollment, purchase and content access.
 * Version:           1.0.0-dev
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            SpaceWork
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       tutor-learning-paths
 * Domain Path:       /languages
 *
 * This plugin is an independent implementation. It does not include, load or
 * depend on any Tutor LMS Pro code at runtime.
 *
 * @package SpaceWork\TutorLearningPaths
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'TLP_VERSION', '1.0.0-dev' );
define( 'TLP_FILE', __FILE__ );
define( 'TLP_DIR', plugin_dir_path( __FILE__ ) );
define( 'TLP_URL', plugin_dir_url( __FILE__ ) );
define( 'TLP_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader.
 *
 * Prefers the Composer autoloader when the plugin was built with `composer
 * install`. Falls back to a minimal PSR-4 loader so the plugin also runs from a
 * plain git checkout.
 */
if ( is_readable( TLP_DIR . 'vendor/autoload.php' ) ) {
	require_once TLP_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'SpaceWork\\TutorLearningPaths\\';
			if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$path     = TLP_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

require_once TLP_DIR . 'src/Api/functions.php';

register_activation_hook( __FILE__, array( \SpaceWork\TutorLearningPaths\Activation::class, 'run' ) );
register_deactivation_hook( __FILE__, array( \SpaceWork\TutorLearningPaths\Deactivation::class, 'run' ) );

/**
 * Boot the plugin.
 *
 * Booting is deferred to `plugins_loaded` so that Tutor LMS has already
 * declared itself and the compatibility check can see it.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		\SpaceWork\TutorLearningPaths\Plugin::instance()->boot();
		\SpaceWork\TutorLearningPaths\MCP::register();
	},
	20
);
