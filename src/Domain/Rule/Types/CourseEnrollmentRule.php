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
 * "The learner must be enrolled in course X."
 *
 * Weaker than completion, and useful for companion courses that run in
 * parallel rather than in sequence.
 */
final class CourseEnrollmentRule implements RuleTypeInterface {

	public const SLUG = 'course_enrolled';

	public function slug(): string {
		return self::SLUG;
	}

	public function label(): string {
		return __( 'Enrolled in course', 'tutor-learning-paths' );
	}

	public function evaluate( Rule $rule, RuleContext $context ): RuleResult {
		$source_id = $rule->source_id;

		if ( $source_id <= 0 || ! $context->tutor->course_exists( $source_id ) ) {
			return new RuleResult(
				$rule->id,
				$this->slug(),
				RuleStatus::NotApplicable,
				'source_course_missing',
				__( 'A required course no longer exists and has been skipped.', 'tutor-learning-paths' ),
				array( 'source_id' => $source_id )
			);
		}

		$enrolled = $context->user_id > 0 && $context->tutor->is_enrolled( $context->user_id, $source_id );

		return new RuleResult(
			$rule->id,
			$this->slug(),
			$enrolled ? RuleStatus::Passed : RuleStatus::Failed,
			$enrolled ? 'course_enrolled' : 'course_not_enrolled',
			$enrolled ? '' : sprintf(
				/* translators: %s: course title. */
				__( 'Enrol in "%s" first.', 'tutor-learning-paths' ),
				$context->tutor->course_title( $source_id )
			),
			array( 'source_id' => $source_id )
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
}
