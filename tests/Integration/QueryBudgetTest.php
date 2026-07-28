<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Guards\VisibilityGuard;
use SpaceWork\TutorLearningPaths\Plugin;

/**
 * Query budgets for the pages that render many courses at once.
 *
 * These assert a ceiling rather than an exact number. An exact count turns
 * every unrelated WordPress change into a red build; a ceiling still catches
 * the thing worth catching, which is a per-course query creeping into a loop.
 *
 * @group performance
 */
final class QueryBudgetTest extends IntegrationTestCase {

	public function test_bulk_rule_loading_is_one_query_regardless_of_size(): void {
		$world      = $this->scenarios->wide_catalogue( 40 );
		$repository = Plugin::instance()->rules();

		$queries = $this->countQueries(
			static function () use ( $repository, $world ): void {
				$repository->for_courses( $world['courses'] );
			}
		);

		$this->assertLessThanOrEqual(
			2,
			$queries,
			sprintf( 'Loading rules for %d courses took %d queries.', count( $world['courses'] ), $queries )
		);
	}

	public function test_the_archive_does_not_scale_its_queries_with_the_catalogue(): void {
		$small = $this->measure_archive( 10 );
		$large = $this->measure_archive( 40 );

		// Four times the courses must not mean four times the queries.
		$this->assertLessThan(
			$small * 2,
			$large,
			sprintf(
				'Archive queries grew from %d to %d when the catalogue grew 4x - that is an N+1.',
				$small,
				$large
			)
		);
	}

	public function test_repeated_decisions_for_one_learner_stay_cheap(): void {
		$world   = $this->scenarios->wide_catalogue( 20 );
		$student = $world['student'];
		$gate    = $this->gate();

		// Warm every decision once.
		foreach ( $world['gated'] as $course_id ) {
			$gate->evaluate( $student, (int) $course_id, AccessContext::View );
		}

		$queries = $this->countQueries(
			static function () use ( $gate, $student, $world ): void {
				foreach ( $world['gated'] as $course_id ) {
					$gate->evaluate( $student, (int) $course_id, AccessContext::View );
				}
			}
		);

		$this->assertLessThanOrEqual(
			count( $world['gated'] ),
			$queries,
			'A warm cache should cost at most one query per course, and normally none.'
		);
	}

	/**
	 * Run an archive query over a seeded catalogue and count the queries.
	 */
	private function measure_archive( int $size ): int {
		$world = $this->scenarios->wide_catalogue( $size );

		wp_set_current_user( $world['student'] );
		VisibilityGuard::rebuild_shortlist();
		wp_cache_flush();

		return $this->countQueries(
			function (): void {
				$query = new \WP_Query(
					array(
						'post_type'      => $this->seeder->course_post_type(),
						'posts_per_page' => 100,
					)
				);

				while ( $query->have_posts() ) {
					$query->the_post();
					$this->gate()->evaluate( get_current_user_id(), (int) get_the_ID(), AccessContext::View );
				}

				wp_reset_postdata();
			}
		);
	}
}
