<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Admin;

use SpaceWork\TutorLearningPaths\Domain\Course\CourseConfig;
use SpaceWork\TutorLearningPaths\Domain\Rule\Operator;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleRegistry;
use SpaceWork\TutorLearningPaths\Domain\Rule\Types\CourseCompletionRule;
use SpaceWork\TutorLearningPaths\Domain\Rule\Types\CourseEnrollmentRule;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\TutorAdapterInterface;
use SpaceWork\TutorLearningPaths\Support\Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalises everything that arrives from the rule editor.
 *
 * Nothing here trusts a course ID, a rule type or a visibility slug from the
 * browser. An instructor who can edit their own course must not be able to
 * craft a payload that points at somebody else's, or that stores a rule type
 * the engine has never heard of.
 */
final class RuleInput {

	/**
	 * @param array<string, mixed> $raw Untrusted payload.
	 * @return array{config: CourseConfig, rules: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function parse( array $raw, TutorAdapterInterface $tutor ) {
		$logic = Operator::from_string( isset( $raw['logic'] ) ? (string) $raw['logic'] : null );

		$visibility = isset( $raw['visibility'] ) ? sanitize_key( (string) $raw['visibility'] ) : '';

		if ( '' !== $visibility && ! Visibility::is_valid( $visibility ) ) {
			return new \WP_Error(
				'tlp_invalid_visibility',
				__( 'That locked-course behaviour is not one of the available options.', 'tutor-learning-paths' )
			);
		}

		$config = CourseConfig::from_array(
			array(
				'enabled'           => ! empty( $raw['enabled'] ),
				'logic'             => $logic->value,
				'min_required'      => isset( $raw['min_required'] ) ? (int) $raw['min_required'] : 1,
				'visibility'        => $visibility,
				'locked_text'       => isset( $raw['locked_text'] ) ? wp_kses_post( (string) $raw['locked_text'] ) : '',
				'redirect_to'       => isset( $raw['redirect_to'] ) ? absint( $raw['redirect_to'] ) : 0,
				'admin_bypass'      => ! empty( $raw['admin_bypass'] ),
				'instructor_bypass' => ! empty( $raw['instructor_bypass'] ),
			)
		);

		$rules = self::parse_rules(
			isset( $raw['rules'] ) && is_array( $raw['rules'] ) ? $raw['rules'] : array(),
			$tutor
		);

		if ( is_wp_error( $rules ) ) {
			return $rules;
		}

		if ( Operator::AtLeast === $logic && $config->min_required > count( $rules ) ) {
			return new \WP_Error(
				'tlp_minimum_too_high',
				sprintf(
					/* translators: %d: number of rules configured. */
					__( 'The minimum cannot be higher than the %d prerequisites you have added, or the course could never unlock.', 'tutor-learning-paths' ),
					count( $rules )
				)
			);
		}

		return array(
			'config' => $config,
			'rules'  => $rules,
		);
	}

	/**
	 * @param array<int, mixed> $raw_rules Untrusted rule rows.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function parse_rules( array $raw_rules, TutorAdapterInterface $tutor ) {
		$parsed = array();
		$seen   = array();

		foreach ( $raw_rules as $raw_rule ) {
			if ( ! is_array( $raw_rule ) ) {
				continue;
			}

			$slug = isset( $raw_rule['rule_type'] ) ? sanitize_key( (string) $raw_rule['rule_type'] ) : '';
			$type = RuleRegistry::get( $slug );

			if ( null === $type ) {
				return new \WP_Error(
					'tlp_unknown_rule_type',
					sprintf(
						/* translators: %s: rule type slug. */
						__( 'Unknown prerequisite type "%s".', 'tutor-learning-paths' ),
						$slug
					)
				);
			}

			$normalised = $type->sanitize( $raw_rule );

			if ( is_wp_error( $normalised ) ) {
				return $normalised;
			}

			$source_id = (int) ( $normalised['source_id'] ?? 0 );

			if (
				in_array( $slug, array( CourseCompletionRule::SLUG, CourseEnrollmentRule::SLUG ), true )
				&& ! $tutor->course_exists( $source_id )
			) {
				return new \WP_Error(
					'tlp_invalid_source_course',
					__( 'The selected prerequisite is not an existing Tutor LMS course.', 'tutor-learning-paths' )
				);
			}

			if (
				in_array( $slug, array( CourseCompletionRule::SLUG, CourseEnrollmentRule::SLUG ), true )
				&& 'publish' !== get_post_status( $source_id )
				&& ! current_user_can( 'read_post', $source_id )
				&& ! current_user_can( 'edit_post', $source_id )
			) {
				return new \WP_Error(
					'tlp_source_course_forbidden',
					__( 'You are not allowed to use that course as a prerequisite.', 'tutor-learning-paths' )
				);
			}

			$fingerprint = $slug . ':' . (string) $source_id;

			// Silently drop duplicates rather than failing the save: the same
			// course listed twice is a slip, not something worth blocking on.
			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}

			$seen[ $fingerprint ] = true;

			$parsed[] = array(
				'rule_type'  => $slug,
				'operator'   => (string) ( $normalised['operator'] ?? '' ),
				'source_id'  => $source_id,
				'value'      => is_array( $normalised['value'] ?? null ) ? $normalised['value'] : array(),
				'rule_group' => 0,
				'enabled'    => true,
			);
		}

		return $parsed;
	}
}
