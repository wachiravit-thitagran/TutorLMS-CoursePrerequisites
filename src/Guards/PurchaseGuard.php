<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Guards;

use SpaceWork\TutorLearningPaths\Application\AccessGate;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessResult;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\ActivityLog;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\HookMap;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\TutorAdapterInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Stops a learner paying for a course they cannot open.
 *
 * Refunding somebody who bought a course they were never eligible for is far
 * more expensive than refusing the sale, so this guard sits as early on each
 * monetisation path as that path allows. Courses explicitly set to "purchasable
 * but not accessible" opt out and are sold as advance access.
 *
 * Two paths, three gates:
 *
 * - WooCommerce: `woocommerce_add_to_cart_validation`, at add-to-cart.
 * - Tutor native eCommerce: `tutor_can_purchase_course`, which Tutor 4.0 asks
 *   at add-to-cart, at checkout page load and at payment.
 * - Tutor native eCommerce on 3.x, which has no purchase gate at all: the
 *   checkout submit itself, `tutor_action_tutor_pay_now`, at priority 0.
 *
 * Every name comes from `HookMap`, so the hook contract suite proves each one
 * still exists in the Tutor versions CI installs. The previous version of this
 * file attached to `tutor_before_checkout_process`, a name no Tutor between
 * 3.0.2 and 4.0.4 has ever declared, and nothing noticed for as long as the
 * name lived here instead of there.
 */
final class PurchaseGuard {

	public function __construct(
		private readonly AccessGate $gate,
		private readonly TutorAdapterInterface $tutor
	) {}

	public function register(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			add_filter( HookMap::woo_add_to_cart_filter(), array( $this, 'validate_woo_add_to_cart' ), 10, 3 );
		}

		foreach ( HookMap::purchase_gate_filters() as $filter ) {
			add_filter( (string) $filter, array( $this, 'validate_native_purchase' ), 10, 2 );
		}

