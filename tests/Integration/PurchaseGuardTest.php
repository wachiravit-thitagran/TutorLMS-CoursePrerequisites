<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Guards\PurchaseGuard;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\Schema;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\HookMap;
use SpaceWork\TutorLearningPaths\Plugin;
use SpaceWork\TutorLearningPaths\Support\Visibility;

/**
 * Server-side refusal of a purchase.
 *
 * The hook contract proves the names exist. These prove that when one of them
 * fires, the learner is actually told no - and, just as importantly, that they
 * are let through the moment the prerequisite is met. A gate that refuses
 * everybody is not a working gate.
 *
 * Deliberately not covered here: `PurchaseGuard::guard_native_checkout()`, which
 * hands the refusal to `Responder::deny()`. Responder ends the request - it
 * redirects and exits, or calls `wp_die()` - so nothing after it can be
 * asserted. What is asserted is the decision it acts on,
 * `refusals_for_posted_checkout()`, and the registration that gets it called.
 * The same gap applies to `EnrollmentGuard::guard_action()` and
 * `guard_ajax()`; it is a property of Responder, not of these guards.
 *
 * @group guards
 * @group ecommerce
 */
final class PurchaseGuardTest extends IntegrationTestCase {

	/**
	 * Tutor's own meta key, written out in full on purpose.
	 *
	 * Seeding through `HookMap::course_product_meta_key()` would make the Woo
	 * tests pass with a typo in the map, since the fixture and the guard would
	 * then agree on the wrong key. Same reasoning for the checkout field below.
	 */
	private const COURSE_PRODUCT_META = '_tutor_course_product_id';

	/**
	 * The field Tutor's checkout form posts the course IDs in.
	 */
	private const CHECKOUT_OBJECT_IDS = 'object_ids';

	public function tear_down(): void {
		$_POST = array();

		parent::tear_down();
	}

	public function test_woocommerce_add_to_cart_is_refused_for_a_locked_course(): void {
		$world  = $this->scenarios->linear_chain( 2 );
		$locked = $world['courses'][1];

		$product = $this->sell_course( $locked );
		wp_set_current_user( $world['student'] );

		$this->assertFalse(
			$this->guard()->validate_woo_add_to_cart( true, $product, 1 ),
			'Adding a locked course to the cart must fail validation.'
		);
	}

	public function test_woocommerce_add_to_cart_is_allowed_once_the_prerequisite_is_met(): void {
		$world                  = $this->scenarios->linear_chain( 2 );
		list( $first, $locked ) = $world['courses'];

		$product = $this->sell_course( $locked );

		$this->seeder->complete( $world['student'], $first );
		wp_set_current_user( $world['student'] );

		$this->assertTrue(
			$this->guard()->validate_woo_add_to_cart( true, $product, 1 ),
			'Finishing the prerequisite must make the course buyable.'
		);
	}

	public function test_woocommerce_add_to_cart_ignores_a_product_that_sells_no_course(): void {
		$this->scenarios->linear_chain( 2 );

		$this->assertTrue(
			$this->guard()->validate_woo_add_to_cart( true, 999999, 1 ),
			'A product with no course behind it is none of this plugin\'s business.'
		);
	}

	public function test_woocommerce_add_to_cart_leaves_an_earlier_failure_alone(): void {
		$world = $this->scenarios->linear_chain( 2 );

		$product = $this->sell_course( $world['courses'][1] );
		wp_set_current_user( $world['student'] );

		$this->assertFalse(
			$this->guard()->validate_woo_add_to_cart( false, $product, 1 ),
			'Something else already refused; the guard must not resurrect the request.'
		);
	}

