<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Frontend\CourseLock;
use SpaceWork\TutorLearningPaths\Infrastructure\Cache\AccessCache;
use SpaceWork\TutorLearningPaths\Plugin;

/**
 * An unavailable course must not offer an actionable enrolment/purchase form.
 *
 * @group frontend
 */
final class LockedEnrollmentPresentationTest extends IntegrationTestCase {

	public function test_locked_free_course_disables_enrollment_button(): void {
		$world = $this->scenarios->linear_chain( 2 );
		wp_set_current_user( $world['student'] );
		$this->register_fresh_lock();

		$html = apply_filters(
			'tutor/course/single/entry-box/free',
			'<div class="tutor-course-single-pricing">Free</div><form class="tutor-enrol-course-form"><input name="tutor_course_action"><button type="submit">Enroll Now</button></form>',
			$world['courses'][1]
		);

		$this->assertStringContainsString( 'tutor-enroll-course-button', $html );
		$this->assertStringContainsString( 'disabled="disabled"', $html );
		$this->assertStringContainsString( 'aria-disabled="true"', $html );
		$this->assertStringContainsString( 'Enrollment unavailable', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'name="tutor_course_action"', $html );
	}

	public function test_completed_prerequisite_restores_normal_enrollment_form(): void {
		$world = $this->scenarios->linear_chain( 2 );
		$this->seeder->complete( $world['student'], $world['courses'][0] );
		wp_set_current_user( $world['student'] );
		$this->register_fresh_lock();

		$html = '<form class="tutor-enrol-course-form"><button type="submit">Enroll Now</button></form>';

		$this->assertSame(
			$html,
			apply_filters( 'tutor/course/single/entry-box/free', $html, $world['courses'][1] )
		);
	}

	public function test_locked_purchasable_course_disables_purchase_button(): void {
		$world = $this->scenarios->linear_chain( 2 );
		wp_set_current_user( $world['student'] );
		$this->register_fresh_lock();

		$html = apply_filters(
			'tutor/course/single/entry-box/purchasable',
			'<form class="tutor-purchase-form"><button type="submit">Buy Now</button></form>',
			$world['courses'][1]
		);

		$this->assertStringContainsString( 'disabled="disabled"', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_advance_purchase_configuration_is_respected(): void {
		$world = $this->scenarios->linear_chain( 2 );
		$this->seeder->require_courses(
			$world['courses'][1],
			array( $world['courses'][0] ),
			array( 'visibility' => 'purchasable' )
		);
		AccessCache::flush_all();
		wp_set_current_user( $world['student'] );
		$this->register_fresh_lock();

		$html = '<form class="tutor-purchase-form"><button type="submit">Buy Now</button></form>';
		$this->assertSame(
			$html,
			apply_filters( 'tutor/course/single/entry-box/purchasable', $html, $world['courses'][1] )
		);
	}

	public function test_thai_catalog_translates_lock_message_and_requirement_count(): void {
		$path = TLP_DIR . 'languages/tutor-learning-paths-th.mo';
		$this->assertFileExists( $path );
		unload_textdomain( 'tutor-learning-paths' );
		$this->assertTrue( load_textdomain( 'tutor-learning-paths', $path ) );

		try {
			$this->assertSame(
				'คอร์สนี้ยังไม่เปิดให้เข้าเรียน กรุณาเรียนคอร์สที่กำหนดให้สำเร็จก่อน',
				__( 'This course is not open to you yet. Finish the required courses to unlock it.', 'tutor-learning-paths' )
			);
			$this->assertSame(
				'ผ่านเงื่อนไขแล้ว 0 จาก 1 รายการ',
				sprintf( __( '%1$d of %2$d requirements met', 'tutor-learning-paths' ), 0, 1 )
			);
			$this->assertSame(
				'ยังไม่สามารถลงทะเบียนได้',
				__( 'Enrollment unavailable', 'tutor-learning-paths' )
			);
		} finally {
			unload_textdomain( 'tutor-learning-paths' );
		}
	}

	private function register_fresh_lock(): void {
		remove_all_filters( 'tutor/course/single/entry-box/free' );
		remove_all_filters( 'tutor/course/single/entry-box/purchasable' );
		( new CourseLock( Plugin::instance()->gate(), Plugin::instance()->tutor() ) )->register();
	}
}