		foreach ( HookMap::native_checkout_actions() as $action ) {
			// Priority 0: ahead of Tutor's own checkout handler.
			add_action( 'tutor_action_' . $action, array( $this, 'guard_native_checkout' ), 0 );
		}
	}

	/**
	 * @param mixed $passed     Current validation state.
	 * @param mixed $product_id Product being added.
	 * @param mixed $quantity   Quantity.
	 * @return mixed
	 */
	public function validate_woo_add_to_cart( $passed, $product_id, $quantity ) {
		if ( ! $passed ) {
			return $passed;
		}

		$course_id = $this->course_for_product( (int) $product_id );

		if ( $course_id <= 0 ) {
			return $passed;
		}

		$user_id = get_current_user_id();
		$result  = $this->refusal_for( $user_id, $course_id );

		if ( null === $result ) {
			return $passed;
		}

		ActivityLog::record(
			ActivityLog::PURCHASE_DENIED,
			$user_id,
			$course_id,
			$result->reason_code,
			array( 'product_id' => (int) $product_id )
		);

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $result->message, 'error' );
		}

		return false;
	}

	/**
	 * Answer Tutor's own "may this learner buy this course" question.
	 *
	 * Tutor treats a `WP_Error` as a refusal and reports it to the learner, so
	 * this is the one gate on the native path that produces a proper message
	 * instead of a redirect.
	 *
	 * @param mixed $can_buy   Whatever the previous filter decided.
	 * @param mixed $course_id Course, or - for a subscription order - a plan ID.
	 * @return mixed
	 */
	public function validate_native_purchase( $can_buy, $course_id = 0 ) {
		// Somebody already said no. Overwriting their reason with ours would
		// hide whichever refusal came first.
		if ( is_wp_error( $can_buy ) ) {
			return $can_buy;
		}

		$course_id = (int) $course_id;
		$user_id   = get_current_user_id();
		$result    = $this->refusal_for( $user_id, $course_id );

		if ( null === $result ) {
			return $can_buy;
		}

		ActivityLog::record(
			ActivityLog::PURCHASE_DENIED,
			$user_id,
			$course_id,
			$result->reason_code,
			array( 'stage' => 'native_purchase_gate' )
		);

		return $result->to_wp_error();
	}

	/**
	 * Refuse a native checkout submission.
	 *
	 * The only gate Tutor 3.x offers - it asks nothing before it creates an
	 * order - and a belt for 4.x. Reads the target courses out of the request
	 * the way `EnrollmentGuard::guard_ajax()` does, because the action carries no
	 * arguments.
	 */
	public function guard_native_checkout(): void {
		$user_id = get_current_user_id();

		foreach ( $this->refusals_for_posted_checkout() as $course_id => $result ) {
			// Ends the request. Responder writes the audit row itself.
			Responder::deny( $result, ActivityLog::PURCHASE_DENIED, $user_id, (int) $course_id );
		}
	}

	/**
	 * The decision behind `guard_native_checkout()`, split out so it can be
	 * asserted on.
	 *
	 * `Responder::deny()` ends the request - it redirects and exits, or calls
	 * `wp_die()` - which a test cannot survive. Keeping the decision in its own
	 * method is what lets `tests/Integration/PurchaseGuardTest.php` prove the
	 * refusal without proving the response.
	 *
	 * @return array<int, AccessResult> Course ID => refusal. Empty when the
	 *                                  checkout may proceed.
	 */
	public function refusals_for_posted_checkout(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Tutor verifies its own nonce inside the handler this guard runs ahead of, and nothing is written here.
		$request = wp_unslash( $_POST );

		if ( ! is_array( $request ) ) {
			return array();
		}

		/*
		 * A subscription checkout posts plan IDs, not course IDs, and a plan is
		 * not something this plugin holds rules about. Tutor narrows its own
		 * `tutor_can_purchase_course` call the same way. If Tutor ever renames
		 * `order_type` this degrades to checking every posted ID, which is safe:
		 * an ID that is not a gated course has no rules and is allowed.
		 */
		$order_type = isset( $request['order_type'] ) ? sanitize_key( (string) $request['order_type'] ) : '';

		if ( '' !== $order_type && 'single_order' !== $order_type ) {
			return array();
		}

		$field   = HookMap::checkout_object_ids_field();
		$posted  = isset( $request[ $field ] ) ? (string) $request[ $field ] : '';
		$user_id = get_current_user_id();

		$refusals = array();

		foreach ( array_filter( array_map( 'absint', explode( ',', $posted ) ) ) as $course_id ) {
			$result = $this->refusal_for( $user_id, $course_id );

			if ( null !== $result ) {
				$refusals[ $course_id ] = $result;
			}
		}

		return $refusals;
	}

	/**
	 * One purchase decision, in the one shape every gate here needs.
	 *
	 * @param int $user_id   Learner, 0 when not logged in.
	 * @param int $course_id Course being bought.
	 * @return AccessResult|null The refusal, or null when the purchase is allowed.
	 */
	private function refusal_for( int $user_id, int $course_id ): ?AccessResult {
		if ( $course_id <= 0 ) {
			return null;
		}

		$result = $this->gate->evaluate( $user_id, $course_id, AccessContext::Purchase );

		return $result->allowed ? null : $result;
	}

	/**
	 * Find the course a WooCommerce product sells.
	 *
	 * Tutor stores the link on the course side, so this is a reverse lookup.
	 */
	private function course_for_product( int $product_id ): int {
		if ( $product_id <= 0 ) {
			return 0;
		}

		$courses = get_posts(
			array(
				'post_type'              => $this->tutor->course_post_type(),
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => array(
					array(
						'key'   => HookMap::course_product_meta_key(),
						'value' => $product_id,
					),
				),
			)
		);

		return array() !== $courses ? (int) $courses[0] : 0;
	}
}
