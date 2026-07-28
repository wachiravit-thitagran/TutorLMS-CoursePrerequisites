<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Infrastructure\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Durable audit trail.
 *
 * Deliberately narrow: rule changes, denials, unlocks and overrides. Page views
 * are not events - logging those would bury the rows that matter.
 */
final class ActivityLog {

	public const RULES_CHANGED   = 'rules_changed';
	public const ACCESS_DENIED   = 'access_denied';
	public const ENROLL_DENIED   = 'enroll_denied';
	public const PURCHASE_DENIED = 'purchase_denied';
	public const COURSE_UNLOCKED = 'course_unlocked';
	public const OVERRIDE_ADDED  = 'override_added';

	/**
	 * @param array<string, mixed> $context Extra data, JSON encoded.
	 */
	public static function record(
		string $event,
		int $user_id = 0,
		int $course_id = 0,
		string $reason = '',
		array $context = array(),
		int $object_id = 0
	): void {
		global $wpdb;

		/**
		 * Filters whether an event is written to the audit trail.
		 *
		 * @param bool   $should_log Whether to log.
		 * @param string $event      Event slug.
		 */
		if ( ! apply_filters( 'tlp_should_log_event', true, $event ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Schema::log_table(),
			array(
				'event'      => $event,
				'user_id'    => $user_id,
				'actor_id'   => get_current_user_id(),
				'course_id'  => $course_id,
				'object_id'  => $object_id,
				'reason'     => mb_substr( $reason, 0, 191 ),
				'context'    => wp_json_encode( $context ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Recent entries, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$table = Schema::log_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				max( 1, min( 500, $limit ) ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
