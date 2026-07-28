<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Domain\Rule;

use SpaceWork\TutorLearningPaths\Domain\Rule\Types\CourseCompletionRule;
use SpaceWork\TutorLearningPaths\Domain\Rule\Types\CourseEnrollmentRule;
use SpaceWork\TutorLearningPaths\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of available rule types.
 *
 * Third party code extends the engine here rather than by patching it:
 *
 *     add_filter( 'tlp_registered_rule_types', function ( array $types ) {
 *         $types['onsite_training'] = new My_Onsite_Training_Rule();
 *         return $types;
 *     } );
 */
final class RuleRegistry {

	/**
	 * @var array<string, RuleTypeInterface>|null
	 */
	private static ?array $types = null;

	/**
	 * @return array<string, RuleTypeInterface>
	 */
	public static function all(): array {
		if ( null !== self::$types ) {
			return self::$types;
		}

		$built_in = array();

		foreach ( array( new CourseCompletionRule(), new CourseEnrollmentRule() ) as $type ) {
			$built_in[ $type->slug() ] = $type;
		}

		/**
		 * Filters the registered rule types.
		 *
		 * @param array<string, RuleTypeInterface> $built_in Slug => instance.
		 */
		$filtered = apply_filters( 'tlp_registered_rule_types', $built_in );

		$valid = array();

		foreach ( (array) $filtered as $slug => $type ) {
			if ( ! $type instanceof RuleTypeInterface ) {
				Logger::warning( 'Ignored an invalid rule type registration.', array( 'slug' => (string) $slug ) );
				continue;
			}
			$valid[ $type->slug() ] = $type;
		}

		self::$types = $valid;

		return self::$types;
	}

	public static function get( string $slug ): ?RuleTypeInterface {
		return self::all()[ $slug ] ?? null;
	}

	public static function has( string $slug ): bool {
		return null !== self::get( $slug );
	}

	/**
	 * Drop the memoised list. Used by tests and after a plugin toggles.
	 */
	public static function flush(): void {
		self::$types = null;
	}
}
