<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Domain\Rule;

use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\TutorAdapterInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a rule needs in order to decide, gathered once per evaluation.
 *
 * Rules never query the database directly; they ask the adapter, which memoises
 * within the request. That is what keeps the archive page free of N+1 queries.
 */
final class RuleContext {

	public function __construct(
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly AccessContext $access_context,
		public readonly TutorAdapterInterface $tutor,
		public readonly int $evaluated_at = 0
	) {}

	public function now(): int {
		return $this->evaluated_at > 0 ? $this->evaluated_at : time();
	}
}
