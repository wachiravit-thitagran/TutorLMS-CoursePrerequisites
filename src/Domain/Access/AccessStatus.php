<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Domain\Access;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of an access evaluation.
 */
enum AccessStatus: string {

	/** No rule applies, or every rule passed. */
	case Allowed = 'allowed';

	/** At least one rule failed and can still be satisfied. */
	case Locked = 'locked';

	/** Previously satisfied, but the result has aged out. */
	case Expired = 'expired';

	/** Waiting on a human decision. */
	case Pending = 'pending';

	/** Explicitly granted by a site administrator. */
	case Overridden = 'overridden';

	/** Granted because the user is an admin or the course instructor. */
	case Bypassed = 'bypassed';

	public function grants_access(): bool {
		return in_array( $this, array( self::Allowed, self::Overridden, self::Bypassed ), true );
	}
}
