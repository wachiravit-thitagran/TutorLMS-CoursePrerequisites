<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Application\AccessGate;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Infrastructure\Cache\AccessCache;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\Schema;
use SpaceWork\TutorLearningPaths\Plugin;
use SpaceWork\TutorLearningPaths\Tests\Fixtures\Scenarios;
use SpaceWork\TutorLearningPaths\Tests\Fixtures\Seeder;

/**
 * Base class for tests that need WordPress, Tutor LMS and a database.
 */
abstract class IntegrationTestCase extends \WP_UnitTestCase {

	protected Seeder $seeder;

	protected Scenarios $scenarios;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'tutor' ) ) {
			$this->markTestSkipped( 'Tutor LMS is not loaded.' );
		}

		global $wpdb;

		// WordPress cleans its own tables between tests, but not plugin tables.
		// Course IDs are reused, so stale custom rows would make later tests
		// depend on execution order.
		foreach ( array( Schema::rules_table(), Schema::log_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "DELETE FROM {$table}" );
		}
		delete_option( 'tlp_hidden_mode_courses' );

		$this->seeder    = new Seeder();
		$this->scenarios = new Scenarios( $this->seeder );

		// Each test starts from a clean decision cache. Without this a value
		// cached in one test silently satisfies an assertion in the next.
		AccessCache::flush_all();
		wp_cache_flush();
	}

	public function tear_down(): void {
		AccessCache::flush_all();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	protected function gate(): AccessGate {
		return Plugin::instance()->gate();
	}

	/**
	 * Assert that a learner is refused.
	 */
	protected function assertLocked(
		int $user_id,
		int $course_id,
		AccessContext $context = AccessContext::Enroll,
		string $message = ''
	): void {
		$result = $this->gate()->evaluate( $user_id, $course_id, $context );

		$this->assertFalse(
			$result->allowed,
			'' !== $message
				? $message
				: sprintf(
					'Expected user %d to be locked out of course %d for context "%s", but access was granted.',
					$user_id,
					$course_id,
					$context->value
				)
		);
	}

	/**
	 * Assert that a learner is allowed through.
	 */
	protected function assertUnlocked(
		int $user_id,
		int $course_id,
		AccessContext $context = AccessContext::Enroll,
		string $message = ''
	): void {
		$result = $this->gate()->evaluate( $user_id, $course_id, $context );

		$this->assertTrue(
			$result->allowed,
			'' !== $message
				? $message
				: sprintf(
					'Expected user %d to reach course %d for context "%s", but it was refused with "%s".',
					$user_id,
					$course_id,
					$context->value,
					$result->reason_code
				)
		);
	}

	/**
	 * Assert the denial names a specific missing course.
	 */
	protected function assertMissingCourse( int $user_id, int $course_id, int $expected_missing ): void {
		$result = $this->gate()->evaluate( $user_id, $course_id, AccessContext::Enroll );

		$this->assertContains(
			$expected_missing,
			$result->missing_courses,
			sprintf( 'Expected course %d to be listed as an unmet requirement.', $expected_missing )
		);
	}

	/**
	 * Run a callable and report how many database queries it issued.
	 */
	protected function countQueries( callable $callback ): int {
		global $wpdb;

		$before = (int) $wpdb->num_queries;
		$callback();

		return (int) $wpdb->num_queries - $before;
	}
}
