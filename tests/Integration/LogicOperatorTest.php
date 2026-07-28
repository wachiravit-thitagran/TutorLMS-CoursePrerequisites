<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;

/**
 * ALL, ANY, AT_LEAST and NONE against real seeded progress.
 *
 * The unit suite already proves the arithmetic. This proves the arithmetic is
 * wired to the right data.
 *
 * @group logic
 */
final class LogicOperatorTest extends IntegrationTestCase {

	public function test_any_unlocks_on_the_first_completion(): void {
		$world   = $this->scenarios->any_of( 3 );
		$student = $world['student'];

		$this->assertLocked( $student, $world['target'] );

		$this->seeder->complete( $student, $world['options'][1] );

		$this->assertUnlocked(
			$student,
			$world['target'],
			AccessContext::Enroll,
			'ANY should unlock as soon as one option is finished.'
		);
	}

	public function test_at_least_holds_until_the_minimum_is_reached(): void {
		$world   = $this->scenarios->at_least( 4, 2 );
		$student = $world['student'];

		$this->assertLocked( $student, $world['target'] );

		$this->seeder->complete( $student, $world['electives'][0] );
		$this->assertLocked( $student, $world['target'], AccessContext::Enroll, 'One of two is not enough.' );

		$this->seeder->complete( $student, $world['electives'][3] );
		$this->assertUnlocked( $student, $world['target'] );
	}

	public function test_none_locks_once_the_excluded_course_is_finished(): void {
		$world   = $this->scenarios->none_of();
		$student = $world['student'];

		$this->assertUnlocked(
			$student,
			$world['target'],
			AccessContext::Enroll,
			'NONE should be satisfied while the learner has not taken the excluded course.'
		);

		$this->seeder->complete( $student, $world['excluded'] );

		$this->assertLocked( $student, $world['target'] );
	}

	public function test_all_needs_every_prerequisite(): void {
		$sources = $this->seeder->courses( 3, 'Required' );
		$target  = $this->seeder->course( 'All target' );
		$student = $this->seeder->student();

		$this->seeder->require_courses( $target, $sources, array( 'logic' => 'ALL' ) );

		foreach ( $sources as $index => $source ) {
			$this->seeder->complete( $student, $source );

			if ( $index < count( $sources ) - 1 ) {
				$this->assertLocked( $student, $target );
			}
		}

		$this->assertUnlocked( $student, $target );
	}

	public function test_a_minimum_higher_than_the_rule_count_is_rejected(): void {
		$sources = $this->seeder->courses( 2, 'Short list' );
		$target  = $this->seeder->course( 'Impossible target' );

		$result = $this->seeder->require_courses(
			$target,
			$sources,
			array(
				'logic'        => 'AT_LEAST',
				'min_required' => 5,
			)
		);

		$this->assertWPError(
			$result,
			'A course that could never unlock must not be saveable.'
		);
		$this->assertSame( 'tlp_minimum_too_high', $result->get_error_code() );
	}
}