	public function test_the_native_purchase_gate_refuses_a_locked_course(): void {
		$world                  = $this->scenarios->linear_chain( 2 );
		list( $first, $locked ) = $world['courses'];

		wp_set_current_user( $world['student'] );

		$refusal = $this->guard()->validate_native_purchase( true, $locked );

		$this->assertWPError( $refusal, 'Tutor asks whether the course may be bought; the answer must be no.' );
		$this->assertSame( 'tlp_course_locked', $refusal->get_error_code() );

		$data = $refusal->get_error_data();
		$this->assertSame( 403, $data['status'] );
		$this->assertContains(
			$first,
			$data['missing_courses'],
			'The refusal has to name the course that is still outstanding, or the learner cannot act on it.'
		);
	}

	public function test_the_native_purchase_gate_lets_an_unlocked_course_through(): void {
		$world                  = $this->scenarios->linear_chain( 2 );
		list( $first, $locked ) = $world['courses'];

		$this->seeder->complete( $world['student'], $first );
		wp_set_current_user( $world['student'] );

		$this->assertTrue(
			$this->guard()->validate_native_purchase( true, $locked ),
			'The gate must return Tutor\'s own value untouched once the prerequisites pass.'
		);
	}

	public function test_the_native_purchase_gate_does_not_overwrite_an_earlier_refusal(): void {
		$world = $this->scenarios->linear_chain( 2 );

		wp_set_current_user( $world['student'] );

		$earlier = new \WP_Error( 'somebody_else_said_no', 'Enrolment is paused.' );

		$this->assertSame(
			$earlier,
			$this->guard()->validate_native_purchase( $earlier, $world['courses'][1] ),
			'Replacing another plugin\'s reason with ours would hide whichever refusal came first.'
		);
	}

	public function test_the_native_checkout_submit_is_refused_for_a_locked_course(): void {
		$world                  = $this->scenarios->linear_chain( 2 );
		list( $first, $locked ) = $world['courses'];

		wp_set_current_user( $world['student'] );
		$this->post_checkout( array( $locked ) );

		$refusals = $this->guard()->refusals_for_posted_checkout();

		$this->assertArrayHasKey(
			$locked,
			$refusals,
			'A hand-posted checkout for a locked course must be refused before the order exists.'
		);
		$this->assertContains( $first, $refusals[ $locked ]->missing_courses );
	}

	public function test_the_native_checkout_submit_is_allowed_once_the_prerequisite_is_met(): void {
		$world                  = $this->scenarios->linear_chain( 2 );
		list( $first, $locked ) = $world['courses'];

		$this->seeder->complete( $world['student'], $first );
		wp_set_current_user( $world['student'] );
		$this->post_checkout( array( $locked ) );

		$this->assertSame(
			array(),
			$this->guard()->refusals_for_posted_checkout(),
			'Nothing should stand between a qualified learner and the payment form.'
		);
	}

	public function test_the_native_checkout_submit_refuses_a_basket_for_one_locked_course(): void {
		$world                  = $this->scenarios->linear_chain( 2 );
		list( $first, $locked ) = $world['courses'];

		$open = $this->seeder->course( 'Ungated course' );

		wp_set_current_user( $world['student'] );
		$this->post_checkout( array( $open, $locked, $first ) );

		$refusals = $this->guard()->refusals_for_posted_checkout();

		$this->assertSame(
			array( $locked ),
			array_keys( $refusals ),
			'Exactly the locked course in the basket should be refused, and only it.'
		);
	}

	public function test_a_subscription_checkout_is_left_alone(): void {
		$world = $this->scenarios->linear_chain( 2 );

		wp_set_current_user( $world['student'] );
		$this->post_checkout( array( $world['courses'][1] ), 'subscription' );

		$this->assertSame(
			array(),
			$this->guard()->refusals_for_posted_checkout(),
			'A subscription checkout posts plan IDs, not course IDs, and a plan ID that happens to '
			. 'collide with a locked course must not refuse the sale.'
		);
	}

