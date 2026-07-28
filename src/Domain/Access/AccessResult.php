<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Domain\Access;

use SpaceWork\TutorLearningPaths\Domain\Rule\RuleResult;

defined( 'ABSPATH' ) || exit;

/**
 * The single value every access decision returns.
 *
 * Immutable on purpose: callers must not be able to widen a denial after the
 * gate has produced it.
 */
final class AccessResult {

	/**
	 * @param bool                 $allowed         Whether access is granted.
	 * @param AccessStatus         $status          Machine readable status.
	 * @param AccessContext        $context         Context the decision was made for.
	 * @param string               $reason_code     Stable code for the denial reason.
	 * @param string               $message         Translated, learner facing message.
	 * @param int[]                $missing_courses Course IDs still to be completed.
	 * @param RuleResult[]         $rule_results    Per-rule detail, for debugging and admin UI.
	 * @param string               $next_action_url Where to send the learner next.
	 * @param array<string, mixed> $context_data    Free-form extra data.
	 */
	public function __construct(
		public readonly bool $allowed,
		public readonly AccessStatus $status,
		public readonly AccessContext $context,
		public readonly string $reason_code = '',
		public readonly string $message = '',
		public readonly array $missing_courses = array(),
		public readonly array $rule_results = array(),
		public readonly string $next_action_url = '',
		public readonly array $context_data = array()
	) {}

	/**
	 * Access granted.
	 */
	public static function allow( AccessContext $context, AccessStatus $status = AccessStatus::Allowed ): self {
		return new self( true, $status, $context );
	}

	/**
	 * Access denied.
	 *
	 * @param int[]                $missing_courses Course IDs still to be completed.
	 * @param RuleResult[]         $rule_results    Per-rule detail.
	 * @param array<string, mixed> $context_data    Free-form extra data.
	 */
	public static function deny(
		AccessContext $context,
		string $reason_code,
		string $message,
		array $missing_courses = array(),
		array $rule_results = array(),
		string $next_action_url = '',
		array $context_data = array(),
		AccessStatus $status = AccessStatus::Locked
	): self {
		return new self(
			false,
			$status,
			$context,
			$reason_code,
			$message,
			$missing_courses,
			$rule_results,
			$next_action_url,
			$context_data
		);
	}

	/**
	 * Serialise for REST responses, AJAX payloads and logging.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'allowed'         => $this->allowed,
			'status'          => $this->status->value,
			'context'         => $this->context->value,
			'reason_code'     => $this->reason_code,
			'message'         => $this->message,
			'missing_courses' => array_values( $this->missing_courses ),
			'next_action_url' => $this->next_action_url,
			'rules'           => array_map(
				static fn( RuleResult $result ): array => $result->to_array(),
				$this->rule_results
			),
			'data'            => $this->context_data,
		);
	}

	/**
	 * Convert a denial into a WP_Error suitable for REST and AJAX responses.
	 */
	public function to_wp_error(): \WP_Error {
		return new \WP_Error(
			'tlp_course_locked',
			'' !== $this->message
				? $this->message
				: __( 'You need to complete the required courses first.', 'tutor-learning-paths' ),
			array(
				'status'          => 403,
				'reason_code'     => $this->reason_code,
				'missing_courses' => array_values( $this->missing_courses ),
				'next_action_url' => $this->next_action_url,
			)
		);
	}
}
