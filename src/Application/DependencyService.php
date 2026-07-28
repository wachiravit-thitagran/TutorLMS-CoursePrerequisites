<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Application;

use SpaceWork\TutorLearningPaths\Domain\Graph\DependencyGraph;
use SpaceWork\TutorLearningPaths\Domain\Rule\Rule;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleRegistry;
use SpaceWork\TutorLearningPaths\Infrastructure\Database\CourseRuleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the course dependency graph and guards it against cycles.
 *
 * A cycle is not a cosmetic problem: "A requires B, B requires A" makes both
 * courses permanently unreachable for every learner on the site, and nothing in
 * the learner-facing UI can explain why. It is therefore rejected at write
 * time, when there is still somebody around to fix it.
 */
final class DependencyService {

	public function __construct( private readonly CourseRuleRepository $rules ) {}

	/**
	 * The graph as currently stored.
	 */
	public function graph(): DependencyGraph {
		return $this->build( $this->rules->all_enabled() );
	}

	/**
	 * The graph as it would be if a course's rules were replaced.
	 *
	 * @param array<int, array<string, mixed>> $proposed Normalised rule payloads.
	 */
	public function graph_with( int $course_id, array $proposed ): DependencyGraph {
		$existing = array_values(
			array_filter(
				$this->rules->all_enabled(),
				static fn( Rule $rule ): bool => $rule->target_course_id !== $course_id
			)
		);

		foreach ( $proposed as $index => $payload ) {
			$existing[] = Rule::from_row(
				array(
					'id'               => 0,
					'target_course_id' => $course_id,
					'rule_type'        => (string) ( $payload['rule_type'] ?? '' ),
					'operator'         => (string) ( $payload['operator'] ?? '' ),
					'source_id'        => (int) ( $payload['source_id'] ?? 0 ),
					'value'            => wp_json_encode( $payload['value'] ?? array() ),
					'rule_group'       => (int) ( $payload['rule_group'] ?? 0 ),
					'position'         => (int) $index,
					'enabled'          => 1,
				)
			);
		}

		return $this->build( $existing );
	}

	/**
	 * Check a proposed rule set before it is written.
	 *
	 * @param array<int, array<string, mixed>> $proposed Normalised rule payloads.
	 * @return true|\WP_Error True when safe to store.
	 */
	public function validate( int $course_id, array $proposed ) {
		foreach ( $proposed as $payload ) {
			$source = (int) ( $payload['source_id'] ?? 0 );

			if ( $source > 0 && $source === $course_id ) {
				return new \WP_Error(
					'tlp_self_dependency',
					__( 'A course cannot be its own prerequisite.', 'tutor-learning-paths' )
				);
			}
		}

		$cycle = $this->graph_with( $course_id, $proposed )->find_cycle();

		if ( array() === $cycle ) {
			return true;
		}

		return new \WP_Error(
			'tlp_circular_dependency',
			sprintf(
				/* translators: %s: arrow separated list of course titles. */
				__( 'These prerequisites create a loop, so none of the courses in it could ever be unlocked: %s', 'tutor-learning-paths' ),
				$this->describe_cycle( $cycle )
			),
			array( 'cycle' => $cycle )
		);
	}

	/**
	 * Render a cycle as "Course A -> Course B -> Course A".
	 *
	 * @param int[] $cycle Course IDs.
	 */
	public function describe_cycle( array $cycle ): string {
		$titles = array_map(
			static function ( int $course_id ): string {
				$title = get_the_title( $course_id );

				return '' !== $title ? $title : sprintf( '#%d', $course_id );
			},
			$cycle
		);

		return implode( ' → ', $titles );
	}

	/**
	 * @param Rule[] $rules Rules to build from.
	 */
	private function build( array $rules ): DependencyGraph {
		$graph = new DependencyGraph();

		foreach ( $rules as $rule ) {
			$type = RuleRegistry::get( $rule->rule_type );

			if ( null === $type ) {
				continue;
			}

			foreach ( $type->dependencies( $rule ) as $source ) {
				$graph->add_dependency( $rule->target_course_id, (int) $source );
			}
		}

		return $graph;
	}
}
