<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Domain\Course\CourseConfig;
use SpaceWork\TutorLearningPaths\Infrastructure\Cache\AccessCache;
use SpaceWork\TutorLearningPaths\Plugin;

/**
 * Cycles, self-references and courses that no longer exist.
 *
 * @group integrity
 */
final class DependencyIntegrityTest extends IntegrationTestCase {

	public function test_a_two_course_loop_is_rejected(): void {
		$world = $this->scenarios->cycle_attempt();

		$result = $this->seeder->require_courses( $world['first'], array( $world['second'] ) );

		$this->assertWPError( $result );
		$this->assertSame( 'tlp_circular_dependency', $result->get_error_code() );
	}

	public function test_a_longer_loop_is_rejected(): void {
		$courses = $this->seeder->courses( 3, 'Loop' );

		$this->seeder->require_courses( $courses[1], array( $courses[0] ) );
		$this->seeder->require_courses( $courses[2], array( $courses[1] ) );

		$result = $this->seeder->require_courses( $courses[0], array( $courses[2] ) );

		$this->assertWPError( $result );
		$this->assertSame( 'tlp_circular_dependency', $result->get_error_code() );
	}

	public function test_a_rejected_loop_leaves_the_stored_rules_untouched(): void {
		$world = $this->scenarios->cycle_attempt();

		$this->seeder->require_courses( $world['first'], array( $world['second'] ) );

		$this->assertSame(
			array(),
			Plugin::instance()->rules()->for_course( $world['first'] ),
			'A rejected save must not write a partial rule set.'
		);
	}

	public function test_a_course_cannot_require_itself(): void {
		$course_id = $this->seeder->course( 'Self referential' );

		$result = $this->seeder->require_courses( $course_id, array( $course_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'tlp_self_dependency', $result->get_error_code() );
	}

	public function test_a_diamond_is_not_mistaken_for_a_loop(): void {
		$world = $this->scenarios->diamond();

		$this->assertFalse(
			Plugin::instance()->dependencies()->graph()->has_cycle(),
			'A diamond is a legitimate shape and must be saveable.'
		);
		$this->assertGreaterThan( 0, $world['capstone'] );
	}

	public function test_a_deleted_prerequisite_does_not_lock_a_course_forever(): void {
		$world   = $this->scenarios->broken_reference();
		$student = $world['student'];

		// The surviving requirement still applies.
		$this->assertLocked( $student, $world['target'] );

		$this->seeder->complete( $student, $world['survivor'] );

		$this->assertUnlocked(
			$student,
			$world['target'],
			AccessContext::Enroll,
			'A prerequisite that no longer exists must be skipped, not treated as unmet forever.'
		);
	}

	public function test_a_deleted_prerequisite_does_not_satisfy_an_any_group(): void {
		$doomed   = $this->seeder->course( 'Doomed option' );
		$survivor = $this->seeder->course( 'Surviving option' );
		$target   = $this->seeder->course( 'Any-of target' );
		$student  = $this->seeder->student();

		$this->seeder->require_courses( $target, array( $doomed, $survivor ), array( 'logic' => 'ANY' ) );

		wp_delete_post( $doomed, true );

		$this->assertLocked(
			$student,
			$target,
			AccessContext::Enroll,
			'A deleted option must not quietly satisfy an ANY group.'
		);
	}

	public function test_a_rule_pointing_at_a_vanished_course_is_skipped(): void {
		// The purge hook cleans up when a course is deleted while the plugin is
		// active. This covers the other case: a rule that outlived its course
		// because the plugin was inactive, or the row was removed by hand.
		$course_id = $this->seeder->course( 'Course with a stale rule' );
		$student   = $this->seeder->student();

		// Written straight to the repository, because the validated save path
		// would never produce this state - which is the point.
		( new CourseConfig( true ) )->save( $course_id );
		Plugin::instance()->rules()->replace_for_course(
			$course_id,
			array(
				array(
					'rule_type' => 'course_completed',
					'source_id' => 999999,
				),
			)
		);
		AccessCache::flush_all();

		$this->assertUnlocked(
			$student,
			$course_id,
			AccessContext::Enroll,
			'A rule whose course no longer exists must be skipped, not treated as permanently unmet.'
		);
	}

	public function test_deleting_a_course_purges_the_rules_that_reference_it(): void {
		$world = $this->scenarios->linear_chain( 2 );
		list( $first, $second ) = $world['courses'];

		$this->assertNotEmpty( Plugin::instance()->rules()->for_course( $second ) );

		wp_delete_post( $first, true );

		$this->assertSame(
			array(),
			Plugin::instance()->rules()->for_course( $second ),
			'Deleting a course should not leave dangling rules behind.'
		);
		$this->assertUnlocked( $world['student'], $second );
	}
}
