<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Domain\Course;

use SpaceWork\TutorLearningPaths\Domain\Rule\Operator;
use SpaceWork\TutorLearningPaths\Support\Settings;
use SpaceWork\TutorLearningPaths\Support\Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * Per-course prerequisite configuration.
 *
 * Stored as one JSON post meta row rather than a dozen scalar rows: it is
 * always read as a unit, and a single key keeps `uninstall.php` honest.
 * The rules themselves live in a real table, because those must be queryable.
 */
final class CourseConfig {

	public const META_KEY = '_tlp_config';

	/**
	 * @param bool     $enabled      Whether prerequisites apply to this course.
	 * @param Operator $logic        How the rules combine.
	 * @param int      $min_required Minimum satisfied rules, for AT_LEAST.
	 * @param string   $visibility   Behaviour while locked, see Visibility.
	 * @param string   $locked_text  Custom learner facing message, may be empty.
	 * @param int      $redirect_to  Post ID to redirect blocked learners to, 0 for the course page.
	 * @param bool     $admin_bypass Let administrators through.
	 * @param bool     $instructor_bypass Let the course instructor through.
	 */
	public function __construct(
		public readonly bool $enabled = false,
		public readonly Operator $logic = Operator::All,
		public readonly int $min_required = 1,
		public readonly string $visibility = Visibility::VISIBLE_LOCKED,
		public readonly string $locked_text = '',
		public readonly int $redirect_to = 0,
		public readonly bool $admin_bypass = true,
		public readonly bool $instructor_bypass = true
	) {}

	/**
	 * Load a course configuration, falling back to the global defaults.
	 */
	public static function for_course( int $course_id ): self {
		$raw = get_post_meta( $course_id, self::META_KEY, true );

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$config = self::from_array( $raw );

		/**
		 * Filters the resolved configuration of one course.
		 *
		 * @param CourseConfig $config    Resolved configuration.
		 * @param int          $course_id Course ID.
		 */
		return apply_filters( 'tlp_course_config', $config, $course_id );
	}

	/**
	 * @param array<string, mixed> $data Raw values.
	 */
	public static function from_array( array $data ): self {
		$visibility = isset( $data['visibility'] ) ? (string) $data['visibility'] : '';

		if ( ! Visibility::is_valid( $visibility ) ) {
			$visibility = (string) Settings::get( 'default_visibility', Visibility::VISIBLE_LOCKED );
		}

		return new self(
			! empty( $data['enabled'] ),
			Operator::from_string( isset( $data['logic'] ) ? (string) $data['logic'] : null ),
			max( 1, isset( $data['min_required'] ) ? (int) $data['min_required'] : 1 ),
			$visibility,
			isset( $data['locked_text'] ) ? (string) $data['locked_text'] : '',
			isset( $data['redirect_to'] ) ? absint( $data['redirect_to'] ) : 0,
			array_key_exists( 'admin_bypass', $data )
				? (bool) $data['admin_bypass']
				: (bool) Settings::get( 'admin_bypass', true ),
			array_key_exists( 'instructor_bypass', $data )
				? (bool) $data['instructor_bypass']
				: (bool) Settings::get( 'instructor_bypass', true )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'enabled'           => $this->enabled,
			'logic'             => $this->logic->value,
			'min_required'      => $this->min_required,
			'visibility'        => $this->visibility,
			'locked_text'       => $this->locked_text,
			'redirect_to'       => $this->redirect_to,
			'admin_bypass'      => $this->admin_bypass,
			'instructor_bypass' => $this->instructor_bypass,
		);
	}

	/**
	 * Persist to post meta.
	 */
	public function save( int $course_id ): bool {
		$value = wp_json_encode( $this->to_array() );

		if ( get_post_meta( $course_id, self::META_KEY, true ) === $value ) {
			return true;
		}

		return false !== update_post_meta( $course_id, self::META_KEY, $value );
	}

	/**
	 * The message shown to a locked out learner.
	 */
	public function message(): string {
		if ( '' !== $this->locked_text ) {
			return $this->locked_text;
		}

		$global = (string) Settings::get( 'default_locked_text', '' );

		if ( '' !== $global ) {
			return $global;
		}

		return __( 'This course is not open to you yet. Finish the required courses to unlock it.', 'tutor-learning-paths' );
	}
}
