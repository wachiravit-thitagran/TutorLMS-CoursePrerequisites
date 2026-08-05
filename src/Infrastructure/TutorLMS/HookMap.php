<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS;

defined( 'ABSPATH' ) || exit;

/**
 * Every name this plugin binds to somebody else's code, in one place.
 *
 * Mostly Tutor LMS hooks and AJAX actions. Also the handful of Tutor-owned
 * *keys* the guards read - a post meta key, a checkout request field - because a
 * silently renamed key switches a guard off exactly as thoroughly as a silently
 * renamed hook does, and `tests/Contract/HookContractTest.php` can prove a key
 * against Tutor's source for the same price as a hook.
 *
 * Hook names are the part of an LMS integration most likely to move between
 * major versions. Collecting them here means a rename is a one-line fix in a
 * file a maintainer can read, instead of a hunt through the guards - and it
 * makes the "what did we verify against which Tutor build" question answerable.
 *
 * The rule this file exists to make enforceable, checked by
 * `tests/Contract/PluginHookSourceTest.php`: no Tutor-owned name may be written
 * as a literal anywhere else in the plugin. WordPress core hooks are exempt -
 * they are not Tutor's and they do not move with Tutor's releases - but the one
 * WooCommerce filter the plugin depends on is listed here anyway, because it is
 * the sole gate on an entire monetisation path and deserves a name in the same
 * table as the rest.
 *
 * See docs/tutor-hook-matrix.md for the verification status of each entry.
 */
final class HookMap {

	/**
	 * Actions fired before Tutor creates an enrolment.
	 *
	 * Several are listed because Tutor has used more than one over its life and
	 * attaching to a hook that does not exist costs nothing.
	 *
	 * @return string[]
	 */
	public static function before_enrol_actions(): array {
		return (array) apply_filters(
			'tlp_hook_before_enrol_actions',
			array(
				'tutor_before_enrol_to_course',
				'tutor_before_enroll',
			)
		);
	}

	/**
	 * Admin-ajax actions that can create an enrolment.
	 *
	 * Guarded at priority 0 so the check runs before Tutor's own handler.
	 *
	 * @return string[]
	 */
	public static function enrolment_ajax_actions(): array {
		return (array) apply_filters(
			'tlp_hook_enrolment_ajax_actions',
			array(
				'tutor_course_enrollment',
				'tutor_enrol_course',
				'tutor_place_free_order',
			)
		);
	}

	/**
	 * Filters that assemble the enrolment record immediately before it is written.
	 *
	 * The last line of defence on the enrolment path: emptying the payload makes
	 * Tutor abandon the insert, so a request that slipped past every earlier
	 * guard still cannot leave an enrolment behind.
	 *
	 * @return string[]
	 */
	public static function enrolment_data_filters(): array {
		return (array) apply_filters(
			'tlp_hook_enrolment_data_filters',
			array(
				'tutor_enroll_data',
			)
		);
	}

	/**
	 * Actions fired once an enrolment exists.
	 *
	 * Used only to drop cached decisions: a new enrolment can satisfy another
	 * course's prerequisite, and a stale cache would leave that course locked
	 * until the TTL expired.
	 *
	 * @return string[]
	 */
	public static function after_enrol_actions(): array {
		return (array) apply_filters(
			'tlp_hook_after_enrol_actions',
			array(
				'tutor_after_enroll',
			)
		);
	}

	/**
	 * REST route fragments that create an enrolment or an order.
	 *
	 * Matched as substrings against the requested route.
	 *
	 * @return string[]
	 */
	public static function enrolment_rest_fragments(): array {
		return (array) apply_filters(
			'tlp_hook_enrolment_rest_fragments',
			array(
				'/tutor/v1/enrollments',
				'/tutor/v1/course-enroll',
			)
		);
	}

	/**
	 * WooCommerce's add-to-cart validation filter.
	 *
	 * WooCommerce's, not Tutor's, which is why it is a single name with no
	 * alternates and why the hook contract pins it instead of looking for it in
	 * Tutor's source. WooCommerce applies it in
	 * `WC_Form_Handler::add_to_cart_action()` and `WC_AJAX::add_to_cart()`, and
	 * those are the two routes Tutor's course templates use: Tutor's
	 * `single/course/add-to-cart-woocommerce.php` renders a plain
	 * `name="add-to-cart"` form post, and the course loop renders WooCommerce's own
	 * `ajax_add_to_cart` button.
	 *
	 * Worth knowing where it does *not* fire: `WC_Cart::add_to_cart()` itself
	 * never applies it, so Tutor's `tutor_add_to_cart()` helper - which calls
	 * `WC_Cart::add_to_cart()` directly - bypasses this gate. Tutor LMS free never
	 * calls that helper, but Pro and the mobile app may; those requests are caught
	 * later, on the enrolment path, by `enrolment_data_filters()`.
	 */
	public static function woo_add_to_cart_filter(): string {
		return (string) apply_filters( 'tlp_hook_woo_add_to_cart_filter', 'woocommerce_add_to_cart_validation' );
	}

