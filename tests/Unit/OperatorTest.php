<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SpaceWork\TutorLearningPaths\Domain\Rule\Operator;

final class OperatorTest extends TestCase {

	public function test_all_requires_every_rule(): void {
		$this->assertTrue( Operator::All->is_satisfied_by( 3, 3 ) );
		$this->assertFalse( Operator::All->is_satisfied_by( 2, 3 ) );
	}

	public function test_any_requires_one_rule(): void {
		$this->assertTrue( Operator::Any->is_satisfied_by( 1, 5 ) );
		$this->assertFalse( Operator::Any->is_satisfied_by( 0, 5 ) );
	}

	public function test_at_least_honours_the_minimum(): void {
		$this->assertTrue( Operator::AtLeast->is_satisfied_by( 2, 4, 2 ) );
		$this->assertFalse( Operator::AtLeast->is_satisfied_by( 1, 4, 2 ) );
	}

	public function test_at_least_never_treats_zero_as_a_pass(): void {
		// A misconfigured minimum of 0 must not silently unlock the course.
		$this->assertFalse( Operator::AtLeast->is_satisfied_by( 0, 4, 0 ) );
	}

	public function test_none_inverts(): void {
		$this->assertTrue( Operator::None->is_satisfied_by( 0, 3 ) );
		$this->assertFalse( Operator::None->is_satisfied_by( 1, 3 ) );
	}

	public function test_an_empty_group_never_blocks(): void {
		foreach ( Operator::cases() as $operator ) {
			$this->assertTrue(
				$operator->is_satisfied_by( 0, 0 ),
				$operator->value . ' should not block when there are no rules'
			);
		}
	}

	public function test_from_string_is_forgiving_but_defaults_safely(): void {
		$this->assertSame( Operator::Any, Operator::from_string( 'any' ) );
		$this->assertSame( Operator::All, Operator::from_string( 'nonsense' ) );
		$this->assertSame( Operator::All, Operator::from_string( null ) );
	}
}
