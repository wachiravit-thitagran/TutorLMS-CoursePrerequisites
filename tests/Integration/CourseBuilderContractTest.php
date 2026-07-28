<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\HookMap;

/**
 * @group contract
 */
final class CourseBuilderContractTest extends IntegrationTestCase {

	public function test_builder_assets_are_registered_before_tutor_prints_the_builder_document(): void {
		$this->assertSame( 'tutor_before_course_builder_load', HookMap::course_builder_loaded_action() );
	}

	public function test_builder_bridge_uses_tutors_supported_additional_content_api(): void {
		$bridge = file_get_contents( TLP_DIR . 'assets/js/course-builder.js' );

		$this->assertIsString( $bridge );
		$this->assertStringContainsString( 'Tutor.CourseBuilder.Additional', $bridge );
		$this->assertStringContainsString( "registerContent( 'bottom_of_sidebar'", $bridge );
		$this->assertStringContainsString( 'tlp_get_course_rules', $bridge );
	}
}
