<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Plugin;

/**
 * @group database
 */
final class RepositoryTransactionTest extends IntegrationTestCase {

	public function test_failed_replacement_restores_the_previous_rules(): void {
		global $wpdb;

		$first  = $this->seeder->course( 'Original prerequisite' );
		$second = $this->seeder->course( 'Replacement prerequisite' );
		$target = $this->seeder->course( 'Transactional target' );

		$this->seeder->require_courses( $target, array( $first ) );

		$table = \SpaceWork\TutorLearningPaths\Infrastructure\Database\Schema::rules_table();
		$break_insert = static function ( string $query ) use ( $table ): string {
			if ( str_starts_with( ltrim( $query ), "INSERT INTO `{$table}`" ) ) {
				return 'THIS IS INTENTIONALLY INVALID SQL';
			}

			return $query;
		};

		add_filter( 'query', $break_insert );

		$result = Plugin::instance()->rules()->replace_for_course(
			$target,
			array(
				array(
					'rule_type' => 'course_completed',
					'operator'  => '',
					'source_id' => $second,
					'value'     => array(),
					'enabled'   => true,
				),
			)
		);

		remove_filter( 'query', $break_insert );

		$this->assertWPError( $result );

		$rules = Plugin::instance()->rules()->for_course( $target );

		$this->assertCount( 1, $rules );
		$this->assertSame( $first, $rules[0]->source_id );
		$this->assertNotSame( $second, $rules[0]->source_id );
	}
}
