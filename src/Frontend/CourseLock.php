<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Frontend;

use SpaceWork\TutorLearningPaths\Application\AccessGate;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessResult;
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

	public function __construct(
		private readonly AccessGate $gate,
		private readonly TutorAdapterInterface $tutor
	) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
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
	 * Put the lock explanation at the top of a locked course page.
	 *
	 * @param mixed $content Post content.
	 * @return mixed
	 */
	public function prepend_notice( $content ) {
		if ( ! is_singular( $this->tutor->course_post_type() ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

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
