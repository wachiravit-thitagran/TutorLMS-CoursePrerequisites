<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Admin;

use SpaceWork\TutorLearningPaths\Application\DependencyService;
use SpaceWork\TutorLearningPaths\Domain\Course\CourseConfig;
use SpaceWork\TutorLearningPaths\Domain\Rule\Operator;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleRegistry;
use SpaceWork\TutorLearningPaths\Infrastructure\Cache\AccessCache;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\ActivityLog;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\CourseRuleRepository;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\HookMap;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\TutorAdapterInterface;
use SpaceWork\TutorLearningPaths\Support\Templates;
use SpaceWork\TutorLearningPaths\Support\Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * The "Learning Path & Prerequisites" editor.
 *
 * Two front ends, one save path. Tutor's course builder gets a registered
 * custom field; the classic post editor gets a metabox. Both post to the same
 * AJAX endpoint, so validation and cycle detection cannot diverge between them.
 */
final class CourseFields {

	private const NONCE = 'tlp_course_rules';

	public function __construct(
		private readonly CourseRuleRepository $rules,
		private readonly DependencyService $dependencies,
		private readonly TutorAdapterInterface $tutor
	) {}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'wp_ajax_tlp_save_course_rules', array( $this, 'handle_save' ) );
		add_action( 'wp_ajax_tlp_get_course_rules', array( $this, 'handle_read' ) );
		add_action( HookMap::course_builder_loaded_action(), array( $this, 'enqueue_builder_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function add_meta_box(): void {
		add_meta_box(
			'tlp-course-prerequisites',
			__( 'Learning Path & Prerequisites', 'tutor-learning-paths' ),
			array( $this, 'render_meta_box' ),
			$this->tutor->course_post_type(),
			'normal',
			'default'
		);
	}

	/**
	 * @param \WP_Post $post Course being edited.
	 */
	public function render_meta_box( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Rendered through the loader so a theme can replace the editor markup
		// the same way it can replace the learner-facing notice.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes its own output.
		echo Templates::render(
			'admin/course-fields.php',
			array(
				'config' => CourseConfig::for_course( (int) $post->ID ),
				'rules'  => $this->rules->for_course( (int) $post->ID, false ),
				'post'   => $post,
			)
		);
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || $screen->post_type !== $this->tutor->course_post_type() ) {
			return;
		}

		$this->enqueue( 'tlp-admin-rules' );
	}

	/**
	 * Enqueue the course builder integration.
	 *
	 * Hooked to Tutor's builder-loaded action so the script is not shipped to
	 * every admin screen.
	 */
	public function enqueue_builder_assets(): void {
		$this->enqueue( 'tlp-admin-rules' );

		wp_enqueue_script(
			'tlp-course-builder',
			TLP_URL . 'assets/js/course-builder.js',
			array( 'tlp-admin-rules', 'tutor-course-builder', 'wp-element' ),
			TLP_VERSION,
			true
		);
	}

	private function enqueue( string $handle ): void {
		wp_enqueue_style(
			'tlp-admin',
			TLP_URL . 'assets/css/admin.css',
			array(),
			TLP_VERSION
		);

		wp_enqueue_script(
			$handle,
			TLP_URL . 'assets/js/admin-rules.js',
			array( 'wp-i18n' ),
			TLP_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'TLPAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'courseId'    => $this->current_course_id(),
				'visibility'  => Visibility::choices(),
				'operators'   => Operator::choices(),
				'ruleTypes'   => array_map(
					static fn( $type ): string => $type->label(),
					RuleRegistry::all()
				),
				'i18n'        => array(
					'searchPlaceholder' => __( 'Search courses by title or ID…', 'tutor-learning-paths' ),
					'noResults'         => __( 'No courses found.', 'tutor-learning-paths' ),
					'saved'             => __( 'Prerequisites saved.', 'tutor-learning-paths' ),
					'saveFailed'        => __( 'Could not save the prerequisites.', 'tutor-learning-paths' ),
					'remove'            => __( 'Remove', 'tutor-learning-paths' ),
				),
			)
		);
	}

	/**
	 * The course being edited.
	 *
	 * `get_the_ID()` is not dependable during `admin_enqueue_scripts`, so the
	 * request is consulted as well. This is only used to seed the editor; the
	 * authoritative ID comes from the markup and is re-checked on save.
	 */
	private function current_course_id(): int {
		$id = (int) get_the_ID();

		if ( $id > 0 ) {
			return $id;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, re-validated on save.
		return isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
	}

	/**
	 * Read the current configuration, for the builder UI.
	 */
	public function handle_read(): void {
		$course_id = $this->authorise();

		$rules = array_map(
			static fn( $rule ): array => array(
				'rule_type' => $rule->rule_type,
				'source_id' => $rule->source_id,
				'title'     => get_the_title( $rule->source_id ),
				'enabled'   => $rule->enabled,
			),
			$this->rules->for_course( $course_id, false )
		);

		wp_send_json_success(
			array(
				'config' => CourseConfig::for_course( $course_id )->to_array(),
				'rules'  => $rules,
				'html'   => Templates::render(
					'admin/course-fields.php',
					array(
						'config' => CourseConfig::for_course( $course_id ),
						'rules'  => $this->rules->for_course( $course_id, false ),
						'post'   => get_post( $course_id ),
					)
				),
			)
		);
	}

	/**
	 * Validate and persist.
	 */
	public function handle_save(): void {
		$course_id = $this->authorise();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$payload = isset( $_POST['payload'] ) ? json_decode( wp_unslash( (string) $_POST['payload'] ), true ) : null;

		if ( ! is_array( $payload ) ) {
			wp_send_json_error(
				array( 'message' => __( 'The prerequisite data could not be read.', 'tutor-learning-paths' ) ),
				400
			);
		}

		$parsed = RuleInput::parse( $payload, $this->tutor );

		if ( is_wp_error( $parsed ) ) {
			wp_send_json_error(
				array(
					'message' => $parsed->get_error_message(),
					'code'    => $parsed->get_error_code(),
				),
				422
			);
		}

		// The important check: never let a save create a loop.
		$validation = $this->dependencies->validate( $course_id, $parsed['rules'] );

		if ( is_wp_error( $validation ) ) {
			wp_send_json_error(
				array(
					'message' => $validation->get_error_message(),
					'code'    => $validation->get_error_code(),
					'data'    => $validation->get_error_data(),
				),
				422
			);
		}

		$stored = $this->rules->replace_for_course(
			$course_id,
			$parsed['rules'],
			static fn(): bool => $parsed['config']->save( $course_id )
		);

		if ( is_wp_error( $stored ) ) {
			wp_send_json_error(
				array(
					'message' => $stored->get_error_message(),
					'code'    => $stored->get_error_code(),
				),
				500
			);
		}

		AccessCache::flush_all();

		ActivityLog::record(
			ActivityLog::RULES_CHANGED,
			0,
			$course_id,
			'rules_saved',
			array( 'rule_count' => $stored )
		);

		/**
		 * Fires after a course's rules have been replaced.
		 *
		 * @param int $course_id Course ID.
		 */
		do_action( 'tlp_rules_saved', $course_id );

		wp_send_json_success(
			array(
				'message'    => __( 'Prerequisites saved.', 'tutor-learning-paths' ),
				'rule_count' => $stored,
			)
		);
	}

	/**
	 * Shared nonce, capability and ownership check.
	 *
	 * Exits on failure; returns the validated course ID on success.
	 */
	private function authorise(): int {
		check_ajax_referer( self::NONCE, 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked immediately above.
		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;

		if ( $course_id <= 0 || ! $this->tutor->course_exists( $course_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'That course does not exist.', 'tutor-learning-paths' ) ),
				404
			);
		}

		// current_user_can with an object ID is what stops an instructor from
		// editing the prerequisites of a course that is not theirs.
		if ( ! current_user_can( 'edit_post', $course_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to edit this course.', 'tutor-learning-paths' ) ),
				403
			);
		}

		return $course_id;
	}
}
