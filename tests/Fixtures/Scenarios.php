<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Fixtures;

use SpaceWork\TutorLearningPaths\Support\Visibility;

/**
 * Named seed shapes.
 *
 * Each method builds one recognisable arrangement of courses and returns the
 * IDs a test needs to assert against. Tests describe behaviour; scenarios
 * describe worlds. Keeping them apart is what stops every test file from
 * carrying forty lines of setup that slowly drift out of agreement.
 */
final class Scenarios {

	public function __construct( private readonly Seeder $seeder ) {}

	public function seeder(): Seeder {
		return $this->seeder;
	}

	/**
	 * A → B → C. The shape the plugin exists for.
	 *
	 * @return array{courses: int[], student: int}
	 */
	public function linear_chain( int $length = 3 ): array {
		$courses = $this->seeder->courses( $length, 'Chain' );

		for ( $i = 1; $i < $length; $i++ ) {
			$this->seeder->require_courses( $courses[ $i ], array( $courses[ $i - 1 ] ) );
		}

		return array(
			'courses' => $courses,
			'student' => $this->seeder->student(),
		);
	}

	/**
	 * One foundation, two parallel branches, a capstone requiring both.
	 *
	 * Legal, common, and the case a naive cycle detector reports as a loop.
	 *
	 * @return array{foundation: int, left: int, right: int, capstone: int, student: int}
	 */
	public function diamond(): array {
		$foundation = $this->seeder->course( 'Foundation' );
		$left       = $this->seeder->course( 'Left branch' );
		$right      = $this->seeder->course( 'Right branch' );
		$capstone   = $this->seeder->course( 'Capstone' );

		$this->seeder->require_courses( $left, array( $foundation ) );
		$this->seeder->require_courses( $right, array( $foundation ) );
		$this->seeder->require_courses( $capstone, array( $left, $right ) );

		return array(
			'foundation' => $foundation,
			'left'       => $left,
			'right'      => $right,
			'capstone'   => $capstone,
			'student'    => $this->seeder->student(),
		);
	}

	/**
	 * A target unlocked by any one of several prerequisites.
	 *
	 * @return array{target: int, options: int[], student: int}
	 */
	public function any_of( int $options = 3 ): array {
		$sources = $this->seeder->courses( $options, 'Option' );
		$target  = $this->seeder->course( 'Any-of target' );

		$this->seeder->require_courses( $target, $sources, array( 'logic' => 'ANY' ) );

		return array(
			'target'  => $target,
			'options' => $sources,
			'student' => $this->seeder->student(),
		);
	}

	/**
	 * A target requiring a minimum number of electives.
	 *
	 * @return array{target: int, electives: int[], minimum: int, student: int}
	 */
	public function at_least( int $electives = 4, int $minimum = 2 ): array {
		$sources = $this->seeder->courses( $electives, 'Elective' );
		$target  = $this->seeder->course( 'At-least target' );

		$this->seeder->require_courses(
			$target,
			$sources,
			array(
				'logic'        => 'AT_LEAST',
				'min_required' => $minimum,
			)
		);

		return array(
			'target'    => $target,
			'electives' => $sources,
			'minimum'   => $minimum,
			'student'   => $this->seeder->student(),
		);
	}

	/**
	 * An exclusion: finishing the beginner track closes the beginner-only course.
	 *
	 * @return array{target: int, excluded: int, student: int}
	 */
	public function none_of(): array {
		$excluded = $this->seeder->course( 'Advanced track' );
		$target   = $this->seeder->course( 'Beginners only' );

		$this->seeder->require_courses( $target, array( $excluded ), array( 'logic' => 'NONE' ) );

		return array(
			'target'   => $target,
			'excluded' => $excluded,
			'student'  => $this->seeder->student(),
		);
	}

	/**
	 * One locked course per visibility mode, all sharing a prerequisite.
	 *
	 * @return array{prerequisite: int, courses: array<string, int>, student: int}
	 */
	public function mixed_visibility(): array {
		$prerequisite = $this->seeder->course( 'Shared prerequisite' );
		$courses      = array();

		foreach ( array_keys( Visibility::choices() ) as $mode ) {
			$course_id = $this->seeder->course( 'Locked: ' . $mode );

			$this->seeder->require_courses(
				$course_id,
				array( $prerequisite ),
				array( 'visibility' => $mode )
			);

			$courses[ $mode ] = $course_id;
		}

		return array(
			'prerequisite' => $prerequisite,
			'courses'      => $courses,
			'student'      => $this->seeder->student(),
		);
	}

