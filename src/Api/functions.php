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
		$sources = array();

		foreach ( Plugin::instance()->rules()->for_course( $course_id ) as $rule ) {
			if ( $rule->source_id > 0 ) {
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
		return Plugin::instance()->rules()->dependents_of( $course_id );
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
