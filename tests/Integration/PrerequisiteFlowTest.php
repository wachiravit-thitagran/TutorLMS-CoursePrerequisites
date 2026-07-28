<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;

/**
 * The behaviour the plugin exists to provide, end to end.
 *
 * @group flow
 */
final class PrerequisiteFlowTest extends IntegrationTestCase {

	public function test_a_chain_unlocks_one_step_at_a_time(): void {
		$world = $this->scenarios->linear_chain( 3 );
		list( $first, $second, $third ) = $world['courses'];
		$student = $world['student'];

		// Nothing done yet.
		$this->assertUnlocked( $student, $first, AccessContext::Enroll, 'The first course has no prerequisites.' );
		$this->assertLocked( $student, $second );
		$this->assertLocked( $student, $third );

		$this->seeder->complete( $student, $first );

		$this->assertUnlocked( $student, $second );
		$this->assertLocked( $student, $third, AccessContext::Enroll, 'The third course still needs the second.' );

		$this->seeder->complete( $student, $second );

		$this->assertUnlocked( $student, $third );
	}

	public function test_the_denial_names_the_course_that_is_missing(): void {
		$world = $this->scenarios->linear_chain( 2 );
		list( $first, $second ) = $world['courses'];

		$this->assertMissingCourse( $world['student'], $second, $first );
	}

	public function test_merely_enrolling_in_the_prerequisite_is_not_enough(): void {
		$world = $this->scenarios->linear_chain( 2 );
		list( $first, $second ) = $world['courses'];
		$student = $world['student'];

		$this->seeder->enrol( $student, $first );

		$this->assertLocked(
			$student,
			$second,
			AccessContext::Enroll,
			'Starting a prerequisite is not finishing it.'
		);
	}

	public function test_a_diamond_requires_both_branches(): void {
		$world   = $this->scenarios->diamond();
		$student = $world['student'];

		$this->seeder->complete( $student, $world['foundation'] );

		$this->assertUnlocked( $student, $world['left'] );
		$this->assertUnlocked( $student, $world['right'] );
		$this->assertLocked( $student, $world['capstone'] );

		$this->seeder->complete( $student, $world['left'] );
		$this->assertLocked( $student, $world['capstone'], AccessContext::Enroll, 'One branch is not both.' );

		$this->seeder->complete( $student, $world['right'] );
		$this->assertUnlocked( $student, $world['capstone'] );
	}

	public function test_a_logged_out_visitor_is_locked_out(): void {
		$world = $this->scenarios->linear_chain( 2 );

		$this->assertLocked( 0, $world['courses'][1] );
	}

	public function test_an_ungated_course_is_never_touched(): void {
		$course_id = $this->seeder->course( 'Free for all' );
		$student   = $this->seeder->student();

		foreach ( AccessContext::cases() as $context ) {
			$this->assertUnlocked( $student, $course_id, $context );
		}
	}

	public function test_disabling_the_config_reopens_the_course(): void {
		$world = $this->scenarios->linear_chain( 2 );
		list( $first, $second ) = $world['courses'];
		$student = $world['student'];

		$this->assertLocked( $student, $second );

		$this->seeder->require_courses( $second, array( $first ), array( 'enabled' => false ) );

		$this->assertUnlocked(
			$student,
			$second,
			AccessContext::Enroll,
			'Turning prerequisites off should reopen the course without deleting the rules.'
		);
	}
}
