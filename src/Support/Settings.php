<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide settings, stored as a single option.
 */
final class Settings {

	public const OPTION = 'tlp_settings';

	/**
	 * Default values. Anything not listed here is not a recognised setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'              => true,
			'default_visibility'   => Visibility::VISIBLE_LOCKED,
			'default_locked_text'  => '',
			'admin_bypass'         => true,
			'instructor_bypass'    => true,
			'block_purchase'       => true,
			'log_level'            => 'warning',
			'cache_ttl'            => 900,
			'conflict_policy'      => 'warn', // warn | disable_self.
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist a partial update. Unknown keys are discarded.
	 *
	 * @param array<string, mixed> $values Values to merge.
	 */
	public static function update( array $values ): void {
		$allowed = array_intersect_key( $values, self::defaults() );
		update_option( self::OPTION, array_merge( self::all(), $allowed ), false );
	}
}
