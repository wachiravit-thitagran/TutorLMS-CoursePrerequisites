<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Integration;

use SpaceWork\TutorLearningPaths\Domain\Access\AccessContext;
use SpaceWork\TutorLearningPaths\Infrastructure\Cache\AccessCache;

/**
 * A cached "no" must not outlive the reason for it.
 *
 * @group cache
 */
final class CacheInvalidationTest extends IntegrationTestCase {

	public function test_completing_a_prerequisite_invalidates_the_cached_denial(): void {
		$world = $this->scenarios->linear_chain( 2 );
		list( $first, $second ) = $world['courses'];
		$student = $world['student'];

		// Warm the cache with a denial.
		$this->assertLocked( $student, $second );

		$this->seeder->complete( $student, $first );

		$this->assertUnlocked(
			$student,
			$second,
			AccessContext::Enroll,
			'A stale denial survived the learner finishing the prerequisite.'
		);
	}

	public function test_changing_the_rules_invalidates_every_cached_decision(): void {
		$world = $this->scenarios->linear_chain( 2 );
		list( $first, $second ) = $world['courses'];
		$student = $world['student'];

		$this->assertLocked( $student, $second );

		// Drop the requirement entirely.
		$this->seeder->apply_rules( $second, array() );

		$this->assertUnlocked( $student, $second );
		$this->assertGreaterThan( 0, $first );
	}

	public function test_the_rules_version_changes_on_a_flush(): void {
		$before = AccessCache::rules_version();

		AccessCache::flush_all();

		$this->assertNotSame(
			$before,
			AccessCache::rules_version(),
			'Flushing must change the cache generation, otherwise old entries stay reachable.'
		);
	}

	public function test_the_cache_key_is_scoped_per_user_course_and_context(): void {
		$a = AccessCache::key( 1, 10, 'enroll' );
		$b = AccessCache::key( 2, 10, 'enroll' );
		$c = AccessCache::key( 1, 11, 'enroll' );
		$d = AccessCache::key( 1, 10, 'content' );

		$this->assertCount( 4, array_unique( array( $a, $b, $c, $d ) ) );
	}

	public function test_a_decision_is_actually_served_from_cache(): void {
		$world = $this->scenarios->linear_chain( 2 );
		$student = $world['student'];
		$second  = $world['courses'][1];

		$cold = $this->countQueries(
			fn() => $this->gate()->evaluate( $student, $second, AccessContext::Enroll )
		);

		$warm = $this->countQueries(
			fn() => $this->gate()->evaluate( $student, $second, AccessContext::Enroll )
		);

		$this->assertLessThanOrEqual(
			$cold,
			$warm,
			'A repeated decision should not cost more queries than the first one.'
		);
	}
}
