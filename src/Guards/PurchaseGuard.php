<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Guards;

use SpaceWork\TutorLearningPaths\Application\AccessGate;
use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\ActivityLog;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\TutorAdapterInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Stops a learner paying for a course they cannot open.
 *
 * Refunding somebody who bought a course they were never eligible for is far
 * more expensive than refusing the sale, so the check runs at add-to-cart
 * rather than at checkout. Courses explicitly set to "purchasable but not
 * accessible" opt out of this and are sold as advance access.
 */
final class PurchaseGuard {

	public function __construct(
		private readonly AccessGate $gate,
		private readonly TutorAdapterInterface $tutor
	) {}

	public function register(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_woo_add_to_cart' ), 10, 3 );
		}

		// Tutor's native checkout.
		add_filter( 'tutor_before_checkout_process', array( $this, 'validate_native_checkout' ), 10, 1 );
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
		$result  = $this->gate->evaluate( $user_id, $course_id, AccessContext::Purchase );

		if ( $result->allowed ) {
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
	 * @param mixed $data Checkout payload.
	 * @return mixed
	 */
	public function validate_native_checkout( $data ) {
		$course_ids = array();

		if ( is_array( $data ) ) {
			foreach ( array( 'course_id', 'object_id', 'item_id' ) as $key ) {
				if ( isset( $data[ $key ] ) ) {
					$course_ids[] = absint( $data[ $key ] );
				}
			}
		}

		foreach ( array_filter( $course_ids ) as $course_id ) {
			$user_id = get_current_user_id();
			$result  = $this->gate->evaluate( $user_id, $course_id, AccessContext::Purchase );

			if ( $result->allowed ) {
				continue;
			}

			ActivityLog::record( ActivityLog::PURCHASE_DENIED, $user_id, $course_id, $result->reason_code );

			return $result->to_wp_error();
		}

		return $data;
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
						'key'   => '_tutor_course_product_id',
						'value' => $product_id,
					),
				),
			)
		);

		return array() !== $courses ? (int) $courses[0] : 0;
	}
}
