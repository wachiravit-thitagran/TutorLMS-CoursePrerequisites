<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Application\AccessGate;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Guards\EnrollmentGuard;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\Schema;
use SpaceWork\TutorLearningPaths\Plugin;

/**
 * Server-side refusal of enrolment.
 *
 * These are the tests that matter most. A learner who reads the page source
 * and posts the request by hand must get the same answer as one who clicks a
 * button that is not there.
 *
 * @group guards
 */
final class EnrollmentGuardTest extends IntegrationTestCase {

	public function test_the_rest_guard_refuses_a_locked_course(): void {
		$world = $this->scenarios->linear_chain( 2 );
		list( , $second ) = $world['courses'];
		$student = $world['student'];

		wp_set_current_user( $student );

		$guard   = new EnrollmentGuard( Plugin::instance()->gate() );
		$request = new \WP_REST_Request( 'POST', '/tutor/v1/enrollments' );
		$request->set_param( 'course_id', $second );

		$response = $guard->guard_rest( null, null, $request );

		$this->assertWPError( $response, 'A hand-crafted REST enrolment must be refused.' );
		$this->assertSame( 'tlp_course_locked', $response->get_error_code() );

		$data = $response->get_error_data();
		$this->assertSame( 403, $data['status'] );
		$this->assertContains( $world['courses'][0], $data['missing_courses'] );
	}

	public function test_the_rest_guard_lets_an_unlocked_course_through(): void {
		$world = $this->scenarios->linear_chain( 2 );
		list( $first, $second ) = $world['courses'];
		$student = $world['student'];

		$this->seeder->complete( $student, $first );
		wp_set_current_user( $student );

		$guard   = new EnrollmentGuard( Plugin::instance()->gate() );
		$request = new \WP_REST_Request( 'POST', '/tutor/v1/enrollments' );
		$request->set_param( 'course_id', $second );

		$this->assertNull(
			$guard->guard_rest( null, null, $request ),
			'The guard must not interfere once the prerequisites are met.'
		);
	}

	public function test_the_rest_guard_ignores_unrelated_routes(): void {
		$world = $this->scenarios->linear_chain( 2 );

		wp_set_current_user( $world['student'] );

		$guard   = new EnrollmentGuard( Plugin::instance()->gate() );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_param( 'course_id', $world['courses'][1] );

		$this->assertNull( $guard->guard_rest( null, null, $request ) );
	}

	public function test_the_rest_guard_ignores_read_requests(): void {
		$world = $this->scenarios->linear_chain( 2 );

		wp_set_current_user( $world['student'] );

		$guard   = new EnrollmentGuard( Plugin::instance()->gate() );
		$request = new \WP_REST_Request( 'GET', '/tutor/v1/enrollments' );
		$request->set_param( 'course_id', $world['courses'][1] );

		$this->assertNull( $guard->guard_rest( null, null, $request ) );
	}

	public function test_the_enrol_data_filter_empties_a_forbidden_payload(): void {
		$world = $this->scenarios->linear_chain( 2 );
		list( , $second ) = $world['courses'];
		$student = $world['student'];

		$guard = new EnrollmentGuard( Plugin::instance()->gate() );

		$payload = array(
			'post_type'   => 'tutor_enrolled',
			'post_author' => $student,
			'post_parent' => $second,
			'post_status' => 'completed',
		);

		$this->assertSame(
			array(),
			$guard->guard_enroll_data( $payload, $second ),
			'The last line of defence must refuse to build the enrolment record.'
		);
	}

	public function test_the_enrol_data_filter_passes_a_permitted_payload_untouched(): void {
		$world = $this->scenarios->linear_chain( 2 );
		list( $first, $second ) = $world['courses'];
		$student = $world['student'];

		$this->seeder->complete( $student, $first );

		$guard = new EnrollmentGuard( Plugin::instance()->gate() );

		$payload = array(
			'post_type'   => 'tutor_enrolled',
			'post_author' => $student,
			'post_parent' => $second,
			'post_status' => 'completed',
		);

		$this->assertSame( $payload, $guard->guard_enroll_data( $payload, $second ) );
	}

	public function test_a_refusal_is_written_to_the_audit_log(): void {
		global $wpdb;

		$world = $this->scenarios->linear_chain( 2 );
		$student = $world['student'];

		wp_set_current_user( $student );

		$guard   = new EnrollmentGuard( Plugin::instance()->gate() );
		$request = new \WP_REST_Request( 'POST', '/tutor/v1/enrollments' );
		$request->set_param( 'course_id', $world['courses'][1] );

		$guard->guard_rest( null, null, $request );

		$table = Schema::log_table();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event = %s AND course_id = %d",
				'enroll_denied',
				$world['courses'][1]
			)
		);

		$this->assertSame( 1, $count, 'A denied enrolment should leave exactly one audit row.' );
	}

	public function test_an_administrator_is_never_locked_out(): void {
		$world = $this->scenarios->linear_chain( 2 );
		$admin = $this->seeder->administrator();

		$this->assertUnlocked( $admin, $world['courses'][1] );
	}

	public function test_the_owning_instructor_is_never_locked_out(): void {
		$world = $this->scenarios->instructor_owned();

		$this->assertUnlocked(
			$world['instructor'],
			$world['course'],
			AccessContext::Enroll,
			'The author of a course should be able to enter it.'
		);
	}

	public function test_another_instructor_gets_no_special_treatment(): void {
		$world = $this->scenarios->instructor_owned();

		$this->assertLocked(
			$world['other_instructor'],
			$world['course'],
			AccessContext::Enroll,
			'Being an instructor somewhere else must not unlock this course.'
		);
	}

	public function test_instructor_bypass_can_be_switched_off(): void {
		$world = $this->scenarios->instructor_owned();

		$this->seeder->require_courses(
			$world['course'],
			array( $world['prerequisite'] ),
			array( 'instructor_bypass' => false )
		);

		$this->assertLocked( $world['instructor'], $world['course'] );
	}
}
