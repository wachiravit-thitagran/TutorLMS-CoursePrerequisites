<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Domain\Rule\Types;

use SpaceWork\TutorLearningPaths\Domain\Rule\Rule;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleContext;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleResult;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleStatus;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleTypeInterface;

defined( 'ABSPATH' ) || exit;

/**
 * "The learner must have completed course X."
 *
 * The foundation rule of the whole plugin, and the only one release 1.0 ships
 * with alongside the enrolment variant.
 */
final class CourseCompletionRule implements RuleTypeInterface {

	public const SLUG = 'course_completed';

	public function slug(): string {
		return self::SLUG;
	}

	public function label(): string {
		return __( 'Course completed', 'tutor-learning-paths' );
	}

	public function evaluate( Rule $rule, RuleContext $context ): RuleResult {
		$source_id = $rule->source_id;

		if ( $source_id <= 0 ) {
			return $this->result( $rule, RuleStatus::NotApplicable, 'missing_source', __( 'This rule has no course assigned.', 'tutor-learning-paths' ) );
		}

		// A course that no longer exists must not lock learners out forever.
		if ( ! $context->tutor->course_exists( $source_id ) ) {
			return $this->result(
				$rule,
				RuleStatus::NotApplicable,
				'source_course_missing',
				__( 'A required course no longer exists and has been skipped.', 'tutor-learning-paths' )
			);
		}

		if ( $context->user_id <= 0 ) {
			return $this->result(
				$rule,
				RuleStatus::Failed,
				'not_logged_in',
				__( 'Sign in to check your progress.', 'tutor-learning-paths' )
			);
		}

		if ( $context->tutor->is_course_completed( $context->user_id, $source_id ) ) {
			return $this->result( $rule, RuleStatus::Passed, 'course_completed', '' );
		}

		return $this->result(
			$rule,
			RuleStatus::Failed,
			'course_not_completed',
			sprintf(
				/* translators: %s: course title. */
				__( 'Complete "%s" first.', 'tutor-learning-paths' ),
				$context->tutor->course_title( $source_id )
			)
		);
	}

	/**
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function sanitize( array $input ) {
		$source_id = isset( $input['source_id'] ) ? absint( $input['source_id'] ) : 0;

		if ( $source_id <= 0 ) {
			return new \WP_Error(
				'tlp_invalid_rule',
				__( 'Select a course for the prerequisite rule.', 'tutor-learning-paths' )
			);
		}

		return array(
			'source_id' => $source_id,
			'operator'  => '',
			'value'     => array(),
		);
	}

	/**
	 * @return int[]
	 */
	public function dependencies( Rule $rule ): array {
		return $rule->source_id > 0 ? array( $rule->source_id ) : array();
	}

	private function result( Rule $rule, RuleStatus $status, string $reason, string $message ): RuleResult {
		return new RuleResult(
			$rule->id,
			$this->slug(),
			$status,
			$reason,
			$message,
			array( 'source_id' => $rule->source_id )
		);
	}
}
