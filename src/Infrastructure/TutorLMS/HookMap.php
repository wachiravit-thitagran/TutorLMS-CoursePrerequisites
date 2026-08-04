<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS;

defined( 'ABSPATH' ) || exit;

/**
 * Every Tutor LMS hook and AJAX action this plugin attaches to, in one place.
 *
 * Hook names are the part of an LMS integration most likely to move between
 * major versions. Collecting them here means a rename is a one-line fix in a
 * file a maintainer can read, instead of a hunt through the guards - and it
 * makes the "what did we verify against which Tutor build" question answerable.
 *
 * See docs/tutor-hook-matrix.md for the verification status of each entry.
 */
final class HookMap {

	/**
	 * Actions fired before Tutor creates an enrolment.
	 *
	 * Several are listed because Tutor has used more than one over its life and
	 * attaching to a hook that does not exist costs nothing.
	 *
	 * @return string[]
	 */
	public static function before_enrol_actions(): array {
		return (array) apply_filters(
			'tlp_hook_before_enrol_actions',
			array(
				'tutor_before_enrol_to_course',
				'tutor_before_enroll',
			)
		);
	}

	/**
	 * Admin-ajax actions that can create an enrolment.
	 *
	 * Guarded at priority 0 so the check runs before Tutor's own handler.
	 *
	 * @return string[]
	 */
	public static function enrolment_ajax_actions(): array {
		return (array) apply_filters(
			'tlp_hook_enrolment_ajax_actions',
			array(
				'tutor_course_enrollment',
				'tutor_enrol_course',
				'tutor_place_free_order',
			)
		);
	}

	/**
	 * REST route fragments that create an enrolment or an order.
	 *
	 * Matched as substrings against the requested route.
	 *
	 * @return string[]
	 */
	public static function enrolment_rest_fragments(): array {
		return (array) apply_filters(
			'tlp_hook_enrolment_rest_fragments',
			array(
				'/tutor/v1/enrollments',
				'/tutor/v1/course-enroll',
			)
		);
	}

	/**
	 * Actions that mark the place on a single-course page where a learner-facing
	 * notice belongs.
	 *
	 * Three names, deliberately chosen from three *different* Tutor template
	 * files, because a theme that overrides Tutor's templates overrides one or
	 * two of them, almost never all three:
	 *
	 * - `tutor_course/single/before/inner-wrap` - templates/single-course.php,
	 *   top of the main column, above the course tabs.
	 * - `tutor_course/single/before/content` - templates/single/course/
	 *   course-content.php, immediately above the course description. This is
	 *   the position the notice used to occupy.
	 * - `tutor_course/single/entry/after` - templates/single/course/
	 *   course-entry-box.php, directly under the enrol button, which is the
	 *   thing that appeared with no explanation when this went wrong.
	 *
	 * Attaching to a hook that never fires costs nothing, and `CourseLock`
	 * renders at most one notice per request however many of them fire.
	 *
	 * Why not `the_content`, which this used to rely on: Tutor's single-course
	 * template renders the course with `get_the_ID()` and never calls
	 * `the_post()` on the main query, so `in_the_loop()` is false for the whole
	 * page and a `the_content` filter guarded on it never runs. Enforcement was
	 * unaffected - the guards do that - but the explanation silently vanished.
	 * `the_content` is kept as a last-resort fallback for themes that do render
	 * the description through the main loop.
	 *
	 * @return string[]
	 */
	public static function course_page_notice_actions(): array {
		return (array) apply_filters(
			'tlp_hook_course_page_notice_actions',
			array(
				'tutor_course/single/before/inner-wrap',
				'tutor_course/single/before/content',
				'tutor_course/single/entry/after',
			)
		);
	}

	/**
	 * Actions fired when a learner completes a course.
	 *
	 * @return string[]
	 */
	public static function course_completed_actions(): array {
		return (array) apply_filters(
			'tlp_hook_course_completed_actions',
			array(
				'tutor_course_complete_after',
				'tutor_course_completed',
			)
		);
	}

	/**
	 * Action fired after the course builder has loaded, used to enqueue the
	 * custom field script only where it is needed.
	 */
	public static function course_builder_loaded_action(): string {
		return (string) apply_filters( 'tlp_hook_course_builder_loaded', 'tutor_before_course_builder_load' );
	}
}
