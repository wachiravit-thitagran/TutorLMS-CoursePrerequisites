<?php
/**
 * Public API.
 *
 * Everything outside this plugin - themes, other plugins, snippets - should go
 * through these functions rather than touching the tables. That is what lets
 * the storage change in a later release without breaking anybody.
 *
 * @package SpaceWork\TutorLearningPaths
 */

declare( strict_types = 1 );

use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessResult;
use SpaceWork\TutorLearningPaths\Infrastructure\Cache\AccessCache;
use SpaceWork\TutorLearningPaths\Plugin;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tlp_can_user_access_course' ) ) {
	/**
	 * Decide whether a user may do something with a course.
	 *
	 * @param int    $user_id   Learner. 0 for a logged-out visitor.
	 * @param int    $course_id Course.
	 * @param string $context   One of view|purchase|enroll|start|content|quiz|download|api.
	 */
	function tlp_can_user_access_course( int $user_id, int $course_id, string $context = 'view' ): AccessResult {
		$resolved = AccessContext::tryFrom( $context ) ?? AccessContext::View;

		return Plugin::instance()->gate()->evaluate( $user_id, $course_id, $resolved );
	}
}

if ( ! function_exists( 'tlp_course_is_locked' ) ) {
	/**
	 * Shorthand for "would this learner be turned away at enrolment".
	 */
	function tlp_course_is_locked( int $course_id, ?int $user_id = null ): bool {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		return ! Plugin::instance()->gate()->allows( $user_id, $course_id, AccessContext::Enroll );
	}
}

if ( ! function_exists( 'tlp_get_course_prerequisites' ) ) {
	/**
	 * The course IDs a course requires.
	 *
	 * @return int[]
	 */
	function tlp_get_course_prerequisites( int $course_id ): array {
		if ( ! tlp_can_inspect_course( $course_id ) ) {
			return array();
		}
		$sources = array();

		foreach ( Plugin::instance()->rules()->for_course( $course_id ) as $rule ) {
			if ( $rule->source_id > 0 && tlp_can_inspect_course( $rule->source_id ) ) {
				$sources[] = $rule->source_id;
			}
		}

		return array_values( array_unique( $sources ) );
	}
}

if ( ! function_exists( 'tlp_get_dependent_courses' ) ) {
	/**
	 * The courses that a course unlocks.
	 *
	 * @return int[]
	 */
	function tlp_get_dependent_courses( int $course_id ): array {
		if ( ! tlp_can_inspect_course( $course_id ) ) {
			return array();
		}
		return array_values( array_filter( Plugin::instance()->rules()->dependents_of( $course_id ), 'tlp_can_inspect_course' ) );
	}
}

if ( ! function_exists( 'tlp_flush_access_cache' ) ) {
	/**
	 * Drop cached decisions.
	 *
	 * @param int|null $user_id    Limit to one learner, or null for the whole site.
	 * @param int[]    $course_ids Courses to clear when a learner is given.
	 */
	function tlp_flush_access_cache( ?int $user_id = null, array $course_ids = array() ): void {
		if ( null === $user_id ) {
			AccessCache::flush_all();

			return;
		}

		AccessCache::flush_user( $user_id, $course_ids );
	}
}

/** Whether graph metadata for this course is visible to the caller. */
function tlp_can_inspect_course( int $course_id ): bool {
	$post = get_post( $course_id );
	if ( ! $post instanceof \WP_Post || $post->post_type !== Plugin::instance()->tutor()->course_post_type() ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) || current_user_can( 'edit_post', $course_id ) ) {
		return true;
	}
	if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $course_id ) ) {
		return false;
	}
	$config = \SpaceWork\TutorLearningPaths\Domain\Course\CourseConfig::for_course( $course_id );
	return ! \SpaceWork\TutorLearningPaths\Support\Visibility::hides_from_listing( $config->visibility )
		|| Plugin::instance()->gate()->allows( get_current_user_id(), $course_id, AccessContext::View );
}
