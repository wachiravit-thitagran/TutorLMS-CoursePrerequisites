<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Plugin;

/**
 * Asserts the seeder and Tutor LMS agree about what the seeded data means.
 *
 * This is the canary for the whole integration suite. Every other test assumes
 * that "seeded as completed" is what Tutor considers completed. If Tutor
 * changes how it stores enrolment or completion, this file fails by name and
 * points straight at the fixtures, instead of leaving a maintainer to work out
 * why thirty unrelated assertions went red at once.
 *
 * @group contract
 */
final class SeederContractTest extends IntegrationTestCase {

	public function test_tutor_is_actually_loaded(): void {
		$this->assertTrue( function_exists( 'tutor' ), 'Tutor LMS should be loaded.' );
		$this->assertTrue( function_exists( 'tutor_utils' ), 'tutor_utils() should exist.' );
		$this->assertTrue(
			defined( 'TUTOR_VERSION' ),
			'TUTOR_VERSION should be defined; the adapter uses it for the compatibility check.'
		);
	}

	public function test_the_seeded_course_uses_tutors_post_type(): void {
		$course_id = $this->seeder->course( 'Contract course' );

		$this->assertSame(
			tutor()->course_post_type,
			get_post_type( $course_id ),
			'The seeder must create posts of the type Tutor actually registers.'
		);
	}

	public function test_the_adapter_sees_a_seeded_enrolment(): void {
		$course_id = $this->seeder->course( 'Enrolment contract' );
		$student   = $this->seeder->student();

		$this->seeder->enrol( $student, $course_id );

		$this->assertTrue(
			Plugin::instance()->tutor()->is_enrolled( $student, $course_id ),
			'The adapter should see the enrolment the seeder created.'
		);
	}

	public function test_tutor_itself_sees_a_seeded_enrolment(): void {
		$course_id = $this->seeder->course( 'Enrolment contract, Tutor side' );
		$student   = $this->seeder->student();

		$this->seeder->enrol( $student, $course_id );

		if ( ! method_exists( tutor_utils(), 'is_enrolled' ) ) {
			$this->markTestSkipped( 'This Tutor build does not expose is_enrolled().' );
		}

		$this->assertNotEmpty(
			tutor_utils()->is_enrolled( $course_id, $student ),
			'Tutor should agree that the seeded learner is enrolled. If this fails, ' .
			'Tutor changed how enrolment is stored and Seeder::enrol_directly() needs updating.'
		);
	}

	public function test_the_adapter_sees_a_seeded_completion(): void {
		$course_id = $this->seeder->course( 'Completion contract' );
		$student   = $this->seeder->student();

		$this->seeder->complete( $student, $course_id );

		$this->assertTrue(
			Plugin::instance()->tutor()->is_course_completed( $student, $course_id ),
			'The adapter should see the completion the seeder created.'
		);
	}

	public function test_tutor_itself_sees_a_seeded_completion(): void {
		$course_id = $this->seeder->course( 'Completion contract, Tutor side' );
		$student   = $this->seeder->student();

		$this->seeder->complete( $student, $course_id );

		if ( ! method_exists( tutor_utils(), 'is_completed_course' ) ) {
			$this->markTestSkipped( 'This Tutor build does not expose is_completed_course().' );
		}

		$this->assertNotEmpty(
			tutor_utils()->is_completed_course( $course_id, $student ),
			'Tutor should agree that the seeded learner completed the course. If this fails, ' .
			'Tutor changed how completion is stored and Seeder::complete_directly() needs updating.'
		);
	}

	public function test_the_adapter_resolves_a_lesson_back_to_its_course(): void {
		$course_id = $this->seeder->course( 'Resolution contract' );
		$lesson    = $this->seeder->lesson( $course_id );

		$this->assertSame(
			$course_id,
			Plugin::instance()->tutor()->resolve_course_id( $lesson['lesson'] ),
			'A lesson nested under a topic must resolve back to its course.'
		);
	}

	public function test_the_adapter_resolves_a_quiz_back_to_its_course(): void {
		$course_id = $this->seeder->course( 'Quiz resolution contract' );
		$quiz_id   = $this->seeder->quiz( $course_id );

		$this->assertSame(
			$course_id,
			Plugin::instance()->tutor()->resolve_course_id( $quiz_id )
		);
	}

	public function test_plugin_tables_exist(): void {
		global $wpdb;

		foreach ( array( 'tlp_course_rules', 'tlp_activity_log' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			$this->assertSame( $table, $found, "Table {$table} should have been created on activation." );
		}
	}
}
