<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Fixtures;

use SpaceWork\TutorLearningPaths\Admin\RuleInput;
use SpaceWork\TutorLearningPaths\Domain\Course\CourseConfig;
use SpaceWork\TutorLearningPaths\Infrastructure\Cache\AccessCache;
use SpaceWork\TutorLearningPaths\Plugin;

/**
 * Creates the data an integration test needs, using Tutor's own APIs.
 *
 * Every write prefers a Tutor function and only falls back to raw storage when
 * that function does not exist in the installed build. The fallbacks are the
 * same ones `TutorAdapter` reads, which is why `SeederContractTest` exists: it
 * asserts that what the seeder wrote is what Tutor itself reports. If Tutor
 * changes how completion is stored, that one test fails with a clear name
 * instead of thirty tests failing for reasons nobody can trace.
 */
final class Seeder {

	/**
	 * @var int[] Course IDs created during the current test.
	 */
	private array $courses = array();

	public function course_post_type(): string {
		return Plugin::instance()->tutor()->course_post_type();
	}

	/**
	 * Create a course.
	 *
	 * @param array<string, mixed> $args Overrides for wp_insert_post.
	 */
	public function course( string $title = 'Course', array $args = array() ): int {
		$course_id = (int) wp_insert_post(
			array_merge(
				array(
					'post_type'    => $this->course_post_type(),
					'post_title'   => $title,
					'post_status'  => 'publish',
					'post_content' => 'Seeded by the integration suite.',
				),
				$args
			)
		);

		if ( $course_id > 0 ) {
			$this->courses[] = $course_id;
		}

		return $course_id;
	}

	/**
	 * Create several courses at once.
	 *
	 * @return int[] Course IDs, in creation order.
	 */
	public function courses( int $count, string $prefix = 'Course' ): array {
		$ids = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->course( sprintf( '%s %d', $prefix, $i ) );
		}

