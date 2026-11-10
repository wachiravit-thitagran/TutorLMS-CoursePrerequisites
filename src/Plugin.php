<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths;

use SpaceWork\TutorLearningPaths\Admin\CourseFields;
use SpaceWork\TutorLearningPaths\Admin\CourseSearchAjax;
use SpaceWork\TutorLearningPaths\Admin\Notices;
use SpaceWork\TutorLearningPaths\Admin\SettingsPage;
use SpaceWork\TutorLearningPaths\Application\AccessGate;
use SpaceWork\TutorLearningPaths\Application\DependencyService;
use SpaceWork\TutorLearningPaths\Application\RuleEngine;
use SpaceWork\TutorLearningPaths\Frontend\CourseLock;
use SpaceWork\TutorLearningPaths\Guards\ContentAccessGuard;
use SpaceWork\TutorLearningPaths\Guards\EnrollmentGuard;
use SpaceWork\TutorLearningPaths\Guards\PurchaseGuard;
use SpaceWork\TutorLearningPaths\Guards\VisibilityGuard;
use SpaceWork\TutorLearningPaths\Infrastructure\Cache\AccessCache;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\CourseRuleRepository;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\Migrator;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\Schema;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\HookMap;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\TutorAdapter;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\TutorAdapterInterface;
use SpaceWork\TutorLearningPaths\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Composition root.
 *
 * Wires the object graph once and hands the shared services to whoever needs
 * them. Deliberately a small service locator rather than a DI container: the
 * graph is shallow and a container would be more machinery than the plugin
 * earns.
 */
final class Plugin {

	private static ?self $instance = null;

	private TutorAdapterInterface $tutor;

	private CourseRuleRepository $rules;

	private AccessGate $gate;

	private DependencyService $dependencies;

	private Compatibility $compatibility;

	private bool $booted = false;

