<?php
/**
 * MCP / WordPress Abilities integration.
 *
 * @package SpaceWork\TutorLearningPaths
 */

declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths;

defined( 'ABSPATH' ) || exit;

final class MCP {
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	public static function register_category(): void {
		wp_register_ability_category(
			'tutorlms-prerequisites',
			array(
				'label'       => 'Tutor Learning Paths',
				'description' => 'Course prerequisites and access evaluation abilities.',
			)
		);
	}

	public static function register_abilities(): void {
		wp_register_ability(
			'tutorlms-prerequisites/check-access',
			array(
				'label'       => 'Check Course Access',
				'description' => 'Evaluate whether a learner may access a course in a specific context.',
				'category'    => 'tutorlms-prerequisites',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'user_id'   => array( 'type' => 'integer', 'minimum' => 0 ),
						'course_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'context'   => array( 'type' => 'string', 'default' => 'view' ),
					),
					'required' => array( 'user_id', 'course_id' ),
				),
				'execute_callback'    => array( self::class, 'check_access' ),
				'permission_callback' => array( self::class, 'can_read_user' ),
				'meta'                => self::meta( true ),
			)
		);

		wp_register_ability(
			'tutorlms-prerequisites/get-prerequisites',
			array(
				'label'       => 'Get Course Prerequisites',
				'description' => 'Return course IDs required before a course.',
				'category'    => 'tutorlms-prerequisites',
				'input_schema' => self::course_schema(),
				'execute_callback'    => static function ( array $input ) {
					return array( 'course_id' => (int) $input['course_id'], 'prerequisites' => tlp_get_course_prerequisites( (int) $input['course_id'] ) );
				},
				'permission_callback' => static function () { return current_user_can( 'read' ); },
				'meta'                => self::meta( true ),
			)
		);

		wp_register_ability(
			'tutorlms-prerequisites/get-dependent-courses',
			array(
				'label'       => 'Get Dependent Courses',
				'description' => 'Return courses that depend on the supplied course.',
				'category'    => 'tutorlms-prerequisites',
				'input_schema' => self::course_schema(),
				'execute_callback'    => static function ( array $input ) {
					return array( 'course_id' => (int) $input['course_id'], 'dependent_courses' => tlp_get_dependent_courses( (int) $input['course_id'] ) );
				},
				'permission_callback' => static function () { return current_user_can( 'read' ); },
				'meta'                => self::meta( true ),
			)
		);

		wp_register_ability(
			'tutorlms-prerequisites/flush-access-cache',
			array(
				'label'       => 'Flush Prerequisite Access Cache',
				'description' => 'Flush cached course access decisions for one learner or the entire site.',
				'category'    => 'tutorlms-prerequisites',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'user_id'    => array( 'type' => 'integer', 'minimum' => 1 ),
						'course_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					),
				),
				'execute_callback'    => array( self::class, 'flush_cache' ),
				'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
				'meta'                => self::meta( false ),
			)
		);
	}

	public static function can_read_user( array $input ): bool {
		$user_id = (int) $input['user_id'];
		return get_current_user_id() === $user_id || current_user_can( 'list_users' );
	}

	public static function check_access( array $input ): array {
		return tlp_can_user_access_course(
			(int) $input['user_id'],
			(int) $input['course_id'],
			isset( $input['context'] ) ? (string) $input['context'] : 'view'
		)->to_array();
	}

	public static function flush_cache( array $input ): array {
		$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : null;
		$course_ids = isset( $input['course_ids'] ) && is_array( $input['course_ids'] ) ? array_map( 'absint', $input['course_ids'] ) : array();
		tlp_flush_access_cache( $user_id, $course_ids );

		return array( 'flushed' => true, 'user_id' => $user_id, 'course_ids' => $course_ids );
	}

	private static function course_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'course_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			),
			'required' => array( 'course_id' ),
		);
	}

	private static function meta( bool $readonly ): array {
		return array(
			'mcp' => array( 'public' => true, 'type' => 'tool' ),
			'annotations' => array(
				'readonly' => $readonly,
				'destructive' => false,
				'idempotent' => true,
				'openWorldHint' => false,
			),
		);
	}
}
