<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Guards\EnrollmentGuard;
use SpaceWork\TutorLearningPaths\Plugin;
use SpaceWork\TutorLearningPaths\Support\Visibility;

/**
 * Paid ownership must be recordable before learning access unlocks.
 *
 * @group ecommerce
 */
final class AdvancePurchaseTest extends IntegrationTestCase {

	public function test_pending_purchase_enrollment_is_preserved_for_a_purchasable_locked_course(): void {
		$world   = $this->scenarios->linear_chain( 2 );
		$student = $world['student'];
		$course  = $world['courses'][1];

		$this->seeder->require_courses(
			$course,
			array( $world['courses'][0] ),
			array( 'visibility' => Visibility::PURCHASABLE )
		);

		$data = array(
			'post_type'   => 'tutor_enrolled',
			'post_author' => $student,
			'post_parent' => $course,
			'post_status' => 'pending',
		);

		$guard = new EnrollmentGuard( Plugin::instance()->gate() );

		$this->assertSame( $data, $guard->guard_enroll_data( $data, $course ) );
	}

	public function test_completed_enrollment_is_still_refused_until_prerequisites_pass(): void {
		$world   = $this->scenarios->linear_chain( 2 );
		$student = $world['student'];
		$course  = $world['courses'][1];

		$this->seeder->require_courses(
			$course,
			array( $world['courses'][0] ),
			array( 'visibility' => Visibility::PURCHASABLE )
		);

		$data = array(
			'post_type'   => 'tutor_enrolled',
			'post_author' => $student,
			'post_parent' => $course,
			'post_status' => 'completed',
		);

		$guard = new EnrollmentGuard( Plugin::instance()->gate() );

		$this->assertSame( array(), $guard->guard_enroll_data( $data, $course ) );
	}
}
