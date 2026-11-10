<?php
declare(strict_types=1);
namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Plugin;
use SpaceWork\TutorLearningPaths\Infrastructure\Cache\AccessCache;
final class P2RegressionTest extends IntegrationTestCase {
	public function test_actual_tutor_enrollment_payload_invalidates_denial(): void {
		[$a, $b] = $this->seeder->courses( 2 );
		$u       = $this->seeder->student();
		$this->seeder->apply_rules(
			$b,
			array(
				array(
					'rule_type' => 'course_enrolled',
					'source_id' => $a,
				),
			)
		);
		$this->assertLocked( $u, $b );
		tutor_utils()->do_enroll( $a, 0, $u );
		$this->assertUnlocked( $u, $b );
	}
	public function test_enrollment_status_cancellation_invalidates_allowance(): void {
		[$a, $b] = $this->seeder->courses( 2 );
		$u       = $this->seeder->student();
		$this->seeder->apply_rules(
			$b,
			array(
				array(
					'rule_type' => 'course_enrolled',
					'source_id' => $a,
				),
			)
		);
		$this->seeder->enrol( $u, $a );
		$this->assertUnlocked( $u, $b );
		$id = (int) tutor_utils()->is_enrolled( $a, $u )->ID;
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'canceled',
			)
		);
		$this->assertLocked( $u, $b );
	}
	public function test_native_tutor_cancel_and_reset_invalidate_decisions(): void {
		[$a, $b] = $this->seeder->courses( 2 );
		$u       = $this->seeder->student();
		$this->seeder->apply_rules(
			$b,
			array(
				array(
					'rule_type' => 'course_enrolled',
					'source_id' => $a,
				),
			)
		);
		$this->seeder->enrol( $u, $a );
		$this->assertUnlocked( $u, $b );
		tutor_utils()->cancel_course_enrol( $a, $u, 'canceled' );
		$this->assertLocked( $u, $b );
		$this->seeder->apply_rules(
			$b,
			array(
				array(
					'rule_type' => 'course_completed',
					'source_id' => $a,
				),
			)
		);
		$this->seeder->complete( $u, $a );
		$this->assertUnlocked( $u, $b );
		tutor_utils()->delete_course_progress( $a, $u );
		$this->assertLocked( $u, $b );
	}
	public function test_cleanup_plugin_raw_reset_invalidates_warm_allowance_but_preview_does_not(): void {
		[$a, $b] = $this->seeder->courses( 2 );
		$u = $this->seeder->student();
		$this->seeder->require_courses( $b, array( $a ) );
		$this->seeder->complete( $u, $a );
		$this->assertUnlocked( $u, $b );
		global $wpdb;
		$wpdb->delete( $wpdb->comments, array( 'user_id' => $u, 'comment_post_ID' => $a, 'comment_type' => 'course_completed' ) );
		do_action( 'tutor_user_cleanup_after_user', $u, array(), true, 'reset_learner' );
		$this->assertUnlocked( $u, $b );
		do_action( 'tutor_user_cleanup_after_user', $u, array(), false, 'reset_learner' );
		$this->assertLocked( $u, $b );
	}

	public function test_user_only_flush_retires_all_user_keys(): void {
		$key = AccessCache::key( 543, 998, 'enroll' );
		AccessCache::set( $key, 'allowed' );
		AccessCache::flush_user( 543 );
		$this->assertFalse( AccessCache::get( AccessCache::key( 543, 998, 'enroll' ) ) );
	}
	public function test_graph_helpers_hide_private_inputs_and_outputs(): void {
		[$a, $b] = $this->seeder->courses( 2 );
		$this->seeder->require_courses( $b, array( $a ) );
		wp_update_post(
			array(
				'ID'          => $a,
				'post_status' => 'private',
			)
		);
		wp_set_current_user( $this->seeder->student() );
		$this->assertSame( array(), tlp_get_course_prerequisites( $b ) );
		$this->assertSame( array(), tlp_get_dependent_courses( $a ) );
	}
	public function test_mixed_search_omits_hidden_course_only(): void {
		[$a, $b] = $this->seeder->courses( 2 );
		$this->seeder->require_courses( $b, array( $a ), array( 'visibility' => 'hidden' ) );
		wp_update_post(
			array(
				'ID'         => $b,
				'post_title' => 'Mixedneedle',
			)
		);
		$post = self::factory()->post->create(
			array(
				'post_title'  => 'Mixedneedle',
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( $this->seeder->student() );
		foreach ( array( 'any', '' ) as $type ) {
			$query = new \WP_Query(
				array(
					's'         => 'Mixedneedle',
					'post_type' => $type,
				)
			);
			$ids   = wp_list_pluck( $query->posts, 'ID' );
			$this->assertNotContains( $b, $ids );
			$this->assertContains( $post, $ids );
		}
	}
	public function test_repository_revalidates_previously_validated_graph_before_mutation(): void {
		[$a, $b] = $this->seeder->courses( 2 );
		$rulesA  = array(
			array(
				'rule_type' => 'course_completed',
				'source_id' => $b,
			),
		);
		$rulesB  = array(
			array(
				'rule_type' => 'course_completed',
				'source_id' => $a,
			),
		);
		$this->assertTrue( Plugin::instance()->dependencies()->validate( $a, $rulesA ) );
		$this->assertTrue( Plugin::instance()->dependencies()->validate( $b, $rulesB ) );
		$this->assertSame( 1, Plugin::instance()->rules()->replace_for_course( $a, $rulesA ) );
		$this->assertWPError( Plugin::instance()->rules()->replace_for_course( $b, $rulesB ) );
		$this->assertFalse( Plugin::instance()->dependencies()->graph()->has_cycle() );
	}
	public function test_registered_graph_abilities_enforce_course_visibility(): void {
		[$a, $b] = $this->seeder->courses( 2 );
		$this->seeder->require_courses( $b, array( $a ) );
		wp_update_post(
			array(
				'ID'          => $a,
				'post_status' => 'private',
			)
		);
		$u = $this->seeder->student();
		wp_set_current_user( $u );
		$ability = wp_get_ability( 'tutorlms-prerequisites/get-dependent-courses' );
		$this->assertNotNull( $ability );
		$this->assertWPError( $ability->execute( array( 'course_id' => $a ) ) );
		$ability = wp_get_ability( 'tutorlms-prerequisites/get-prerequisites' );
		$result  = $ability->execute( array( 'course_id' => $b ) );
		$this->assertNotWPError( $result );
		$this->assertSame( array(), $result['prerequisites'] );
		$ability = wp_get_ability( 'tutorlms-prerequisites/check-access' );
		$this->assertWPError(
			$ability->execute(
				array(
					'course_id' => $a,
					'user_id'   => $u,
				)
			)
		);
		wp_set_current_user( $this->seeder->administrator() );
		$ability = wp_get_ability( 'tutorlms-prerequisites/get-prerequisites' );
		$result  = $ability->execute( array( 'course_id' => $b ) );
		$this->assertSame( array( $a ), $result['prerequisites'] );
	}
	public function test_graph_lock_is_shared_across_database_connections(): void {
		global $wpdb;
		$rival = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$name  = 'tlp_graph_' . md5( DB_NAME . ':' . $wpdb->prefix );
		$this->assertSame( '1', (string) $rival->get_var( $rival->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) );
		$course = $this->seeder->course();
		try {
			$result = Plugin::instance()->rules()->replace_for_course( $course, array() );
		} finally {
			$rival->get_var( $rival->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
				$rival->close();}
		$this->assertWPError( $result );
		$this->assertSame( 'tlp_graph_busy', $result->get_error_code() );
		$this->assertSame( 0, Plugin::instance()->rules()->replace_for_course( $course, array() ) );
	}
	public function test_completion_deletion_invalidates_after_predelete_callbacks_rewarm_cache(): void {
		[$a, $b] = $this->seeder->courses( 2 );
		$u       = $this->seeder->student();
		$this->seeder->require_courses( $b, array( $a ) );
		$this->seeder->complete( $u, $a );
		$this->assertUnlocked( $u, $b );
		global $wpdb;
		$id     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID=%d AND user_id=%d AND comment_type='course_completed'", $a, $u ) );
		$rewarm = function () use ( $u, $b ) {
			$this->assertUnlocked( $u, $b );
		};
		add_action( 'delete_comment', $rewarm, 50 );
		$after = function () use ( $u, $b ) {
			$this->assertLocked( $u, $b );
		};
		add_action( 'deleted_comment', $after, 50 );
		try {
			wp_delete_comment( $id, true );
		} finally {
			remove_action( 'delete_comment', $rewarm, 50 );
			remove_action( 'deleted_comment', $after, 50 );}
		$this->assertLocked( $u, $b );
	}
}
