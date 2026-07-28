<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Guards;

use SpaceWork\TutorLearningPaths\Application\AccessGate;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\ActivityLog;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\TutorAdapterInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks direct access to the content of a locked course.
 *
 * The interesting case is not the learner who clicks through the curriculum -
 * it is the one who pastes /course/advanced/lesson/lesson-1/ into the address
 * bar, or who bookmarked it from a previous enrolment. Both land here.
 */
final class ContentAccessGuard {

	public function __construct(
		private readonly AccessGate $gate,
		private readonly TutorAdapterInterface $tutor
	) {}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'guard_request' ), 1 );
	}

	public function guard_request(): void {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$context = $this->context_for( $post->post_type );

		if ( null === $context ) {
			return;
		}

		$course_id = $this->tutor->resolve_course_id( (int) $post->ID );

		if ( $course_id <= 0 ) {
			return;
		}

		$user_id = get_current_user_id();
		$result  = $this->gate->evaluate( $user_id, $course_id, $context );

		if ( $result->allowed ) {
			return;
		}

		Responder::deny( $result, ActivityLog::ACCESS_DENIED, $user_id, $course_id );
	}

	/**
	 * Map a post type onto the access context it represents.
	 *
	 * The course page itself is intentionally excluded: a locked course may
	 * still be browsable, and the lock notice is rendered inline there instead
	 * of redirecting the learner away from the page that explains the lock.
	 */
	private function context_for( string $post_type ): ?AccessContext {
		if ( ! in_array( $post_type, $this->tutor->content_post_types(), true ) ) {
			return null;
		}

		if ( in_array( $post_type, array( 'tutor_quiz' ), true ) ) {
			return AccessContext::Quiz;
		}

		return AccessContext::Content;
	}
}
