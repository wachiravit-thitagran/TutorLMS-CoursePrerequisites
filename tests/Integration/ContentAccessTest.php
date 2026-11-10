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

	public function test_lesson_ajax_is_guarded_before_tutor_for_members_and_guests(): void {
		$guard = new \SpaceWork\TutorLearningPaths\Guards\ContentAccessGuard( $this->gate(), \SpaceWork\TutorLearningPaths\Plugin::instance()->tutor() );
		$guard->register();
		$this->assertSame( 0, has_action( 'wp_ajax_tutor_render_lesson_content', array( $guard, 'guard_ajax' ) ) );
		$this->assertSame( 0, has_action( 'wp_ajax_nopriv_tutor_render_lesson_content', array( $guard, 'guard_ajax' ) ) );
	}

	/** @dataProvider ajax_viewers */
	public function test_public_lesson_ajax_denies_incomplete_prerequisite( bool $guest ): void {
		$world = $this->scenarios->gated_content();
		update_post_meta( $world['course'], '_tutor_is_public_course', 'yes' );
		wp_set_current_user( $guest ? 0 : $world['student'] );
		$_POST['lesson_id'] = $world['lesson'];
		$guard = new \SpaceWork\TutorLearningPaths\Guards\ContentAccessGuard( $this->gate(), \SpaceWork\TutorLearningPaths\Plugin::instance()->tutor() );
		$handler = static fn() => static function () { throw new \RuntimeException( 'JSON request stopped' ); };
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $handler );
		ob_start();
		try {
			$guard->guard_ajax();
			$this->fail( 'Locked AJAX content was allowed.' );
		} catch ( \RuntimeException $error ) {
			$json = json_decode( (string) ob_get_contents(), true );
			$this->assertFalse( $json['success'] );
			$this->assertSame( 'incomplete_prerequisites', $json['data']['reason_code'] );
		} finally {
			ob_end_clean();
			unset( $_POST['lesson_id'] );
			remove_filter( 'wp_die_ajax_handler', $handler );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
	}

	public static function ajax_viewers(): array {
		return array( 'learner' => array( false ), 'guest' => array( true ) );
	}

	public function test_lesson_ajax_allows_completed_prerequisite(): void {
		$world = $this->scenarios->gated_content();
		$this->seeder->complete( $world['student'], $world['prerequisite'] );
		wp_set_current_user( $world['student'] );
		$_POST['lesson_id'] = $world['lesson'];
		try {
			$guard = new \SpaceWork\TutorLearningPaths\Guards\ContentAccessGuard( $this->gate(), \SpaceWork\TutorLearningPaths\Plugin::instance()->tutor() );
			$guard->guard_ajax();
			$this->assertTrue( true );
		} finally {
			unset( $_POST['lesson_id'] );
		}
	}

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