	private function __construct() {
		$this->tutor         = new TutorAdapter();
		$this->rules         = new CourseRuleRepository();
		$this->gate          = new AccessGate( $this->tutor, $this->rules, new RuleEngine() );
		$this->dependencies  = new DependencyService( $this->rules );
		$this->compatibility = new Compatibility( $this->tutor );
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function gate(): AccessGate {
		return $this->gate;
	}

	public function rules(): CourseRuleRepository {
		return $this->rules;
	}

	public function tutor(): TutorAdapterInterface {
		return $this->tutor;
	}

	public function dependencies(): DependencyService {
		return $this->dependencies;
	}

	public function compatibility(): Compatibility {
		return $this->compatibility;
	}

	/**
	 * Register everything.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		( new Notices( $this->compatibility ) )->register();

		if ( ! $this->compatibility->is_satisfied() ) {
			// Stand down, but keep the data and the admin notice.
			return;
		}

		// A version bump between requests, e.g. after an FTP upload.
		if ( Schema::needs_upgrade() ) {
			Migrator::run();
		}

		if ( is_admin() ) {
			Activation::add_capabilities();
			( new SettingsPage() )->register();
		}

		if ( 'disable_self' === Settings::get( 'conflict_policy' ) && $this->tutor->official_prerequisites_active() ) {
			return;
		}

		( new EnrollmentGuard( $this->gate ) )->register();
		( new ContentAccessGuard( $this->gate, $this->tutor ) )->register();
		( new VisibilityGuard( $this->gate, $this->tutor, $this->rules ) )->register();
		( new PurchaseGuard( $this->gate, $this->tutor ) )->register();
		( new CourseLock( $this->gate, $this->tutor ) )->register();

		if ( is_admin() ) {
			( new CourseFields( $this->rules, $this->dependencies, $this->tutor ) )->register();
			( new CourseSearchAjax( $this->tutor ) )->register();
		}

		$this->register_invalidation();
		add_action( 'tlp_access_cache_flushed', array( $this->rules, 'clear_runtime_cache' ) );
		add_action( 'tlp_access_cache_flushed', array( $this->tutor, 'clear_runtime_cache' ) );
		add_action( 'tlp_user_access_cache_flushed', array( $this->tutor, 'clear_runtime_cache' ) );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'tutor-learning-paths', false, dirname( TLP_BASENAME ) . '/languages' );
	}

	/**
	 * Keep cached decisions honest.
	 *
	 * Completion and enrolment change what a learner may reach, so the cheapest
	 * correct thing to do is drop that learner's cached decisions for every
	 * course that depends on the one that changed.
	 */
	private function register_invalidation(): void {
		$invalidate = function ( $course_id, $user_id = 0 ): void {
			$course_id = (int) $course_id;
			$user_id   = (int) $user_id > 0 ? (int) $user_id : get_current_user_id();

			if ( $course_id <= 0 || $user_id <= 0 ) {
				return;
			}

			$affected   = $this->rules->dependents_of( $course_id );
			$affected[] = $course_id;

			AccessCache::flush_user( $user_id, $affected );
		};

		foreach ( array_merge( HookMap::course_completed_actions(), HookMap::enrollment_revoked_actions(), array( HookMap::course_progress_reset_action() ) ) as $hook ) {
			add_action( (string) $hook, $invalidate, 10, 2 );
		}

		add_action(
			HookMap::learner_cleanup_completed_action(),
			static function ( $user_id, $counts, $dry_run ): void {
				if ( ! $dry_run && (int) $user_id > 0 ) {
					AccessCache::flush_user( (int) $user_id );
				}
			},
			10,
			3
		);

		// Tutor's after-enrolled payload is (course ID, enrollment post ID).
		foreach ( HookMap::after_enrol_actions() as $hook ) {
			add_action(
				(string) $hook,
				static function ( $course_id, $enrollment_id ) use ( $invalidate ): void {
					$post = get_post( (int) $enrollment_id );
					if ( $post instanceof \WP_Post && 'tutor_enrolled' === $post->post_type ) {
						$invalidate( (int) $course_id, (int) $post->post_author );
					}
				},
				10,
				2
			);
		}

		$invalidate_enrollment = static function ( $post_id, $post = null ) use ( $invalidate ): void {
			$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
			if ( $post instanceof \WP_Post && 'tutor_enrolled' === $post->post_type ) {
				$invalidate( (int) $post->post_parent, (int) $post->post_author );
			}
		};
		add_action(
			'transition_post_status',
			static function ( $new, $old, $post ) use ( $invalidate_enrollment ): void {
				if ( $new !== $old ) {
					$invalidate_enrollment( $post->ID, $post );
				}
			},
			10,
			3
		);
		add_action( 'deleted_post', $invalidate_enrollment, 10, 2 );
		add_action( HookMap::enrollment_status_changed_action(), $invalidate_enrollment, 10, 1 );
		// Tutor completion resets remove its course_completed comment.
		$invalidate_completion = static function ( $comment_id, $comment = null ) use ( $invalidate ): void {
			$comment = $comment instanceof \WP_Comment ? $comment : get_comment( (int) $comment_id );
			if ( $comment instanceof \WP_Comment && 'course_completed' === $comment->comment_type ) {
				$invalidate( (int) $comment->comment_post_ID, (int) $comment->user_id );
			}
		};
		add_action( 'deleted_comment', $invalidate_completion, 10, 2 );
		add_action(
			'transition_comment_status',
			static function ( $new, $old, $comment ) use ( $invalidate_completion ): void {
				if ( $new !== $old ) {
					$invalidate_completion( $comment->comment_ID, $comment );
				}
			},
			10,
			3
		);

		// A deleted course must not leave dangling rules behind.
		add_action(
			'deleted_post',
			function ( $post_id, $post = null ): void {
				$post_id = (int) $post_id;

				if ( $post instanceof \WP_Post && $post->post_type !== $this->tutor->course_post_type() ) {
					return;
				}

				if ( $this->rules->purge_course( $post_id ) > 0 ) {
					AccessCache::flush_all();
				}
			},
			10,
			2
		);
	}
}
