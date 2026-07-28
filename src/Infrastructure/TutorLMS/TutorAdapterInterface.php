<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS;

defined( 'ABSPATH' ) || exit;

/**
 * The only surface through which this plugin is allowed to talk to Tutor LMS.
 *
 * Keeping every `tutor_utils()` call behind one interface means a breaking
 * change in Tutor is a one-file fix, and it makes the domain layer testable
 * without WordPress.
 */
interface TutorAdapterInterface {

	public function is_available(): bool;

	public function version(): string;

	public function course_post_type(): string;

	/**
	 * Post types that represent learning content inside a course.
	 *
	 * @return string[]
	 */
	public function content_post_types(): array;

	public function course_exists( int $course_id ): bool;

	public function course_title( int $course_id ): string;

	public function course_permalink( int $course_id ): string;

	public function is_enrolled( int $user_id, int $course_id ): bool;

	public function is_course_completed( int $user_id, int $course_id ): bool;

	/**
	 * Resolve the owning course of a lesson, quiz, assignment or topic.
	 *
	 * @return int Course ID, or 0 when the post is not course content.
	 */
	public function resolve_course_id( int $post_id ): int;

	public function is_instructor_of( int $user_id, int $course_id ): bool;

	public function is_administrator( int $user_id ): bool;

	/**
	 * Whether the official Tutor LMS Pro prerequisites add-on is active.
	 *
	 * Detected only so the site owner can be warned about two prerequisite
	 * systems running at once. No Pro code is ever called.
	 */
	public function official_prerequisites_active(): bool;

	/**
	 * Clear request-local facts after enrolment, completion, or test isolation.
	 */
	public function clear_runtime_cache(): void;
}
