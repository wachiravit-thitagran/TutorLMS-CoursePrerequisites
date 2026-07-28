<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SpaceWork\TutorLearningPaths\Domain\Graph\DependencyGraph;

final class DependencyGraphTest extends TestCase {

	public function test_a_straight_chain_is_acyclic(): void {
		$graph = new DependencyGraph();
		$graph->add_dependency( 2, 1 ); // 2 requires 1.
		$graph->add_dependency( 3, 2 );

		$this->assertFalse( $graph->has_cycle() );
	}

	public function test_a_two_course_loop_is_detected(): void {
		$graph = new DependencyGraph();
		$graph->add_dependency( 2, 1 );
		$graph->add_dependency( 1, 2 );

		$cycle = $graph->find_cycle();

		$this->assertNotEmpty( $cycle );
		$this->assertSame( $cycle[0], end( $cycle ), 'A cycle must close on itself.' );
	}

	public function test_a_longer_loop_is_detected(): void {
		$graph = new DependencyGraph();
		$graph->add_dependency( 2, 1 );
		$graph->add_dependency( 3, 2 );
		$graph->add_dependency( 1, 3 );

		$this->assertTrue( $graph->has_cycle() );
	}

	public function test_a_diamond_is_not_a_cycle(): void {
		// 1 unlocks both 2 and 3; 4 requires both. Common, and perfectly legal.
		$graph = new DependencyGraph();
		$graph->add_dependency( 2, 1 );
		$graph->add_dependency( 3, 1 );
		$graph->add_dependency( 4, 2 );
		$graph->add_dependency( 4, 3 );

		$this->assertFalse( $graph->has_cycle() );
	}

	public function test_zero_ids_are_ignored(): void {
		$graph = new DependencyGraph();
		$graph->add_dependency( 0, 1 );
		$graph->add_dependency( 1, 0 );

		$this->assertFalse( $graph->has_cycle() );
	}

	public function test_descendants_walks_the_whole_subtree(): void {
		$graph = new DependencyGraph();
		$graph->add_dependency( 2, 1 );
		$graph->add_dependency( 3, 2 );
		$graph->add_dependency( 4, 2 );

		$descendants = $graph->descendants( 1 );
		sort( $descendants );

		$this->assertSame( array( 2, 3, 4 ), $descendants );
	}
}
