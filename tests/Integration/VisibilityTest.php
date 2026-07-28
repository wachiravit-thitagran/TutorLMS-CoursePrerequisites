<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Guards\VisibilityGuard;
use SpaceWork\TutorLearningPaths\Support\Visibility;

/**
 * How a locked course behaves in each visibility mode.
 *
 * @group visibility
 */
final class VisibilityTest extends IntegrationTestCase {

	public function test_only_hidden_mode_removes_a_course_from_listings(): void {
		$world   = $this->scenarios->mixed_visibility();
		$student = $world['student'];

		foreach ( $world['courses'] as $mode => $course_id ) {
			$expected_visible = Visibility::HIDDEN !== $mode;

			$result = $this->gate()->evaluate( $student, $course_id, AccessContext::View );

			$this->assertSame(
				$expected_visible,
				$result->allowed,
				sprintf( 'Visibility mode "%s" behaved unexpectedly in a listing.', $mode )
			);
		}
	}

	public function test_no_mode_allows_enrolment_while_locked(): void {
		$world = $this->scenarios->mixed_visibility();

		foreach ( $world['courses'] as $mode => $course_id ) {
			$this->assertLocked(
				$world['student'],
				$course_id,
				AccessContext::Enroll,
				sprintf( 'Visibility mode "%s" must never permit enrolment while locked.', $mode )
			);
		}
	}

	public function test_no_mode_allows_content_while_locked(): void {
		$world = $this->scenarios->mixed_visibility();

		foreach ( $world['courses'] as $mode => $course_id ) {
			$this->assertLocked(
				$world['student'],
				$course_id,
				AccessContext::Content,
				sprintf( 'Visibility mode "%s" must never open content while locked.', $mode )
			);
		}
	}

	public function test_only_purchasable_mode_permits_buying_ahead(): void {
		$world = $this->scenarios->mixed_visibility();

		foreach ( $world['courses'] as $mode => $course_id ) {
			$result = $this->gate()->evaluate( $world['student'], $course_id, AccessContext::Purchase );

			$this->assertSame(
				Visibility::PURCHASABLE === $mode,
				$result->allowed,
				sprintf( 'Visibility mode "%s" handled purchase incorrectly.', $mode )
			);
		}
	}

	public function test_a_hidden_course_is_excluded_from_the_archive_query(): void {
		$world   = $this->scenarios->mixed_visibility();
		$hidden  = $world['courses'][ Visibility::HIDDEN ];
		$visible = $world['courses'][ Visibility::VISIBLE_LOCKED ];

		wp_set_current_user( $world['student'] );
		VisibilityGuard::rebuild_shortlist();

		$query = new \WP_Query(
			array(
				'post_type'      => $this->seeder->course_post_type(),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertNotContains( $hidden, $query->posts, 'A hidden locked course must not appear in listings.' );
		$this->assertContains( $visible, $query->posts, 'A visible locked course should still be listed.' );
	}

	public function test_a_hidden_course_reappears_once_unlocked(): void {
		$world  = $this->scenarios->mixed_visibility();
		$hidden = $world['courses'][ Visibility::HIDDEN ];

		$this->seeder->complete( $world['student'], $world['prerequisite'] );

		wp_set_current_user( $world['student'] );
		VisibilityGuard::rebuild_shortlist();

		$query = new \WP_Query(
			array(
				'post_type'      => $this->seeder->course_post_type(),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertContains( $hidden, $query->posts );
	}

	public function test_an_administrator_still_sees_hidden_courses(): void {
		$world  = $this->scenarios->mixed_visibility();
		$hidden = $world['courses'][ Visibility::HIDDEN ];

		wp_set_current_user( $this->seeder->administrator() );
		VisibilityGuard::rebuild_shortlist();

		$query = new \WP_Query(
			array(
				'post_type'      => $this->seeder->course_post_type(),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertContains( $hidden, $query->posts, 'Administrators must be able to find hidden courses.' );
	}
}
