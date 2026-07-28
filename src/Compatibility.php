<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths;

use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\TutorAdapterInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Environment checks.
 *
 * The plugin never fatals when Tutor LMS is missing. It stands down, keeps its
 * data, and says so in the admin.
 */
final class Compatibility {

	public const MIN_PHP       = '8.1';
	public const MIN_WP        = '6.4';
	public const MIN_TUTOR     = '3.0.0';
	public const TESTED_TUTOR  = '4.0';

	/**
	 * @var string[]
	 */
	private array $problems = array();

	public function __construct( private readonly TutorAdapterInterface $tutor ) {}

	/**
	 * Whether the plugin may run its feature set.
	 */
	public function is_satisfied(): bool {
		return array() === $this->problems();
	}

	/**
	 * @return string[] Translated, human readable problems.
	 */
	public function problems(): array {
		if ( array() !== $this->problems ) {
			return $this->problems;
		}

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			$this->problems[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'Tutor Learning Paths needs PHP %1$s or newer. This site runs PHP %2$s.', 'tutor-learning-paths' ),
				self::MIN_PHP,
				PHP_VERSION
			);
		}

		if ( version_compare( (string) get_bloginfo( 'version' ), self::MIN_WP, '<' ) ) {
			$this->problems[] = sprintf(
				/* translators: %s: required WordPress version. */
				__( 'Tutor Learning Paths needs WordPress %s or newer.', 'tutor-learning-paths' ),
				self::MIN_WP
			);
		}

		if ( ! $this->tutor->is_available() ) {
			$this->problems[] = __( 'Tutor Learning Paths needs Tutor LMS to be installed and active. Your prerequisite data has been kept and will apply again once Tutor LMS is back.', 'tutor-learning-paths' );

			return $this->problems;
		}

		$version = $this->tutor->version();

		if ( '' !== $version && version_compare( $version, self::MIN_TUTOR, '<' ) ) {
			$this->problems[] = sprintf(
				/* translators: 1: required Tutor LMS version, 2: detected version. */
				__( 'Tutor Learning Paths needs Tutor LMS %1$s or newer. Version %2$s was detected.', 'tutor-learning-paths' ),
				self::MIN_TUTOR,
				$version
			);
		}

		return $this->problems;
	}

	/**
	 * Warnings that do not stop the plugin but the site owner should see.
	 *
	 * @return string[]
	 */
	public function warnings(): array {
		$warnings = array();

		if ( $this->tutor->official_prerequisites_active() ) {
			$warnings[] = __( 'The Tutor LMS Pro "Course Prerequisites" add-on is also active. Two prerequisite systems will both block enrolment, which is confusing to learners. Turn one of them off.', 'tutor-learning-paths' );
		}

		return $warnings;
	}
}
