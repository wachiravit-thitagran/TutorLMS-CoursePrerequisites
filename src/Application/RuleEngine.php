<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Application;

use SpaceWork\TutorLearningPaths\Domain\Course\CourseConfig;
use SpaceWork\TutorLearningPaths\Domain\Rule\Operator;
use SpaceWork\TutorLearningPaths\Domain\Rule\Rule;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleContext;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleRegistry;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleResult;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates a set of rules against one learner.
 *
 * Knows nothing about WordPress hooks, HTTP or templates. That is what makes
 * the ALL / ANY / AT_LEAST behaviour cheap to unit test.
 */
final class RuleEngine {

	/**
	 * Outcome of one evaluation.
	 *
	 * @param Rule[]        $rules   Rules to evaluate.
	 * @param CourseConfig  $config  Course configuration supplying the operator.
	 * @param RuleContext   $context Learner context.
	 * @return array{passed: bool, results: RuleResult[]}
	 */
	public function evaluate( array $rules, CourseConfig $config, RuleContext $context ): array {
		$results = array();

		foreach ( $rules as $rule ) {
			$results[] = $this->evaluate_one( $rule, $context );
		}

		/**
		 * Filters the individual rule results before they are combined.
		 *
		 * @param RuleResult[] $results Results.
		 * @param RuleContext  $context Learner context.
		 */
		$results = (array) apply_filters( 'tlp_rule_results', $results, $context );

		return array(
			'passed'  => $this->combine( $results, $config->logic, $config->min_required ),
			'results' => $results,
		);
	}

	/**
	 * Evaluate a single rule, tolerating an unknown type.
	 */
	private function evaluate_one( Rule $rule, RuleContext $context ): RuleResult {
		$type = RuleRegistry::get( $rule->rule_type );

		if ( null === $type ) {
			/*
			 * An unknown type means the plugin that registered it was
			 * deactivated. Treating that as "failed" would lock learners out of
			 * courses for a reason nobody can see or fix, so it is skipped and
			 * surfaced in the admin report instead.
			 */
			return new RuleResult(
				$rule->id,
				$rule->rule_type,
				RuleStatus::NotApplicable,
				'unknown_rule_type',
				__( 'A rule type is no longer available and has been skipped.', 'tutor-learning-paths' ),
				array( 'source_id' => $rule->source_id )
			);
		}

		$result = $type->evaluate( $rule, $context );

		/**
		 * Filters one rule result.
		 *
		 * @param RuleResult  $result  Result.
		 * @param Rule        $rule    Rule that produced it.
		 * @param RuleContext $context Learner context.
		 */
		return apply_filters( 'tlp_rule_result', $result, $rule, $context );
	}

	/**
	 * Combine results according to the course operator.
	 *
	 * @param RuleResult[] $results  Results.
	 * @param Operator     $operator Combination mode.
	 * @param int          $minimum  Minimum satisfied, for AT_LEAST.
	 */
	private function combine( array $results, Operator $operator, int $minimum ): bool {
		/*
		 * Rules that are not applicable - a deleted prerequisite, a rule type
		 * from a disabled plugin - are excluded from the tally entirely rather
		 * than counted as passes. Counting them as passes would let a single
		 * deleted course satisfy an ANY group and quietly open a locked course.
		 */
		$countable = array_values(
			array_filter(
				$results,
				static fn( RuleResult $result ): bool => RuleStatus::NotApplicable !== $result->status
			)
		);

		$total = count( $countable );

		$satisfied = count(
			array_filter(
				$countable,
				static fn( RuleResult $result ): bool => $result->passed()
			)
		);

		return $operator->is_satisfied_by( $satisfied, $total, $minimum );
	}
}