	/**
	 * Filters Tutor's native eCommerce consults before it will sell a course.
	 *
	 * Returning a `WP_Error` refuses the sale. Tutor 4.0 asks this in all three
	 * places a purchase can begin - putting a course in the cart, loading the
	 * checkout page, and submitting the payment - which makes it the one hook
	 * worth having on this path.
	 *
	 * It does not exist before Tutor 4.0.0, and nothing equivalent does: 3.x has
	 * no purchase gate at all. That is why `native_checkout_actions()` exists
	 * alongside this one and is what actually protects a 3.x site.
	 *
	 * @return string[]
	 */
	public static function purchase_gate_filters(): array {
		return (array) apply_filters(
			'tlp_hook_purchase_gate_filters',
			array(
				'tutor_can_purchase_course',
			)
		);
	}

	/**
	 * Tutor request actions that submit a native eCommerce checkout.
	 *
	 * Reached as `tutor_action_<name>`: Tutor dispatches
	 * `do_action( 'tutor_action_' . $tutor_action )` from the `tutor_action`
	 * request field, and the checkout form posts `tutor_action=tutor_pay_now`.
	 * Guarded at priority 0, ahead of Tutor's own handler, in the same shape as
	 * the enrolment AJAX guards.
	 *
	 * This is the gate that works on every Tutor this plugin supports, including
	 * the 3.x builds that have no `tutor_can_purchase_course`.
	 *
	 * `tutor_pay_incomplete_order` is deliberately absent: that request carries an
	 * order ID rather than course IDs, resolving it would mean reaching into
	 * Tutor's order model, and the order it re-pays was already gated when it was
	 * created.
	 *
	 * @return string[]
	 */
	public static function native_checkout_actions(): array {
		return (array) apply_filters(
			'tlp_hook_native_checkout_actions',
			array(
				'tutor_pay_now',
			)
		);
	}

	/**
	 * Request field carrying the IDs a native checkout is trying to buy.
	 *
	 * Comma separated, and for a single-course order the IDs are course IDs.
	 * Tutor has posted it under this name since native eCommerce shipped in 3.0.
	 */
	public static function checkout_object_ids_field(): string {
		return (string) apply_filters( 'tlp_hook_checkout_object_ids_field', 'object_ids' );
	}

	/**
	 * Post meta linking a course to the WooCommerce product that sells it.
	 *
	 * Stored on the course, so finding the course behind a product is a reverse
	 * lookup. A rename here would raise no error: the lookup would simply stop
	 * finding courses and every add-to-cart would sail through. That is the same
	 * silent failure a renamed hook causes, so it is watched the same way.
	 */
	public static function course_product_meta_key(): string {
		return (string) apply_filters( 'tlp_hook_course_product_meta_key', '_tutor_course_product_id' );
	}

	/**
	 * Actions that mark the place on a single-course page where a learner-facing
	 * notice belongs.
	 *
	 * Three names, deliberately chosen from three *different* Tutor template
	 * files, because a theme that overrides Tutor's templates overrides one or
	 * two of them, almost never all three:
	 *
	 * - `tutor_course/single/before/inner-wrap` - templates/single-course.php,
	 *   top of the main column, above the course tabs.
	 * - `tutor_course/single/before/content` - templates/single/course/
	 *   course-content.php, immediately above the course description. This is
	 *   the position the notice used to occupy.
	 * - `tutor_course/single/entry/after` - templates/single/course/
	 *   course-entry-box.php, directly under the enrol button, which is the
	 *   thing that appeared with no explanation when this went wrong.
	 *
	 * Attaching to a hook that never fires costs nothing, and `CourseLock`
	 * renders at most one notice per request however many of them fire.
	 *
	 * Why not `the_content`, which this used to rely on: Tutor's single-course
	 * template renders the course with `get_the_ID()` and never calls
	 * `the_post()` on the main query, so `in_the_loop()` is false for the whole
	 * page and a `the_content` filter guarded on it never runs. Enforcement was
	 * unaffected - the guards do that - but the explanation silently vanished.
	 * `the_content` is kept as a last-resort fallback for themes that do render
	 * the description through the main loop.
	 *
	 * @return string[]
	 */
	public static function course_page_notice_actions(): array {
		return (array) apply_filters(
			'tlp_hook_course_page_notice_actions',
			array(
				'tutor_course/single/before/inner-wrap',
				'tutor_course/single/before/content',
				'tutor_course/single/entry/after',
			)
		);
	}

	/**
	 * Actions fired when a learner completes a course.
	 *
	 * @return string[]
	 */
	public static function course_completed_actions(): array {
		return (array) apply_filters(
			'tlp_hook_course_completed_actions',
			array(
				'tutor_course_complete_after',
				'tutor_course_completed',
			)
		);
	}

	/**
	 * Action fired after the course builder has loaded, used to enqueue the
	 * custom field script only where it is needed.
	 */
	public static function course_builder_loaded_action(): string {
		return (string) apply_filters( 'tlp_hook_course_builder_loaded', 'tutor_before_course_builder_load' );
	}
}
