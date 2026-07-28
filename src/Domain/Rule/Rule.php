<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Domain\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * A stored rule row, decoupled from its storage.
 */
final class Rule {

	/**
	 * @param int                  $id               Row ID.
	 * @param int                  $target_course_id Course being protected.
	 * @param string               $rule_type        Registered rule type slug.
	 * @param string               $operator         Comparison operator, meaning depends on the type.
	 * @param int                  $source_id        Course, quiz or path the rule points at.
	 * @param array<string, mixed> $value            Type specific payload.
	 * @param int                  $rule_group       Group index, for future nesting.
	 * @param int                  $position         Display order.
	 * @param bool                 $enabled          Whether the rule is active.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $target_course_id,
		public readonly string $rule_type,
		public readonly string $operator = '',
		public readonly int $source_id = 0,
		public readonly array $value = array(),
		public readonly int $rule_group = 0,
		public readonly int $position = 0,
		public readonly bool $enabled = true
	) {}

	/**
	 * Build from a database row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 */
	public static function from_row( array $row ): self {
		$value = array();
		if ( ! empty( $row['value'] ) && is_string( $row['value'] ) ) {
			$decoded = json_decode( $row['value'], true );
			$value   = is_array( $decoded ) ? $decoded : array( 'raw' => $row['value'] );
		}

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['target_course_id'] ?? 0 ),
			(string) ( $row['rule_type'] ?? '' ),
			(string) ( $row['operator'] ?? '' ),
			(int) ( $row['source_id'] ?? 0 ),
			$value,
			(int) ( $row['rule_group'] ?? 0 ),
			(int) ( $row['position'] ?? 0 ),
			( isset( $row['enabled'] ) ? (bool) (int) $row['enabled'] : true )
		);
	}

	/**
	 * Read a single key from the payload.
	 *
	 * @param string $key     Payload key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function value( string $key, $default = null ) {
		return $this->value[ $key ] ?? $default;
	}
}
