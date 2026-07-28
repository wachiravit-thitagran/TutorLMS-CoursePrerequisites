<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Domain\Access;

defined( 'ABSPATH' ) || exit;

/**
 * The situation an access decision is being made for.
 *
 * Every entry point maps onto exactly one context so the gate can apply the
 * right visibility policy without the caller re-implementing the rules.
 */
enum AccessContext: string {

	/** Seeing the course in an archive, search result or card. */
	case View = 'view';

	/** Adding to cart / starting a checkout. */
	case Purchase = 'purchase';

	/** Submitting an enrolment request. */
	case Enroll = 'enroll';

	/** Pressing "start learning" on an already-enrolled course. */
	case Start = 'start';

	/** Opening a lesson, topic or attachment. */
	case Content = 'content';

	/** Opening or submitting a quiz. */
	case Quiz = 'quiz';

	/** Downloading an attachment. */
	case Download = 'download';

	/** Any REST or AJAX request that is not one of the above. */
	case Api = 'api';

	/**
	 * Contexts that only affect whether something is displayed.
	 *
	 * A locked course may still be listed; it may never be entered.
	 */
	public function is_display_only(): bool {
		return self::View === $this;
	}

	/**
	 * Contexts that put the learner inside paid or protected material.
	 */
	public function is_content_entry(): bool {
		return in_array( $this, array( self::Start, self::Content, self::Quiz, self::Download ), true );
	}
}
