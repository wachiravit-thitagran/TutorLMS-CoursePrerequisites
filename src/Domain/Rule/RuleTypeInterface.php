<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Domain\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Contract every rule type implements.
 *
 * Rule types are stateless. All learner data arrives through the context, which
 * keeps them trivially unit-testable and safe to cache around.
 */
interface RuleTypeInterface {

	/**
	 * Unique slug, stored in the `rule_type` column.
	 */
	public function slug(): string;

	/**
	 * Human readable label for the admin UI.
	 */
	public function label(): string;

	/**
	 * Evaluate the rule for one learner.
	 */
	public function evaluate( Rule $rule, RuleContext $context ): RuleResult;

	/**
	 * Validate and normalise admin input before it is stored.
	 *
	 * @param array<string, mixed> $input Raw, untrusted input.
	 * @return array<string, mixed>|\WP_Error Normalised payload, or an error.
	 */
	public function sanitize( array $input );

	/**
	 * Course IDs this rule depends on, used by cycle detection.
	 *
	 * @return int[]
	 */
	public function dependencies( Rule $rule ): array;
}
