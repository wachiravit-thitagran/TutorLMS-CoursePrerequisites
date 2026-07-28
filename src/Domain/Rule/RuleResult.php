<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Domain\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Standard return value of every rule.
 */
final class RuleResult {

	/**
	 * @param int                  $rule_id     Row ID of the evaluated rule, 0 for synthetic rules.
	 * @param string               $rule_type   Registered rule type slug.
	 * @param RuleStatus           $status      Evaluation outcome.
	 * @param string               $reason_code Stable code describing why.
	 * @param string               $message     Translated learner facing message.
	 * @param array<string, mixed> $context     Extra data (source course, required score, ...).
	 */
	public function __construct(
		public readonly int $rule_id,
		public readonly string $rule_type,
		public readonly RuleStatus $status,
		public readonly string $reason_code = '',
		public readonly string $message = '',
		public readonly array $context = array()
	) {}

	public function passed(): bool {
		return $this->status->is_satisfied();
	}

	/**
	 * Course ID this rule points at, when the rule is course based.
	 */
	public function source_course_id(): int {
		return isset( $this->context['source_id'] ) ? (int) $this->context['source_id'] : 0;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'rule_id'     => $this->rule_id,
			'rule_type'   => $this->rule_type,
			'status'      => $this->status->value,
			'reason_code' => $this->reason_code,
			'message'     => $this->message,
			'context'     => $this->context,
		);
	}
}
