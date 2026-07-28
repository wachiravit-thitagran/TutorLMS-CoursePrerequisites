<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Guards;

use SpaceWork\TutorLearningPaths\Application\AccessGate;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Domain\Course\CourseConfig;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\CourseRuleRepository;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\Schema;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\TutorAdapterInterface;
use SpaceWork\TutorLearningPaths\Support\Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * Removes courses set to "hidden" from listings a learner should not see.
 *
 * Only courses that opted into hidden mode are ever considered, and that set is
 * cached as a plain list of IDs. Without that shortlist this would have to
 * evaluate every course in the catalogue on every archive request, which is
 * exactly the N+1 the plan sets out to avoid.
 */
final class VisibilityGuard {

	private const SHORTLIST_OPTION = 'tlp_hidden_mode_courses';

	public function __construct(
		private readonly AccessGate $gate,
		private readonly TutorAdapterInterface $tutor,
		private readonly CourseRuleRepository $rules
	) {}

	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'filter_query' ), 20 );
		add_action( 'template_redirect', array( $this, 'guard_singular' ), 0 );
		add_action( 'tlp_rules_saved', array( self::class, 'rebuild_shortlist' ) );
	}

	/**
	 * Return a real 404 for a locked course configured as hidden.
	 */
	public function guard_singular(): void {
		if ( is_admin() || ! is_singular( $this->tutor->course_post_type() ) ) {
			return;
		}

		$course_id = (int) get_queried_object_id();

		if ( ! $this->is_hidden_for_user( $course_id, get_current_user_id() ) ) {
			return;
		}

		global $wp_query;

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();

		$template = get_404_template();

		if ( '' !== $template ) {
			include $template;
		}

		exit;
	}

	/**
	 * Testable decision used by direct requests and other integrations.
	 */
	public function is_hidden_for_user( int $course_id, int $user_id ): bool {
		if ( $course_id <= 0 || ! in_array( $course_id, self::shortlist(), true ) ) {
			return false;
		}

		$this->rules->for_course( $course_id );

		return ! $this->gate->allows( $user_id, $course_id, AccessContext::View );
	}

	/**
	 * Exclude locked, hidden courses from front-end queries.
	 *
	 * @param \WP_Query $query Query being prepared.
	 */
	public function filter_query( $query ): void {
		// Secondary queries count too: shortcodes and related-course widgets
		// are just as capable of leaking a hidden course as the main archive.
		if ( ! $query instanceof \WP_Query || is_admin() || ! $this->is_course_query( $query ) ) {
			return;
		}

		$shortlist = self::shortlist();

		if ( array() === $shortlist ) {
			return;
		}

		$user_id = get_current_user_id();
		$exclude = array();

		$this->rules->for_courses( $shortlist );

		foreach ( $shortlist as $course_id ) {
			if ( ! $this->gate->allows( $user_id, (int) $course_id, AccessContext::View ) ) {
				$exclude[] = (int) $course_id;
			}
		}

		if ( array() === $exclude ) {
			return;
		}

		$existing = (array) $query->get( 'post__not_in', array() );

		$query->set( 'post__not_in', array_values( array_unique( array_merge( $existing, $exclude ) ) ) );
	}

	/**
	 * Whether this query is asking for courses.
	 */
	private function is_course_query( \WP_Query $query ): bool {
		$post_type = $query->get( 'post_type' );
		$course    = $this->tutor->course_post_type();

		if ( is_array( $post_type ) ) {
			return in_array( $course, $post_type, true );
		}

		if ( is_string( $post_type ) && '' !== $post_type ) {
			return $course === $post_type;
		}

		// Untyped queries on a course archive or taxonomy still target courses.
		return $query->is_post_type_archive( $course ) || $query->is_tax( array( 'course-category', 'course-tag' ) );
	}

	/**
	 * Course IDs that use hidden mode.
	 *
	 * @return int[]
	 */
	public static function shortlist(): array {
		$cached = get_option( self::SHORTLIST_OPTION, null );

		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		return self::rebuild_shortlist();
	}

	/**
	 * Recompute the shortlist from the rules table.
	 *
	 * @return int[]
	 */
	public static function rebuild_shortlist(): array {
		global $wpdb;

		$table = Schema::rules_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$course_ids = $wpdb->get_col( "SELECT DISTINCT target_course_id FROM {$table} WHERE enabled = 1" );

		$hidden = array();

		foreach ( (array) $course_ids as $course_id ) {
			$course_id = (int) $course_id;
			$config    = CourseConfig::for_course( $course_id );

			if ( $config->enabled && Visibility::hides_from_listing( $config->visibility ) ) {
				$hidden[] = $course_id;
			}
		}

		update_option( self::SHORTLIST_OPTION, $hidden, true );

		return $hidden;
	}
}
