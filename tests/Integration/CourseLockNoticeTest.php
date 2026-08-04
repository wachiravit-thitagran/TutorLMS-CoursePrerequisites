<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Frontend\CourseLock;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\HookMap;
use SpaceWork\TutorLearningPaths\Plugin;

/**
 * The locked course page has to explain itself.
 *
 * A hook existing is not the same as a notice appearing, which is the lesson
 * this file exists to keep. The notice used to ride on `the_content`, guarded by
 * `in_the_loop()`. Tutor's single-course template renders the course without
 * ever calling `the_post()`, so that guard was never satisfied and a locked
 * course showed the ordinary enrol button with nothing next to it. Enforcement
 * was fine the whole time; only the explanation was missing, which is the worst
 * kind of broken because nothing errors.
 *
 * @group frontend
 */
final class CourseLockNoticeTest extends IntegrationTestCase {

	/**
	 * The outermost element of templates/course/locked-notice.php.
	 *
	 * Counted rather than merely looked for: "appears" and "appears once" are
	 * different claims, and the second one is the one that listing several
	 * candidate hooks puts at risk.
	 */
	private const NOTICE = 'class="tlp-locked-notice"';

	public function test_a_locked_course_page_explains_itself(): void {
		$world = $this->locked_course();

		$this->visit( $world['course'] );

		$this->register_fresh_lock();

		$html = $this->render_page( true, false );

		$this->assertSame(
			1,
			substr_count( $html, self::NOTICE ),
			'A learner who has not met the prerequisite must be told why the course is closed.'
		);

		$this->assertStringContainsString(
			'Intro to statistics',
			$html,
			'The notice must name the course that has to be finished first, or it explains nothing.'
		);
	}

	/**
	 * The guarantee that makes listing several candidate hooks safe.
	 *
	 * Every candidate fires here, and the theme renders the description through
	 * the main loop as well, so every path that can print the notice is taken in
	 * one request. Exactly one notice may come out.
	 */
	public function test_the_notice_renders_once_even_when_every_candidate_hook_fires(): void {
		$world = $this->locked_course();

		$this->visit( $world['course'] );

		$this->register_fresh_lock();

		$html = $this->render_page( true, true );

		$this->assertSame(
			1,
			substr_count( $html, self::NOTICE ),
			sprintf(
				'Expected exactly one notice with all %d candidate hooks and the_content firing, got %d. '
				. 'CourseLock::$notice_handled is what keeps this at one.',
				count( HookMap::course_page_notice_actions() ),
				substr_count( $html, self::NOTICE )
			)
		);
	}

	public function test_the_notice_is_absent_once_the_prerequisite_is_met(): void {
		$world = $this->locked_course();

		$this->seeder->complete( $world['student'], $world['prerequisite'] );

		$this->visit( $world['course'] );

		$this->register_fresh_lock();

		$html = $this->render_page( true, true );

		$this->assertStringNotContainsString(
			self::NOTICE,
			$html,
			'A learner who has met every prerequisite must see an ordinary course page.'
		);
	}

	/**
	 * The fallback is still worth keeping.
	 *
	 * A theme that renders the course description through the main loop - and
	 * therefore never reaches Tutor's template hooks - is the one case
	 * `the_content` was right about all along.
	 */
	public function test_a_theme_that_only_renders_the_content_still_shows_the_notice(): void {
		$world = $this->locked_course();

		$this->visit( $world['course'] );

		$this->register_fresh_lock();

		$html = $this->render_page( false, true );

		$this->assertSame(
			1,
			substr_count( $html, self::NOTICE ),
			'With none of Tutor\'s hooks reached, the_content is the only thing left to carry the notice.'
		);
	}

	public function test_the_notice_stays_off_pages_that_are_not_a_course(): void {
		$this->locked_course();

		$this->go_to( home_url( '/' ) );

		$this->register_fresh_lock();

		$html = $this->render_page( true, true );

		$this->assertStringNotContainsString(
			self::NOTICE,
			$html,
			'The notice belongs on a single course page and nowhere else.'
		);
	}

	/**
	 * One course locked behind one other, and a learner who has done neither.
	 *
	 * @return array{course: int, prerequisite: int, student: int}
	 */
	private function locked_course(): array {
		$prerequisite = $this->seeder->course( 'Intro to statistics' );
		$course       = $this->seeder->course( 'Advanced statistics' );
		$student      = $this->seeder->student();

		$this->seeder->require_courses( $course, array( $prerequisite ) );

		wp_set_current_user( $student );

		return array(
			'course'       => $course,
			'prerequisite' => $prerequisite,
			'student'      => $student,
		);
	}

	/**
	 * Put WordPress on the course page.
	 *
	 * Addressed by query var rather than by pretty permalink: the test suite's
	 * rewrite rules are not the subject here, and a fixture that fails for
	 * permalink reasons would look like a bug in the notice.
	 */
	private function visit( int $course_id ): void {
		$post_type = Plugin::instance()->tutor()->course_post_type();

		$this->go_to(
			add_query_arg(
				array(
					'post_type' => $post_type,
					'p'         => $course_id,
				),
				home_url( '/' )
			)
		);

		$this->assertTrue(
			is_singular( $post_type ),
			sprintf( 'The fixture failed to reach course %d as a single %s page.', $course_id, $post_type )
		);

		$this->assertSame(
			$course_id,
			get_queried_object_id(),
			'WordPress resolved the request to the wrong post.'
		);
	}

	/**
	 * A CourseLock with nothing else listening.
	 *
	 * The booted plugin already registered one for the whole process, and its
	 * once-per-request flag would still be set from an earlier test. Clearing
	 * the hooks and registering a fresh instance is what makes each test a
	 * request of its own. WP_UnitTestCase backs up and restores the hook
	 * globals around every test, so this does not leak.
	 */
	private function register_fresh_lock(): void {
		foreach ( HookMap::course_page_notice_actions() as $hook ) {
			remove_all_actions( (string) $hook );
		}

		remove_all_filters( 'the_content' );

		( new CourseLock( Plugin::instance()->gate(), Plugin::instance()->tutor() ) )->register();
	}

	/**
	 * Render the course page the way a given theme would.
	 *
	 * @param bool $tutor_hooks Fire Tutor's template actions.
	 * @param bool $main_loop   Run the main loop and print the content, as a theme
	 *                          that ignores Tutor's templates would.
	 */
	private function render_page( bool $tutor_hooks, bool $main_loop ): string {
		rewind_posts();
		ob_start();

		if ( $main_loop ) {
			while ( have_posts() ) {
				the_post();

				if ( $tutor_hooks ) {
					$this->fire_tutor_hooks();
				}

				the_content();
			}
		} elseif ( $tutor_hooks ) {
			// Tutor's own single-course template: no main loop anywhere on the
			// page, which is precisely why the old the_content filter never ran.
			$this->fire_tutor_hooks();
		}

		return (string) ob_get_clean();
	}

	private function fire_tutor_hooks(): void {
		foreach ( HookMap::course_page_notice_actions() as $hook ) {
			// Tutor passes a course ID to some of these and nothing to others.
			do_action( (string) $hook, get_queried_object_id() );
		}
	}
}
