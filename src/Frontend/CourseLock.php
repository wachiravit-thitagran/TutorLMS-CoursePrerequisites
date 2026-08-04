<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Frontend;

use SpaceWork\TutorLearningPaths\Application\AccessGate;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessResult;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\HookMap;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\TutorAdapterInterface;
use SpaceWork\TutorLearningPaths\Support\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Learner-facing presentation of a lock.
 *
 * Purely cosmetic. Every one of these hooks could be removed and the course
 * would still be unenterable, because the guards do the actual work. This layer
 * exists so a locked course explains itself instead of just failing.
 */
final class CourseLock {

	/**
	 * Whether this request has already decided about the automatic notice.
	 *
	 * Several candidate hooks are attached on purpose (see
	 * `HookMap::course_page_notice_actions()`), so "more than one of them fires"
	 * is the expected case rather than the exception. The first handler to run
	 * owns the decision and every later one returns immediately, which is what
	 * makes the notice appear exactly once.
	 *
	 * Instance state is request state: `Plugin::boot()` constructs exactly one
	 * CourseLock per request and PHP throws the object away with the response.
	 *
	 * @var bool
	 */
	private bool $notice_handled = false;

	public function __construct(
		private readonly AccessGate $gate,
		private readonly TutorAdapterInterface $tutor
	) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		foreach ( HookMap::course_page_notice_actions() as $hook ) {
			// Zero accepted arguments: some of these pass a course ID, some pass
			// nothing, and the handler reads the queried course either way.
			add_action( (string) $hook, array( $this, 'render_notice' ), 10, 0 );
		}

		// Last resort, for a theme that does render the course description
		// through the main loop. On Tutor's own template this never fires,
		// because that template never calls the_post() - which is exactly how
		// the notice went missing in the first place.
		add_filter( 'the_content', array( $this, 'prepend_notice' ), 20 );

		add_shortcode( 'tlp_course_status', array( $this, 'shortcode' ) );
	}

	public function enqueue(): void {
		if ( ! is_singular( $this->tutor->course_post_type() ) ) {
			return;
		}

		wp_enqueue_style( 'tlp-frontend', TLP_URL . 'assets/css/frontend.css', array(), TLP_VERSION );
	}

	/**
	 * Echo the lock explanation where Tutor's course template invites it.
	 *
	 * Attached to every candidate in `HookMap::course_page_notice_actions()`.
	 * Whichever of them the installed Tutor and the active theme actually reach
	 * prints the notice; the rest are no-ops.
	 */
	public function render_notice(): void {
		if ( $this->notice_handled || ! is_singular( $this->tutor->course_post_type() ) ) {
			return;
		}

		// Claimed before the notice is built, so that a hook firing later in the
		// same request cannot print a second copy even if the gate's answer
		// changed underneath us.
		$this->notice_handled = true;

		$notice = $this->notice_for( $this->current_course_id() );

		if ( '' === $notice ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built and escaped by templates/course/locked-notice.php.
		echo $notice;
	}

	/**
	 * Put the lock explanation at the top of a locked course page.
	 *
	 * @param mixed $content Post content.
	 * @return mixed
	 */
	public function prepend_notice( $content ) {
		if ( $this->notice_handled ) {
			return $content;
		}

		if ( ! is_singular( $this->tutor->course_post_type() ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$this->notice_handled = true;

		$notice = $this->notice_for( (int) get_the_ID() );

		return '' === $notice ? $content : $notice . $content;
	}

	/**
	 * `[tlp_course_status course_id="123"]`
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array( 'course_id' => 0 ),
			is_array( $atts ) ? $atts : array(),
			'tlp_course_status'
		);

		$course_id = absint( $atts['course_id'] );

		if ( $course_id <= 0 ) {
			$course_id = (int) get_the_ID();
		}

		return $this->notice_for( $course_id );
	}

	/**
	 * The course this request is displaying.
	 *
	 * `get_the_ID()` is unreliable here: Tutor's single-course template renders
	 * outside the main loop, so there is no "current post" to read. The queried
	 * object is the course either way.
	 */
	private function current_course_id(): int {
		$course_id = (int) get_queried_object_id();

		return $course_id > 0 ? $course_id : (int) get_the_ID();
	}

	/**
	 * Build the notice markup for a course, or an empty string when unlocked.
	 */
	private function notice_for( int $course_id ): string {
		if ( $course_id <= 0 ) {
			return '';
		}

		$user_id = get_current_user_id();
		$result  = $this->gate->evaluate( $user_id, $course_id, AccessContext::Enroll );

		if ( $result->allowed ) {
			return '';
		}

		$markup = Templates::render(
			'course/locked-notice.php',
			array(
				'result'    => $result,
				'checklist' => $this->checklist( $result ),
			)
		);

		/**
		 * Filters the locked course notice markup.
		 *
		 * @param string       $markup    Rendered HTML.
		 * @param AccessResult $result    Denial detail.
		 * @param int          $course_id Course ID.
		 */
		return (string) apply_filters( 'tlp_locked_course_message', $markup, $result, $course_id );
	}

	/**
	 * Turn rule results into a learner-readable checklist.
	 *
	 * Passed rules are included on purpose: seeing two of three ticked is what
	 * makes the lock feel like progress rather than a wall.
	 *
	 * @return array<int, array{id: int, title: string, url: string, done: bool}>
	 */
	private function checklist( AccessResult $result ): array {
		$items = array();

		foreach ( $result->rule_results as $rule_result ) {
			$source_id = $rule_result->source_course_id();

			if ( $source_id <= 0 || ! $this->tutor->course_exists( $source_id ) ) {
				continue;
			}

			$items[] = array(
				'id'    => $source_id,
				'title' => $this->tutor->course_title( $source_id ),
				'url'   => $this->tutor->course_permalink( $source_id ),
				'done'  => $rule_result->passed(),
			);
		}

		return $items;
	}
}
