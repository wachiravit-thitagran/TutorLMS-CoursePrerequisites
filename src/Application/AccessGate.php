<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Application;

use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessResult;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessStatus;
use SpaceWork\TutorLearningPaths\Domain\Course\CourseConfig;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleContext;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleResult;
use SpaceWork\TutorLearningPaths\Infrastructure\Cache\AccessCache;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\CourseRuleRepository;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\TutorAdapterInterface;
use SpaceWork\TutorLearningPaths\Support\Settings;
use SpaceWork\TutorLearningPaths\Support\Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * The single place where access is decided.
 *
 * Course cards, enrolment handlers, checkout, lesson pages, REST controllers
 * and the dashboard all call this and nothing else. Every duplicated
 * permission check is a future bypass, so there is exactly one.
 */
final class AccessGate {

	public function __construct(
		private readonly TutorAdapterInterface $tutor,
		private readonly CourseRuleRepository $rules,
		private readonly RuleEngine $engine
	) {}

	/**
	 * Decide whether a user may do something with a course.
	 */
	public function evaluate( int $user_id, int $course_id, AccessContext $context ): AccessResult {
		$user_id   = max( 0, $user_id );
		$course_id = max( 0, $course_id );

		if ( $course_id <= 0 ) {
			return AccessResult::allow( $context );
		}

		if ( ! Settings::get( 'enabled', true ) || ! $this->tutor->is_available() ) {
			return AccessResult::allow( $context );
		}

		$config = CourseConfig::for_course( $course_id );

		if ( ! $config->enabled ) {
			return AccessResult::allow( $context );
		}

		$bypass = $this->bypass( $user_id, $course_id, $config, $context );

		if ( null !== $bypass ) {
			return $bypass;
		}

		$cache_key = AccessCache::key( $user_id, $course_id, $context->value );
		$cached    = AccessCache::get( $cache_key );

		if ( $cached instanceof AccessResult ) {
			return $this->filter( $cached, $user_id, $course_id );
		}

		$result = $this->decide( $user_id, $course_id, $config, $context );

		AccessCache::set( $cache_key, $result );

		return $this->filter( $result, $user_id, $course_id );
	}

	/**
	 * Convenience wrapper for the common yes/no question.
	 */
	public function allows( int $user_id, int $course_id, AccessContext $context ): bool {
		return $this->evaluate( $user_id, $course_id, $context )->allowed;
	}

	/**
	 * Roles that are never locked out.
	 *
	 * Returns null when no bypass applies.
	 */
	private function bypass( int $user_id, int $course_id, CourseConfig $config, AccessContext $context ): ?AccessResult {
		if ( $user_id <= 0 ) {
			return null;
		}

		if ( $config->admin_bypass && $this->tutor->is_administrator( $user_id ) ) {
			return AccessResult::allow( $context, AccessStatus::Bypassed );
		}

		if ( $config->instructor_bypass && $this->tutor->is_instructor_of( $user_id, $course_id ) ) {
			return AccessResult::allow( $context, AccessStatus::Bypassed );
		}

		return null;
	}

	/**
	 * Run the rules and translate the outcome into a context-aware decision.
	 */
	private function decide( int $user_id, int $course_id, CourseConfig $config, AccessContext $context ): AccessResult {
		$rules = $this->rules->for_course( $course_id );

		if ( array() === $rules ) {
			return AccessResult::allow( $context );
		}

		$rule_context = new RuleContext( $user_id, $course_id, $context, $this->tutor );
		$evaluation   = $this->engine->evaluate( $rules, $config, $rule_context );

		if ( true === $evaluation['passed'] ) {
			return AccessResult::allow( $context );
		}

		/*
		 * A learner who is already enrolled and part-way through must not be
		 * thrown out of a course they legitimately started - for example after
		 * an instructor adds a new prerequisite. Existing enrolments keep
		 * access to content; the lock only governs new entry.
		 */
		if ( $context->is_content_entry() && $this->tutor->is_enrolled( $user_id, $course_id ) ) {
			/**
			 * Filters whether an existing enrolment survives a later rule change.
			 *
			 * @param bool $grandfather Whether to keep access.
			 * @param int  $user_id     Learner.
			 * @param int  $course_id   Course.
			 */
			if ( apply_filters( 'tlp_grandfather_existing_enrolment', true, $user_id, $course_id ) ) {
				return AccessResult::allow( $context, AccessStatus::Overridden );
			}
		}

		if ( $this->context_is_permitted_while_locked( $context, $config ) ) {
			return AccessResult::allow( $context );
		}

		return $this->denial( $context, $config, $course_id, $evaluation['results'] );
	}

	/**
	 * Whether the configured visibility still permits this context while locked.
	 */
	private function context_is_permitted_while_locked( AccessContext $context, CourseConfig $config ): bool {
		return match ( $context ) {
			// Only "hidden" removes a locked course from listings.
			AccessContext::View     => ! Visibility::hides_from_listing( $config->visibility ),

			/*
			 * Buying ahead is allowed only when the course opted into it, and
			 * only while the site has not switched purchase blocking off
			 * globally. The learner is told before paying that the course will
			 * not open until the prerequisites are done.
			 */
			AccessContext::Purchase => ! (bool) Settings::get( 'block_purchase', true )
				|| Visibility::allows_purchase( $config->visibility ),

			// Enrolling and entering content are never allowed while locked.
			default                 => false,
		};
	}

	/**
	 * Build the denial, including everything the UI needs to explain it.
	 *
	 * @param RuleResult[] $results Rule results.
	 */
	private function denial( AccessContext $context, CourseConfig $config, int $course_id, array $results ): AccessResult {
		$missing = array();

		foreach ( $results as $result ) {
			if ( $result->passed() ) {
				continue;
			}

			$source = $result->source_course_id();

			if ( $source > 0 ) {
				$missing[] = $source;
			}
		}

		$redirect = $config->redirect_to > 0
			? (string) get_permalink( $config->redirect_to )
			: $this->tutor->course_permalink( $course_id );

		return AccessResult::deny(
			$context,
			'incomplete_prerequisites',
			$config->message(),
			array_values( array_unique( $missing ) ),
			$results,
			is_string( $redirect ) ? $redirect : '',
			array( 'visibility' => $config->visibility )
		);
	}

	/**
	 * Final extension point, applied to cached and fresh results alike.
	 */
	private function filter( AccessResult $result, int $user_id, int $course_id ): AccessResult {
		/**
		 * Filters the final access decision.
		 *
		 * Returning a permissive result here bypasses every prerequisite, so
		 * only do it deliberately.
		 *
		 * @param AccessResult $result    Decision.
		 * @param int          $user_id   Learner.
		 * @param int          $course_id Course.
		 */
		$filtered = apply_filters( 'tlp_access_result', $result, $user_id, $course_id );

		return $filtered instanceof AccessResult ? $filtered : $result;
	}
}
