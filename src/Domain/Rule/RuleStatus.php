<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Domain\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of evaluating one rule.
 */
enum RuleStatus: string {

	case Passed        = 'passed';
	case Failed        = 'failed';
	case Pending       = 'pending';
	case Expired       = 'expired';
	case NotApplicable = 'not_applicable';
	case Overridden    = 'overridden';

	/**
	 * Whether the rule counts towards the "satisfied" tally.
	 */
	public function is_satisfied(): bool {
		return in_array( $this, array( self::Passed, self::Overridden, self::NotApplicable ), true );
	}
}