	/**
	 * A prerequisite that is deleted after the rule was stored.
	 *
	 * The interesting question is what happens next. A deleted prerequisite
	 * must not lock a course forever, and it must not silently satisfy an ANY
	 * group either.
	 *
	 * @return array{target: int, deleted_course_id: int, survivor: int, student: int}
	 */
	public function broken_reference(): array {
		$doomed   = $this->seeder->course( 'Course about to be deleted' );
		$survivor = $this->seeder->course( 'Surviving prerequisite' );
		$target   = $this->seeder->course( 'Target with a broken reference' );

		$this->seeder->require_courses( $target, array( $doomed, $survivor ) );

		// Hard delete, bypassing the trash, exactly as an admin emptying the
		// trash would.
		wp_delete_post( $doomed, true );

		return array(
			'target'            => $target,
			'deleted_course_id' => $doomed,
			'survivor'          => $survivor,
			'student'           => $this->seeder->student(),
		);
	}

	/**
	 * Two courses that would require each other, without saving the loop.
	 *
	 * @return array{first: int, second: int}
	 */
	public function cycle_attempt(): array {
		$first  = $this->seeder->course( 'Cycle A' );
		$second = $this->seeder->course( 'Cycle B' );

		$this->seeder->require_courses( $second, array( $first ) );

		return array(
			'first'  => $first,
			'second' => $second,
		);
	}

	/**
	 * A catalogue big enough for the archive query to misbehave.
	 *
	 * @return array{courses: int[], gated: int[], prerequisite: int, student: int}
	 */
	public function wide_catalogue( int $size = 40 ): array {
		$prerequisite = $this->seeder->course( 'Catalogue prerequisite' );
		$courses      = $this->seeder->courses( $size, 'Catalogue' );
		$gated        = array();

		foreach ( $courses as $index => $course_id ) {
			// Gate every other course, so the page mixes locked and open cards.
			if ( 0 !== $index % 2 ) {
				continue;
			}

			$this->seeder->require_courses(
				$course_id,
				array( $prerequisite ),
				array( 'visibility' => Visibility::HIDDEN )
			);
			$gated[] = $course_id;
		}

		return array(
			'courses'      => $courses,
			'gated'        => $gated,
			'prerequisite' => $prerequisite,
			'student'      => $this->seeder->student(),
		);
	}

	/**
	 * A gated course owned by a specific instructor.
	 *
	 * @return array{course: int, prerequisite: int, instructor: int, other_instructor: int, student: int}
	 */
	public function instructor_owned(): array {
		$instructor = $this->seeder->instructor();
		$other      = $this->seeder->instructor();

		$prerequisite = $this->seeder->course( 'Instructor prerequisite' );
		$course       = $this->seeder->course(
			'Instructor owned course',
			array( 'post_author' => $instructor )
		);

		$this->seeder->require_courses( $course, array( $prerequisite ) );

		return array(
			'course'           => $course,
			'prerequisite'     => $prerequisite,
			'instructor'       => $instructor,
			'other_instructor' => $other,
			'student'          => $this->seeder->student(),
		);
	}

	/**
	 * A gated course with real lesson and quiz content behind it.
	 *
	 * @return array{course: int, prerequisite: int, lesson: int, quiz: int, student: int}
	 */
	public function gated_content(): array {
		$prerequisite = $this->seeder->course( 'Content prerequisite' );
		$course       = $this->seeder->course( 'Course with content' );

		$lesson = $this->seeder->lesson( $course );
		$quiz   = $this->seeder->quiz( $course );

		$this->seeder->require_courses( $course, array( $prerequisite ) );

		return array(
			'course'       => $course,
			'prerequisite' => $prerequisite,
			'lesson'       => $lesson['lesson'],
			'quiz'         => $quiz,
			'student'      => $this->seeder->student(),
		);
	}
}
