<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Guards;

use SpaceWork\TutorLearningPaths\Application\AccessGate;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\ActivityLog;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\HookMap;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks enrolment in a locked course.
 *
 * Hiding the enrol button is presentation, not protection. This guard sits on
 * the server side of every path that can create an enrolment - the form post,
 * admin-ajax, and REST - so a hand-crafted request is refused exactly like a
 * click on a hidden button would be.
 */
final class EnrollmentGuard {

	public function __construct( private readonly AccessGate $gate ) {}

	public function register(): void {
		foreach ( HookMap::before_enrol_actions() as $hook ) {
			add_action( $hook, array( $this, 'guard_action' ), 1, 1 );
		}

		foreach ( HookMap::enrolment_ajax_actions() as $action ) {
			// Priority 0: ahead of Tutor's own handler.
			add_action( 'wp_ajax_' . $action, array( $this, 'guard_ajax' ), 0 );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, 'guard_ajax' ), 0 );
		}

		add_filter( 'rest_pre_dispatch', array( $this, 'guard_rest' ), 10, 3 );

		// Last line of defence: refuse to write the enrolment record itself.
		foreach ( HookMap::enrolment_data_filters() as $filter ) {
			add_filter( (string) $filter, array( $this, 'guard_enroll_data' ), 1, 2 );
		}
	}

	/**
	 * Guard the documented pre-enrolment action.
	 *
	 * @param mixed $course_id Course being enrolled into.
	 */
	public function guard_action( $course_id ): void {
		$course_id = (int) $course_id;
		$user_id   = get_current_user_id();

		$result = $this->gate->evaluate( $user_id, $course_id, AccessContext::Enroll );

		if ( $result->allowed || $this->defers_to_pending_enrollment( $result ) ) {
			return;
		}

		Responder::deny( $result, ActivityLog::ENROLL_DENIED, $user_id, $course_id );
	}

	/**
	 * Guard admin-ajax enrolment endpoints.
	 */
	public function guard_ajax(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Tutor verifies its own nonce; this guard only reads the target course.
		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;

		if ( $course_id <= 0 ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		}

		if ( $course_id <= 0 ) {
			return;
		}

		$user_id = get_current_user_id();
		$result  = $this->gate->evaluate( $user_id, $course_id, AccessContext::Enroll );

		if ( $result->allowed || $this->defers_to_pending_enrollment( $result ) ) {
			return;
		}

		Responder::deny( $result, ActivityLog::ENROLL_DENIED, $user_id, $course_id );
	}

	/**
	 * Guard REST enrolment routes.
	 *
	 * @param mixed            $response Short-circuit response, if any.
	 * @param mixed            $server   REST server.
	 * @param \WP_REST_Request $request  Incoming request.
	 * @return mixed
	 */
	public function guard_rest( $response, $server, $request ) {
		if ( null !== $response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		if ( ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return $response;
		}

		$route   = (string) $request->get_route();
		$matched = false;

		foreach ( HookMap::enrolment_rest_fragments() as $fragment ) {
			if ( false !== strpos( $route, (string) $fragment ) ) {
				$matched = true;
				break;
			}
		}

		if ( ! $matched ) {
			return $response;
		}

		$course_id = absint( $request->get_param( 'course_id' ) );

		if ( $course_id <= 0 ) {
			return $response;
		}

		$user_id = get_current_user_id();
		$result  = $this->gate->evaluate( $user_id, $course_id, AccessContext::Enroll );

		if ( $result->allowed || $this->defers_to_pending_enrollment( $result ) ) {
			return $response;
		}

		ActivityLog::record(
			ActivityLog::ENROLL_DENIED,
			$user_id,
			$course_id,
			$result->reason_code,
			array( 'route' => $route )
		);

		return $result->to_wp_error();
	}

	/**
	 * Refuse to build the enrolment post itself.
	 *
	 * @param mixed $data      Enrolment post data.
	 * @param mixed $course_id Course ID.
	 * @return mixed
	 */
	public function guard_enroll_data( $data, $course_id = 0 ) {
		$course_id = (int) $course_id;

		if ( $course_id <= 0 && is_array( $data ) && isset( $data['post_parent'] ) ) {
			$course_id = (int) $data['post_parent'];
		}

		if ( $course_id <= 0 ) {
			return $data;
		}

		$user_id = is_array( $data ) && isset( $data['post_author'] )
			? (int) $data['post_author']
			: get_current_user_id();

		$result = $this->gate->evaluate( $user_id, $course_id, AccessContext::Enroll );

		if ( $result->allowed ) {
			return $data;
		}

		if (
			$this->defers_to_pending_enrollment( $result )
			&& is_array( $data )
			&& 'pending' === (string) ( $data['post_status'] ?? '' )
		) {
			return $data;
		}

		ActivityLog::record(
			ActivityLog::ENROLL_DENIED,
			$user_id,
			$course_id,
			$result->reason_code,
			array( 'stage' => 'enroll_data' )
		);

		// An empty payload makes Tutor abandon the insert.
		return array();
	}

	/**
	 * Advance purchase creates a pending ownership record. Content remains
	 * protected by the content/start guards until the prerequisites pass.
	 */
	private function defers_to_pending_enrollment( \SpaceWork\TutorLearningPaths\Domain\Access\AccessResult $result ): bool {
		return ! $result->allowed
			&& \SpaceWork\TutorLearningPaths\Support\Visibility::PURCHASABLE
				=== (string) ( $result->context_data['visibility'] ?? '' );
	}
}
