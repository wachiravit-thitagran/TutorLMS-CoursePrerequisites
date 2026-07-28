<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Domain\Graph;

defined( 'ABSPATH' ) || exit;

/**
 * Directed graph of course dependencies, with cycle detection.
 *
 * Edges run prerequisite -> target, i.e. the direction a learner travels.
 * A cycle means at least one course can never be unlocked by anyone, so the
 * graph is checked before every write rather than after.
 */
final class DependencyGraph {

	/**
	 * Adjacency list: course ID => list of course IDs it unlocks.
	 *
	 * @var array<int, int[]>
	 */
	private array $edges = array();

	/**
	 * Record "target depends on source".
	 */
	public function add_dependency( int $target, int $source ): void {
		if ( $target <= 0 || $source <= 0 ) {
			return;
		}

		if ( ! isset( $this->edges[ $source ] ) ) {
			$this->edges[ $source ] = array();
		}

		if ( ! in_array( $target, $this->edges[ $source ], true ) ) {
			$this->edges[ $source ][] = $target;
		}

		if ( ! isset( $this->edges[ $target ] ) ) {
			$this->edges[ $target ] = array();
		}
	}

	/**
	 * Find one cycle, if any exists.
	 *
	 * Iterative depth-first search with an explicit stack: a recursive version
	 * would risk blowing the stack on a large, deeply chained catalogue.
	 *
	 * @return int[] The cycle as an ordered list of course IDs, closing back on
	 *               the first element. Empty when the graph is acyclic.
	 */
	public function find_cycle(): array {
		$state  = array(); // 0 = unvisited, 1 = on stack, 2 = done.
		$parent = array();

		foreach ( array_keys( $this->edges ) as $start ) {
			if ( ( $state[ $start ] ?? 0 ) !== 0 ) {
				continue;
			}

			$stack = array( array( $start, 0 ) );

			while ( array() !== $stack ) {
				list( $node, $index ) = end( $stack );

				if ( 0 === $index ) {
					$state[ $node ] = 1;
				}

				$neighbours = $this->edges[ $node ] ?? array();

				if ( $index < count( $neighbours ) ) {
					// Advance the cursor on the current frame before descending.
					$stack[ count( $stack ) - 1 ][1] = $index + 1;
					$next                            = $neighbours[ $index ];
					$next_state                      = $state[ $next ] ?? 0;

					if ( 1 === $next_state ) {
						return $this->build_cycle( $parent, $node, $next );
					}

					if ( 0 === $next_state ) {
						$parent[ $next ] = $node;
						$stack[]         = array( $next, 0 );
					}

					continue;
				}

				$state[ $node ] = 2;
				array_pop( $stack );
			}
		}

		return array();
	}

	public function has_cycle(): bool {
		return array() !== $this->find_cycle();
	}

	/**
	 * Walk back up the parent chain to render the cycle for a human.
	 *
	 * @param array<int, int> $parent Child => parent map.
	 * @param int             $from   Node the back edge starts at.
	 * @param int             $to     Node the back edge points to.
	 * @return int[]
	 */
	private function build_cycle( array $parent, int $from, int $to ): array {
		$path = array( $from );
		$node = $from;

		$guard = 0;
		while ( $node !== $to && isset( $parent[ $node ] ) && $guard++ < 1000 ) {
			$node   = $parent[ $node ];
			$path[] = $node;
		}

		$path = array_reverse( $path );
		$path[] = $to;

		return $path;
	}

	/**
	 * Every course reachable downstream from a course.
	 *
	 * @return int[]
	 */
	public function descendants( int $course_id ): array {
		$seen  = array();
		$queue = $this->edges[ $course_id ] ?? array();

		while ( array() !== $queue ) {
			$node = array_shift( $queue );

			if ( isset( $seen[ $node ] ) ) {
				continue;
			}

			$seen[ $node ] = true;

			foreach ( $this->edges[ $node ] ?? array() as $child ) {
				$queue[] = $child;
			}
		}

		return array_map( 'intval', array_keys( $seen ) );
	}
}