	public function test_a_course_sold_as_advance_access_stays_buyable(): void {
		$world  = $this->scenarios->linear_chain( 2 );
		$locked = $world['courses'][1];

		$this->seeder->require_courses(
			$locked,
			array( $world['courses'][0] ),
			array( 'visibility' => Visibility::PURCHASABLE )
		);

		$product = $this->sell_course( $locked );
		wp_set_current_user( $world['student'] );
		$this->post_checkout( array( $locked ) );

		$guard = $this->guard();

		$this->assertTrue( $guard->validate_woo_add_to_cart( true, $product, 1 ) );
		$this->assertTrue( $guard->validate_native_purchase( true, $locked ) );
		$this->assertSame(
			array(),
			$guard->refusals_for_posted_checkout(),
			'"Purchasable but not accessible" exists so a course can be sold ahead of time. '
			. 'Content stays locked; the sale does not.'
		);
	}

	public function test_a_refused_purchase_is_written_to_the_audit_log(): void {
		global $wpdb;

		$world  = $this->scenarios->linear_chain( 2 );
		$locked = $world['courses'][1];

		wp_set_current_user( $world['student'] );
		$this->guard()->validate_native_purchase( true, $locked );

		$table = Schema::log_table();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from Schema, not from a request.
				"SELECT COUNT(*) FROM {$table} WHERE event = %s AND course_id = %d",
				'purchase_denied',
				$locked
			)
		);

		$this->assertSame( 1, $count, 'A refused purchase should leave exactly one audit row.' );
	}

	/**
	 * Every purchase gate is bound to the name HookMap gives it.
	 *
	 * The bug this file was written for was a guard attached to a hook that does
	 * not exist. `HookContractTest` now proves the names are real; this proves the
	 * guard uses those names and not others, in a live WordPress where
	 * `add_filter` is the real thing.
	 */
	public function test_every_purchase_gate_is_registered_under_its_hook_map_name(): void {
		$guard = $this->guard();
		$guard->register();

		foreach ( HookMap::purchase_gate_filters() as $filter ) {
			$this->assertSame(
				10,
				has_filter( (string) $filter, array( $guard, 'validate_native_purchase' ) ),
				sprintf( 'The native purchase gate is not attached to %s.', (string) $filter )
			);
		}

		foreach ( HookMap::native_checkout_actions() as $action ) {
			$this->assertSame(
				0,
				has_action( 'tutor_action_' . $action, array( $guard, 'guard_native_checkout' ) ),
				sprintf(
					'The checkout submit guard must run at priority 0 on tutor_action_%s, ahead of Tutor\'s own handler.',
					$action
				)
			);
		}

		if ( class_exists( 'WooCommerce' ) ) {
			$this->assertSame(
				10,
				has_filter( HookMap::woo_add_to_cart_filter(), array( $guard, 'validate_woo_add_to_cart' ) )
			);

			return;
		}

		$this->assertFalse(
			has_filter( HookMap::woo_add_to_cart_filter(), array( $guard, 'validate_woo_add_to_cart' ) ),
			'Without WooCommerce there is no cart to guard, and registering the filter anyway would be noise.'
		);
	}

	private function guard(): PurchaseGuard {
		return new PurchaseGuard( Plugin::instance()->gate(), Plugin::instance()->tutor() );
	}

	/**
	 * Link a course to a WooCommerce product, the way Tutor does.
	 *
	 * No WooCommerce needed: the guard's reverse lookup is a meta query, and the
	 * integration matrix installs Tutor but not Woo.
	 *
	 * @return int The product ID.
	 */
	private function sell_course( int $course_id ): int {
		$product_id = (int) wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_title'  => 'Course product',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $course_id, self::COURSE_PRODUCT_META, $product_id );

		return $product_id;
	}

	/**
	 * Fake the checkout form post.
	 *
	 * @param int[] $course_ids Courses the learner is trying to buy.
	 */
	private function post_checkout( array $course_ids, string $order_type = 'single_order' ): void {
		$_POST = array(
			'tutor_action'            => 'tutor_pay_now',
			'order_type'              => $order_type,
			self::CHECKOUT_OBJECT_IDS => implode( ',', $course_ids ),
		);
	}
}
