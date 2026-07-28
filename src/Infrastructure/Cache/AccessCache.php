<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Infrastructure\Cache;

use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cache for access decisions.
 *
 * Uses the object cache group `tlp_access`, so Redis and Memcached back it
 * automatically on sites that have a drop-in, and it degrades to the
 * non-persistent in-memory cache on sites that do not. No per-user transients:
 * those pile up in the options table and nothing ever cleans them.
 *
 * Invalidation is by generation counter rather than by key enumeration - you
 * cannot enumerate keys in a distributed object cache. Bumping the counter
 * orphans the old entries and they expire on their own.
 */
final class AccessCache {

	public const GROUP = 'tlp_access';

	private const RULES_VERSION_OPTION = 'tlp_rules_version';

	/**
	 * Build the cache key for one decision.
	 */
	public static function key( int $user_id, int $course_id, string $context ): string {
		return sprintf(
			'access:%d:%d:%s:%s',
			$user_id,
			$course_id,
			$context,
			self::rules_version()
		);
	}

	/**
	 * @return mixed|false
	 */
	public static function get( string $key ) {
		if ( (int) Settings::get( 'cache_ttl', 900 ) <= 0 ) {
			return false;
		}

		return wp_cache_get( $key, self::GROUP );
	}

	/**
	 * @param mixed $value Value to store.
	 */
	public static function set( string $key, $value ): void {
		$ttl = (int) Settings::get( 'cache_ttl', 900 );

		if ( $ttl <= 0 ) {
			return;
		}

		wp_cache_set( $key, $value, self::GROUP, $ttl );
	}

	public static function delete( string $key ): void {
		wp_cache_delete( $key, self::GROUP );
	}

	/**
	 * Current generation of the rule set.
	 */
	public static function rules_version(): string {
		$version = get_option( self::RULES_VERSION_OPTION, '' );

		if ( ! is_string( $version ) || '' === $version ) {
			$version = (string) time();
			update_option( self::RULES_VERSION_OPTION, $version, true );
		}

		return $version;
	}

	/**
	 * Invalidate every cached decision on the site.
	 *
	 * Called when rules change. Coarse on purpose: a rule change can affect any
	 * learner, and a wrong "allowed" is far more expensive than a cache miss.
	 */
	public static function flush_all(): void {
		update_option( self::RULES_VERSION_OPTION, (string) time() . wp_rand( 100, 999 ), true );

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
		}

		/**
		 * Fires after every access decision has been invalidated.
		 */
		do_action( 'tlp_access_cache_flushed' );
	}

	/**
	 * Invalidate the decisions of one learner.
	 *
	 * There is no key enumeration, so this is a targeted delete for the
	 * contexts we know about rather than a wildcard.
	 *
	 * @param int[] $course_ids Courses to clear, or empty for none.
	 */
	public static function flush_user( int $user_id, array $course_ids = array() ): void {
		foreach ( $course_ids as $course_id ) {
			// Driven off the enum so a new context cannot be added without its
			// cache entries also being cleared.
			foreach ( AccessContext::cases() as $context ) {
				self::delete( self::key( $user_id, (int) $course_id, $context->value ) );
			}
		}

		/**
		 * Fires after one learner's decisions have been invalidated.
		 *
		 * @param int   $user_id    Learner.
		 * @param int[] $course_ids Courses cleared.
		 */
		do_action( 'tlp_user_access_cache_flushed', $user_id, $course_ids );
	}
}
