<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Behaviour of a course while it is locked.
 */
final class Visibility {

	/** Listed and browsable, but enrolment and content are blocked. */
	public const VISIBLE_LOCKED = 'visible_locked';

	/** Removed from archives, search, categories and learner REST responses. */
	public const HIDDEN = 'hidden';

	/** Course page and curriculum are readable; buying and enrolling are blocked. */
	public const PREVIEW = 'preview';

	/** May be bought in advance, but cannot be started until unlocked. */
	public const PURCHASABLE = 'purchasable';

	/** Neither buying nor enrolling is possible. */
	public const NOT_PURCHASABLE = 'not_purchasable';

	/**
	 * @return array<string, string> Slug => translated label.
	 */
	public static function choices(): array {
		return array(
			self::VISIBLE_LOCKED  => __( 'Visible but locked', 'tutor-learning-paths' ),
			self::HIDDEN          => __( 'Hidden', 'tutor-learning-paths' ),
			self::PREVIEW         => __( 'Preview only', 'tutor-learning-paths' ),
			self::PURCHASABLE     => __( 'Purchasable but not accessible', 'tutor-learning-paths' ),
			self::NOT_PURCHASABLE => __( 'Not purchasable', 'tutor-learning-paths' ),
		);
	}

	public static function is_valid( string $value ): bool {
		return array_key_exists( $value, self::choices() );
	}

	/**
	 * Whether a locked course in this mode should disappear from queries.
	 */
	public static function hides_from_listing( string $value ): bool {
		return self::HIDDEN === $value;
	}

	/**
	 * Whether a locked course in this mode may still be purchased.
	 */
	public static function allows_purchase( string $value ): bool {
		return self::PURCHASABLE === $value;
	}
}
