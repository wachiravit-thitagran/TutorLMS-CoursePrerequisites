<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Guards;

use SpaceWork\TutorLearningPaths\Domain\Access\AccessResult;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a denial into the right kind of response for the request that caused it.
 *
 * The same decision has to become a JSON error for AJAX, a WP_Error for REST,
 * and a redirect for a browser. Centralising that keeps the guards short and
 * stops one of them from accidentally responding with a 200.
 */
final class Responder {

	/**
	 * Stop the request, in whichever way suits it.
	 *
	 * This function does not return.
	 */
	public static function deny( AccessResult $result, string $event, int $user_id, int $course_id ): void {
		ActivityLog::record(
			$event,
			$user_id,
			$course_id,
			$result->reason_code,
			array(
				'context' => $result->context->value,
				'missing' => $result->missing_courses,
			)
		);

		/*
		 * REST is not handled here on purpose. Those guards short-circuit by
		 * returning a WP_Error from `rest_pre_dispatch`, which lets the REST
		 * server format the response and set the status. Exiting mid-dispatch
		 * would bypass that and produce a body no REST client can parse.
		 */
		if ( wp_doing_ajax() ) {
			wp_send_json_error( $result->to_array(), 403 );
		}

		$redirect = $result->next_action_url;

		if ( '' !== $redirect ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'tlp_locked' => $result->reason_code,
						'tlp_course' => $course_id,
					),
					$redirect
				),
				302
			);
			exit;
		}

		wp_die(
			esc_html( $result->message ),
			esc_html__( 'Course locked', 'tutor-learning-paths' ),
			array( 'response' => 403 )
		);
	}
}