		return $ids;
	}

	/**
	 * Create a topic inside a course, then a lesson inside the topic.
	 *
	 * Tutor nests lessons two levels below the course, so a test that only
	 * created a lesson with the course as its parent would exercise a shape
	 * that does not occur in production.
	 *
	 * @return array{topic: int, lesson: int}
	 */
	public function lesson( int $course_id, string $title = 'Lesson 1' ): array {
		$topic_id = (int) wp_insert_post(
			array(
				'post_type'   => 'topics',
				'post_title'  => 'Topic',
				'post_status' => 'publish',
				'post_parent' => $course_id,
			)
		);

		$lesson_id = (int) wp_insert_post(
			array(
				'post_type'   => 'lesson',
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_parent' => $topic_id,
			)
		);

		return array(
			'topic'  => $topic_id,
			'lesson' => $lesson_id,
		);
	}

	/**
	 * Create a quiz inside a course.
	 */
	public function quiz( int $course_id, string $title = 'Quiz 1' ): int {
		$topic_id = (int) wp_insert_post(
			array(
				'post_type'   => 'topics',
				'post_title'  => 'Quiz topic',
				'post_status' => 'publish',
				'post_parent' => $course_id,
			)
		);

		return (int) wp_insert_post(
			array(
				'post_type'   => 'tutor_quiz',
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_parent' => $topic_id,
			)
		);
	}

	/**
	 * Create a learner.
	 */
	public function student( string $login = '' ): int {
		$login = '' !== $login ? $login : 'student_' . wp_generate_password( 8, false );

		return (int) wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $login . '@example.test',
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
	}

	public function instructor( string $login = '' ): int {
		$login = '' !== $login ? $login : 'instructor_' . wp_generate_password( 8, false );

		$user_id = (int) wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $login . '@example.test',
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);

		$user = get_user_by( 'id', $user_id );

		if ( $user instanceof \WP_User && get_role( 'tutor_instructor' ) ) {
			$user->add_role( 'tutor_instructor' );
		}

		return $user_id;
	}

	public function administrator(): int {
		return (int) wp_insert_user(
			array(
				'user_login' => 'admin_' . wp_generate_password( 8, false ),
				'user_email' => 'admin_' . wp_generate_password( 8, false ) . '@example.test',
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
	}

	/**
	 * Enrol a learner in a course.
	 */
	public function enrol( int $user_id, int $course_id ): void {
		if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'do_enroll' ) ) {
			tutor_utils()->do_enroll( $course_id, 0, $user_id );
		} else {
			$this->enrol_directly( $user_id, $course_id );
		}

		$this->forget( $user_id, $course_id );
	}

	/**
	 * Mark a course complete for a learner.
	 *
	 * Enrolment is implied: Tutor cannot complete a course nobody joined, and a
	 * test that skipped it would be seeding a state the application can never
	 * reach.
	 */
	public function complete( int $user_id, int $course_id ): void {
		if ( ! Plugin::instance()->tutor()->is_enrolled( $user_id, $course_id ) ) {
			$this->enrol( $user_id, $course_id );
		}

		$this->complete_directly( $user_id, $course_id );
		$this->forget( $user_id, $course_id );
	}

	/**
	 * Attach prerequisite rules to a course.
	 *
	 * Routed through `RuleInput` and the repository - the same path the admin
	 * screen uses - so a fixture can never store a shape the UI could not
	 * produce.
	 *
	 * @param int[]                $prerequisites Course IDs required.
	 * @param array<string, mixed> $config        Overrides for the course config.
	 * @return true|\WP_Error
	 */
	public function require_courses( int $course_id, array $prerequisites, array $config = array() ) {
		$rules = array_map(
			static fn( int $source ): array => array(
				'rule_type' => 'course_completed',
				'source_id' => $source,
			),
			array_values( $prerequisites )
		);

		return $this->apply_rules( $course_id, $rules, $config );
	}

	/**
	 * Attach an arbitrary rule set.
	 *
	 * @param array<int, array<string, mixed>> $rules  Raw rule payloads.
	 * @param array<string, mixed>             $config Course config overrides.
	 * @return true|\WP_Error
	 */
	public function apply_rules( int $course_id, array $rules, array $config = array() ) {
		$payload = array_merge(
			array(
				'enabled'           => true,
				'logic'             => 'ALL',
				'min_required'      => 1,
				'visibility'        => 'visible_locked',
				'locked_text'       => '',
				'admin_bypass'      => true,
				'instructor_bypass' => true,
			),
			$config,
			array( 'rules' => $rules )
		);

		$parsed = RuleInput::parse( $payload, Plugin::instance()->tutor() );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$validation = Plugin::instance()->dependencies()->validate( $course_id, $parsed['rules'] );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$stored = Plugin::instance()->rules()->replace_for_course(
			$course_id,
			$parsed['rules'],
			static fn(): bool => $parsed['config']->save( $course_id )
		);

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		AccessCache::flush_all();
		do_action( 'tlp_rules_saved', $course_id );

		return true;
	}

	/**
	 * Read back the stored configuration, for assertions.
	 */
	public function config( int $course_id ): CourseConfig {
		return CourseConfig::for_course( $course_id );
	}

	/**
	 * @return int[] Every course this seeder created.
	 */
	public function created_courses(): array {
		return $this->courses;
	}

	/**
	 * Write the enrolment record the way Tutor stores it.
	 */
	private function enrol_directly( int $user_id, int $course_id ): void {
		wp_insert_post(
			array(
				'post_type'   => 'tutor_enrolled',
				'post_title'  => 'Course enrolled',
				'post_status' => 'completed',
				'post_author' => $user_id,
				'post_parent' => $course_id,
			)
		);
	}

	/**
	 * Write the completion record the way Tutor stores it.
	 *
	 * Tutor records completion as a comment rather than as post meta. Done
	 * directly because Tutor's own completion path is bound to a nonce-checked
	 * request handler that a unit test cannot reasonably drive.
	 */
	private function complete_directly( int $user_id, int $course_id ): void {
		global $wpdb;

		$user = get_userdata( $user_id );

		$wpdb->insert(
			$wpdb->comments,
			array(
				'comment_post_ID'  => $course_id,
				'comment_author'   => $user instanceof \WP_User ? $user->user_login : '',
				'comment_date'     => current_time( 'mysql' ),
				'comment_date_gmt' => current_time( 'mysql', true ),
				'comment_content'  => 'course_completed',
				'comment_approved' => 'approved',
				'comment_agent'    => 'TutorLMSPlugin',
				'comment_type'     => 'course_completed',
				'user_id'          => $user_id,
			)
		);
	}

	/**
	 * Drop cached decisions for a learner, so the next assertion is fresh.
	 */
	private function forget( int $user_id, int $course_id ): void {
		$affected   = Plugin::instance()->rules()->dependents_of( $course_id );
		$affected[] = $course_id;

		AccessCache::flush_user( $user_id, $affected );
	}
}
