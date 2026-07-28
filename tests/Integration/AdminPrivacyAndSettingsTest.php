<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Admin\CourseSearchAjax;
use SpaceWork\TutorLearningPaths\Admin\RuleInput;
use SpaceWork\TutorLearningPaths\Admin\SettingsPage;
use SpaceWork\TutorLearningPaths\Guards\VisibilityGuard;
use SpaceWork\TutorLearningPaths\Plugin;
use SpaceWork\TutorLearningPaths\Support\Visibility;

/**
 * Admin configuration and course privacy boundaries.
 *
 * @group admin
 */
final class AdminPrivacyAndSettingsTest extends IntegrationTestCase {

	public function test_settings_input_is_allowlisted_and_bounded(): void {
		$sanitized = SettingsPage::sanitize(
			array(
				'enabled'             => '1',
				'default_visibility'  => Visibility::HIDDEN,
				'default_locked_text' => '<b>Complete first</b>',
				'admin_bypass'        => '1',
				'cache_ttl'           => '999999',
				'conflict_policy'     => 'delete_everything',
				'unknown'             => 'must not survive',
			)
		);

		$this->assertTrue( $sanitized['enabled'] );
		$this->assertSame( Visibility::HIDDEN, $sanitized['default_visibility'] );
		$this->assertSame( 'Complete first', $sanitized['default_locked_text'] );
		$this->assertSame( 86400, $sanitized['cache_ttl'] );
		$this->assertSame( 'warn', $sanitized['conflict_policy'] );
		$this->assertArrayNotHasKey( 'unknown', $sanitized );
	}

	public function test_course_search_hides_another_authors_private_course(): void {
		$first_author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$second_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$private       = $this->seeder->course( 'Private source', array( 'post_author' => $second_author, 'post_status' => 'private' ) );
		$published     = $this->seeder->course( 'Published source', array( 'post_author' => $second_author ) );

		wp_set_current_user( $first_author );

		$this->assertFalse( CourseSearchAjax::can_include( get_post( $private ) ) );
		$this->assertTrue( CourseSearchAjax::can_include( get_post( $published ) ) );

		$crafted = RuleInput::parse(
			array(
				'enabled' => true,
				'rules'   => array( array( 'rule_type' => 'course_completed', 'source_id' => $private ) ),
			),
			Plugin::instance()->tutor()
		);

		$this->assertWPError( $crafted );
		$this->assertSame( 'tlp_source_course_forbidden', $crafted->get_error_code() );
	}

	public function test_hidden_mode_hides_the_direct_url_but_respects_admin_bypass(): void {
		$locked_user  = $this->seeder->student( 'direct_hidden_locked' );
		$administrator = $this->seeder->administrator();
		$prerequisite = $this->seeder->course( 'Direct prerequisite' );
		$course_id    = $this->seeder->course( 'Direct hidden course' );

		$this->seeder->require_courses(
			$course_id,
			array( $prerequisite ),
			array( 'visibility' => Visibility::HIDDEN )
		);

		$guard = new VisibilityGuard(
			Plugin::instance()->gate(),
			Plugin::instance()->tutor(),
			Plugin::instance()->rules()
		);

		$this->assertTrue( $guard->is_hidden_for_user( $course_id, $locked_user ) );
		$this->assertFalse( $guard->is_hidden_for_user( $course_id, $administrator ) );
	}
}
