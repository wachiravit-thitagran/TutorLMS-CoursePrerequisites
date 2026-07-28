<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Domain\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * How the results inside a rule group combine.
 */
enum Operator: string {

	/** Every rule must be satisfied. */
	case All = 'ALL';

	/** At least one rule must be satisfied. */
	case Any = 'ANY';

	/** A configurable minimum number of rules must be satisfied. */
	case AtLeast = 'AT_LEAST';

	/** No rule may be satisfied. */
	case None = 'NONE';

	public static function from_string( ?string $value, self $fallback = self::All ): self {
		return self::tryFrom( strtoupper( (string) $value ) ) ?? $fallback;
	}

	/**
	 * @return array<string, string> Value => translated label.
	 */
	public static function choices(): array {
		return array(
			self::All->value     => __( 'All of the following', 'tutor-learning-paths' ),
			self::Any->value     => __( 'Any one of the following', 'tutor-learning-paths' ),
			self::AtLeast->value => __( 'At least N of the following', 'tutor-learning-paths' ),
			self::None->value    => __( 'None of the following', 'tutor-learning-paths' ),
		);
	}

	/**
	 * Decide the group outcome from the number of satisfied rules.
	 *
	 * @param int $satisfied     Rules that passed.
	 * @param int $total         Rules evaluated.
	 * @param int $minimum       Minimum required, only used by AT_LEAST.
	 */
	public function is_satisfied_by( int $satisfied, int $total, int $minimum = 1 ): bool {
		if ( 0 === $total ) {
			// An empty group never blocks anybody.
			return true;
		}

		return match ( $this ) {
			self::All     => $satisfied === $total,
			self::Any     => $satisfied >= 1,
			self::AtLeast => $satisfied >= max( 1, $minimum ),
			self::None    => 0 === $satisfied,
		};
	}
}
