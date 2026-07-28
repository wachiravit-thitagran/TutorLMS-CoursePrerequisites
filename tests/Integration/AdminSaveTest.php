<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Admin\RuleInput;
use SpaceWork\TutorLearningPaths\Domain\Rule\Operator;
use SpaceWork\TutorLearningPaths\Plugin;
use SpaceWork\TutorLearningPaths\Support\Visibility;

/**
 * What the rule editor accepts and what it refuses.
 *
 * @group admin
 */
final class AdminSaveTest extends IntegrationTestCase {

	public function test_a_valid_payload_round_trips(): void {
		$prerequisite = $this->seeder->course( 'Round trip prerequisite' );
		$course_id    = $this->seeder->course( 'Round trip course' );

		$this->seeder->require_courses(
			$course_id,
			array( $prerequisite ),
			array(
				'logic'       => 'ANY',
				'visibility'  => Visibility::PREVIEW,
				'locked_text' => 'Finish the basics first.',
			)
		);

		$config = $this->seeder->config( $course_id );

		$this->assertTrue( $config->enabled );
		$this->assertSame( Operator::Any, $config->logic );
		$this->assertSame( Visibility::PREVIEW, $config->visibility );
		$this->assertSame( 'Finish the basics first.', $config->locked_text );
		$this->assertCount( 1, Plugin::instance()->rules()->for_course( $course_id ) );
	}

	public function test_an_unknown_rule_type_is_refused(): void {
			$result = RuleInput::parse(
				array(
					'enabled' => true,
					'rules'   => array( array( 'rule_type' => 'not_a_real_rule', 'source_id' => 1 ) ),
				),
				Plugin::instance()->tutor()
			);

		$this->assertWPError( $result );
		$this->assertSame( 'tlp_unknown_rule_type', $result->get_error_code() );
	}

	public function test_an_unknown_visibility_mode_is_refused(): void {
			$result = RuleInput::parse(
				array(
					'enabled'    => true,
					'visibility' => 'invisible_somehow',
					'rules'      => array(),
				),
				Plugin::instance()->tutor()
			);

		$this->assertWPError( $result );
		$this->assertSame( 'tlp_invalid_visibility', $result->get_error_code() );
	}

	public function test_a_rule_without_a_course_is_refused(): void {
			$result = RuleInput::parse(
				array(
					'enabled' => true,
					'rules'   => array( array( 'rule_type' => 'course_completed', 'source_id' => 0 ) ),
				),
				Plugin::instance()->tutor()
			);

		$this->assertWPError( $result );
		$this->assertSame( 'tlp_invalid_rule', $result->get_error_code() );
	}

	public function test_duplicate_prerequisites_are_collapsed(): void {
		$prerequisite = $this->seeder->course( 'Listed twice' );
		$course_id    = $this->seeder->course( 'Duplicate host' );

		$this->seeder->require_courses( $course_id, array( $prerequisite, $prerequisite ) );

		$this->assertCount(
			1,
			Plugin::instance()->rules()->for_course( $course_id ),
			'The same course listed twice is a slip, and should be quietly collapsed.'
		);
	}

	public function test_saving_replaces_rather_than_appends(): void {
		$first     = $this->seeder->course( 'First requirement' );
		$second    = $this->seeder->course( 'Second requirement' );
		$course_id = $this->seeder->course( 'Replacement host' );

		$this->seeder->require_courses( $course_id, array( $first ) );
		$this->seeder->require_courses( $course_id, array( $second ) );

		$rules = Plugin::instance()->rules()->for_course( $course_id );

		$this->assertCount( 1, $rules );
		$this->assertSame( $second, $rules[0]->source_id );
	}

	public function test_dependents_are_queryable_in_both_directions(): void {
		$world = $this->scenarios->linear_chain( 3 );
		list( $first, $second, $third ) = $world['courses'];

		$this->assertSame( array( $second ), Plugin::instance()->rules()->dependents_of( $first ) );
		$this->assertSame( array( $third ), Plugin::instance()->rules()->dependents_of( $second ) );
		$this->assertSame( array(), Plugin::instance()->rules()->dependents_of( $third ) );
	}

	public function test_a_non_course_source_id_is_refused(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$result = RuleInput::parse(
			array(
				'enabled' => true,
				'rules'   => array( array( 'rule_type' => 'course_completed', 'source_id' => $page_id ) ),
			),
			Plugin::instance()->tutor()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'tlp_invalid_source_course', $result->get_error_code() );
	}

	public function test_a_missing_source_id_is_refused(): void {
		$result = RuleInput::parse(
			array(
				'enabled' => true,
				'rules'   => array( array( 'rule_type' => 'course_completed', 'source_id' => 99999999 ) ),
			),
			Plugin::instance()->tutor()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'tlp_invalid_source_course', $result->get_error_code() );
	}
}
