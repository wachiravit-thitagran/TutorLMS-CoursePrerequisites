<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Infrastructure\Database;

use SpaceWork\TutorLearningPaths\Domain\Rule\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes course rules.
 *
 * The only class allowed to touch the rules table.
 */
final class CourseRuleRepository {

	/**
	 * Enabled rules loaded during this request, keyed by course ID.
	 *
	 * @var array<int, Rule[]>
	 */
	private array $enabled_cache = array();

	/**
	 * Rules protecting one course, ordered for display.
	 *
	 * @return Rule[]
	 */
	public function for_course( int $course_id, bool $enabled_only = true ): array {
		global $wpdb;

		if ( $course_id <= 0 ) {
			return array();
		}

		if ( $enabled_only && array_key_exists( $course_id, $this->enabled_cache ) ) {
			return $this->enabled_cache[ $course_id ];
		}

		$table = Schema::rules_table();
		$where = $enabled_only ? 'AND enabled = 1' : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE target_course_id = %d {$where}
				 ORDER BY rule_group ASC, position ASC, id ASC",
				$course_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$rules = array_map( array( Rule::class, 'from_row' ), $rows );

		if ( $enabled_only ) {
			$this->enabled_cache[ $course_id ] = $rules;
		}

		return $rules;
	}

	/**
	 * Warm the cache for many courses at once.
	 *
	 * The course archive needs rules for every card on the page. Without this
	 * the page issues one query per course, which is the classic N+1.
	 *
	 * @param int[] $course_ids Course IDs.
	 * @return array<int, Rule[]> Course ID => rules, including empty arrays.
	 */
	public function for_courses( array $course_ids ): array {
		global $wpdb;

		$course_ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );

		if ( array() === $course_ids ) {
			return array();
		}

		$missing = array_values(
			array_filter(
				$course_ids,
				fn( int $course_id ): bool => ! array_key_exists( $course_id, $this->enabled_cache )
			)
		);

		if ( array() === $missing ) {
			return array_intersect_key( $this->enabled_cache, array_flip( $course_ids ) );
		}

		$grouped = array_fill_keys( $missing, array() );
		$table   = Schema::rules_table();
		// Integers only, cast above; safe to interpolate.
		$in = implode( ',', $missing );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT * FROM {$table}
			 WHERE target_course_id IN ({$in}) AND enabled = 1
			 ORDER BY rule_group ASC, position ASC, id ASC",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$grouped[ (int) $row['target_course_id'] ][] = Rule::from_row( $row );
		}

		foreach ( $grouped as $course_id => $rules ) {
			$this->enabled_cache[ (int) $course_id ] = $rules;
		}

		return array_intersect_key( $this->enabled_cache, array_flip( $course_ids ) );
	}

	/**
	 * Every enabled rule in the install, used to build the dependency graph.
	 *
	 * @return Rule[]
	 */
	public function all_enabled(): array {
		global $wpdb;

		$table = Schema::rules_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE enabled = 1", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( Rule::class, 'from_row' ), $rows );
	}

	/**
	 * Courses that name this course as a prerequisite.
	 *
	 * @return int[]
	 */
	public function dependents_of( int $source_course_id ): array {
		global $wpdb;

		$table = Schema::rules_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT target_course_id FROM {$table} WHERE source_id = %d AND enabled = 1",
				$source_course_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Replace the whole rule set of a course in one transaction.
	 *
	 * Replace rather than diff: the admin UI always submits the complete set,
	 * and a delete-then-insert cannot leave a half-applied rule behind.
	 *
	 * @param array<int, array<string, mixed>> $rules         Normalised rule payloads.
	 * @param callable|null                   $before_commit Optional related write; false/WP_Error rolls everything back.
	 * @return int|\WP_Error Number of rules stored, or an error after rollback.
	 */
	public function replace_for_course( int $course_id, array $rules, ?callable $before_commit = null ) {
		global $wpdb;

		if ( $course_id <= 0 ) {
			return 0;
		}

		$table = Schema::rules_table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete( $table, array( 'target_course_id' => $course_id ), array( '%d' ) );

		if ( false === $deleted ) {
			return $this->rollback_error( $wpdb, 'delete_failed' );
		}

		$position = 0;
		$stored   = 0;

		foreach ( $rules as $rule ) {
			$rule_type = isset( $rule['rule_type'] ) ? (string) $rule['rule_type'] : '';

			if ( '' === $rule_type ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$inserted = $wpdb->insert(
				$table,
				array(
					'target_course_id' => $course_id,
					'rule_type'        => $rule_type,
					'operator'         => isset( $rule['operator'] ) ? (string) $rule['operator'] : '',
					'source_id'        => isset( $rule['source_id'] ) ? absint( $rule['source_id'] ) : 0,
					'value'            => wp_json_encode( isset( $rule['value'] ) && is_array( $rule['value'] ) ? $rule['value'] : array() ),
					'rule_group'       => isset( $rule['rule_group'] ) ? absint( $rule['rule_group'] ) : 0,
					'position'         => $position++,
					'enabled'          => isset( $rule['enabled'] ) ? (int) (bool) $rule['enabled'] : 1,
					'created_at'       => $now,
					'updated_at'       => $now,
				),
				array( '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
			);

			if ( false === $inserted ) {
				return $this->rollback_error( $wpdb, 'insert_failed' );
			}

			++$stored;
		}

		if ( null !== $before_commit ) {
			$related = $before_commit();

			if ( false === $related || is_wp_error( $related ) ) {
				return $this->rollback_error( $wpdb, 'related_write_failed' );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return $this->rollback_error( $wpdb, 'commit_failed' );
		}

		unset( $this->enabled_cache[ $course_id ] );

		return $stored;
	}

	/**
	 * Remove every rule that points at, or protects, a deleted course.
	 *
	 * @return int Rows removed.
	 */
	public function purge_course( int $course_id ): int {
		global $wpdb;

		$table = Schema::rules_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE target_course_id = %d OR source_id = %d",
				$course_id,
				$course_id
			)
		);

		$this->clear_runtime_cache();

		return (int) $removed;
	}

	/**
	 * Clear request-local rule data after a global cache generation change.
	 */
	public function clear_runtime_cache(): void {
		$this->enabled_cache = array();
	}

	private function rollback_error( \wpdb $wpdb, string $stage ): \WP_Error {
		$error = '' !== $wpdb->last_error ? $wpdb->last_error : __( 'The database rejected the rule update.', 'tutor-learning-paths' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'ROLLBACK' );

		$this->clear_runtime_cache();

		return new \WP_Error(
			'tlp_rule_write_failed',
			__( 'The prerequisite rules could not be saved. The previous rules were restored.', 'tutor-learning-paths' ),
			array(
				'stage'    => $stage,
				'database' => $error,
			)
		);
	}
}
