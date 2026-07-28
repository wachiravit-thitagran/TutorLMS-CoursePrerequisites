<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;

/**
 * Direct URLs to lessons, quizzes and assignments.
 *
 * @group guards
 */
final class ContentAccessTest extends IntegrationTestCase {

	public function test_a_lesson_of_a_locked_course_is_refused(): void {
		$world = $this->scenarios->gated_content();

		$this->assertLocked(
			$world['student'],
			$world['course'],
			AccessContext::Content,
			'Opening a lesson URL directly must be refused.'
		);
	}

	public function test_a_quiz_of_a_locked_course_is_refused(): void {
		$world = $this->scenarios->gated_content();

		$this->assertLocked( $world['student'], $world['course'], AccessContext::Quiz );
	}

	public function test_content_opens_once_the_prerequisite_is_met(): void {
		$world = $this->scenarios->gated_content();

		$this->seeder->complete( $world['student'], $world['prerequisite'] );

		$this->assertUnlocked( $world['student'], $world['course'], AccessContext::Content );
		$this->assertUnlocked( $world['student'], $world['course'], AccessContext::Quiz );
	}

	public function test_an_existing_enrolment_survives_a_new_prerequisite(): void {
		$course_id = $this->seeder->course( 'Course in progress' );
		$later     = $this->seeder->course( 'Prerequisite added later' );
		$student   = $this->seeder->student();

		// The learner legitimately joined while the course was open.
		$this->seeder->enrol( $student, $course_id );

		// The instructor then adds a requirement.
		$this->seeder->require_courses( $course_id, array( $later ) );

		$this->assertUnlocked(
			$student,
			$course_id,
			AccessContext::Content,
			'A learner mid-course must not lose access to content they already started.'
		);

		$this->assertLocked(
			$student,
			$course_id,
			AccessContext::Enroll,
			'New enrolment is still governed by the new rule.'
		);
	}

	public function test_grandfathering_can_be_switched_off(): void {
		$course_id = $this->seeder->course( 'Strict course' );
		$later     = $this->seeder->course( 'Strict prerequisite' );
		$student   = $this->seeder->student();

		$this->seeder->enrol( $student, $course_id );
		$this->seeder->require_courses( $course_id, array( $later ) );

		add_filter( 'tlp_grandfather_existing_enrolment', '__return_false' );

		$this->assertLocked( $student, $course_id, AccessContext::Content );

		remove_filter( 'tlp_grandfather_existing_enrolment', '__return_false' );
	}

	public function test_the_lesson_resolves_to_the_right_course(): void {
		$first  = $this->scenarios->gated_content();
		$second = $this->scenarios->gated_content();

		$this->assertNotSame( $first['course'], $second['course'] );
		$this->assertNotSame( $first['lesson'], $second['lesson'] );

		$adapter = \SpaceWork\TutorLearningPaths\Plugin::instance()->tutor();

		$this->assertSame( $first['course'], $adapter->resolve_course_id( $first['lesson'] ) );
		$this->assertSame( $second['course'], $adapter->resolve_course_id( $second['lesson'] ) );
	}
}
