<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Template loader with theme override support.
 *
 * A theme may replace any template by dropping a file of the same name into
 * `your-theme/tutor-learning-paths/`. Child themes win over parent themes,
 * which win over the plugin.
 */
final class Templates {

	public const THEME_DIR = 'tutor-learning-paths';

	/**
	 * Render a template and return the markup.
	 *
	 * @param string               $relative Path below templates/, e.g. 'course/locked-notice.php'.
	 * @param array<string, mixed> $vars     Variables extracted into the template scope.
	 */
	public static function render( string $relative, array $vars = array() ): string {
		$path = self::locate( $relative );

		if ( '' === $path ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template scope, keys are ours.
		extract( $vars, EXTR_SKIP );

		ob_start();
		include $path;

		return (string) ob_get_clean();
	}

	/**
	 * Resolve a template path, honouring theme overrides.
	 */
	public static function locate( string $relative ): string {
		$relative = ltrim( str_replace( array( '..', "\0" ), '', $relative ), '/' );

		$candidates = array(
			trailingslashit( get_stylesheet_directory() ) . self::THEME_DIR . '/' . $relative,
			trailingslashit( get_template_directory() ) . self::THEME_DIR . '/' . $relative,
			TLP_DIR . 'templates/' . $relative,
		);

		/**
		 * Filters the candidate paths for a template.
		 *
		 * @param string[] $candidates Absolute paths, most specific first.
		 * @param string   $relative   Template path below templates/.
		 */
		$candidates = (array) apply_filters( 'tlp_template_candidates', $candidates, $relative );

		foreach ( $candidates as $candidate ) {
			if ( is_readable( $candidate ) ) {
				return (string) $candidate;
			}
		}

		return '';
	}
}
