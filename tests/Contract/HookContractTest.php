<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Contract;

use PHPUnit\Framework\TestCase;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\HookMap;

/**
 * Asserts that the names in HookMap still exist in the installed Tutor LMS.
 *
 * Hook names are the part of this integration that moves. Every other test in
 * the repository assumes that attaching to the names in `HookMap` attaches to
 * something; this one is the only test that checks the assumption, which is why
 * CI runs it against several Tutor versions instead of one.
 *
 * It reads Tutor's source rather than booting WordPress, so a leg of the matrix
 * costs a checkout and a download - no database, no WordPress test suite. That
 * cheapness is the whole reason the version axis is affordable.
 *
 * What this test does *not* prove is that a hook is reached on the page we want
 * it on. `tests/Integration/CourseLockNoticeTest.php` covers that, against the
 * one Tutor version the integration matrix installs.
 */
final class HookContractTest extends TestCase {

	/**
	 * The name is declared with `do_action()` or `apply_filters()`.
	 */
	private const KIND_ACTION = 'action';

	/**
	 * The name is an admin-ajax action, reached as `wp_ajax_<name>`.
	 */
	private const KIND_AJAX = 'ajax';

	/**
	 * The name is a REST route fragment, matched against a requested route.
	 */
	private const KIND_REST = 'rest';

	private static ?TutorSource $tutor = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// Throwing here fails every test in the class with the same message,
		// which is the loud failure we want when Tutor is missing entirely.
		self::$tutor = TutorSource::locate();
	}

	public static function tearDownAfterClass(): void {
		self::$tutor = null;

		parent::tearDownAfterClass();
	}

	/**
	 * Every accessor on HookMap, and the kind of evidence that proves it.
	 *
	 * Adding an accessor to HookMap without adding it here fails
	 * `test_every_hookmap_accessor_is_covered_by_this_contract`.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function accessors(): array {
		return array(
			'course page notice'   => array( 'course_page_notice_actions', self::KIND_ACTION ),
			'before enrol'         => array( 'before_enrol_actions', self::KIND_ACTION ),
			'enrolment ajax'       => array( 'enrolment_ajax_actions', self::KIND_AJAX ),
			'enrolment rest'       => array( 'enrolment_rest_fragments', self::KIND_REST ),
			'course completed'     => array( 'course_completed_actions', self::KIND_ACTION ),
			'course builder ready' => array( 'course_builder_loaded_action', self::KIND_ACTION ),
		);
	}

	/**
	 * The check this whole file exists for.
	 *
	 * @dataProvider accessors
	 */
	public function test_the_installed_tutor_still_provides_at_least_one_candidate( string $accessor, string $kind ): void {
		$candidates = self::candidates_for( $accessor );

		$this->assertNotEmpty(
			$candidates,
			sprintf( 'HookMap::%s() returned nothing to check.', $accessor )
		);

		$evidence = $this->evidence_for( $kind, $candidates );

		$this->assertNotEmpty(
			array_filter( $evidence ),
			$this->explain_failure( $accessor, $kind, $evidence )
		);
	}

	/**
	 * The redundancy in the course-page notice is only real if the candidates
	 * live in different template files.
	 *
	 * A theme that overrides Tutor's templates overrides one or two of them, not
	 * all of them - that is the entire reason more than one name is listed. If a
	 * future Tutor collapses these hooks into a single template, the notice goes
	 * back to being one theme override away from disappearing, and whoever sees
	 * this fail should pick a new spread rather than delete the assertion.
	 */
	public function test_the_course_page_notice_has_a_foothold_in_more_than_one_template(): void {
		$found = array_filter(
			$this->evidence_for( self::KIND_ACTION, HookMap::course_page_notice_actions() )
		);

		$files = array_unique(
			array_map(
				static fn( string $where ): string => (string) strstr( $where, ':', true ),
				array_values( $found )
			)
		);

		foreach ( $files as $file ) {
			$this->assertStringStartsWith(
				'templates/',
				$file,
				sprintf(
					'A course-page notice hook must be declared in a Tutor template, but %s declares one. Tutor %s.',
					$file,
					self::tutor()->version()
				)
			);
		}

		$this->assertGreaterThanOrEqual(
			2,
			count( $files ),
			sprintf(
				"Tutor %s declares the course-page notice hooks in only %d template file(s):\n  %s\n\n"
				. 'Listing several hook names buys nothing if a single theme override can remove all of them. '
				. 'Choose candidates from different templates in HookMap::course_page_notice_actions().',
				self::tutor()->version(),
				count( $files ),
				implode( "\n  ", $files )
			)
		);
	}

	public function test_every_hookmap_accessor_is_covered_by_this_contract(): void {
		$declared = array();

		foreach ( ( new \ReflectionClass( HookMap::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC ) as $method ) {
			if ( HookMap::class === $method->getDeclaringClass()->getName() ) {
				$declared[] = $method->getName();
			}
		}

		$covered = array_map(
			static fn( array $entry ): string => $entry[0],
			array_values( self::accessors() )
		);

		sort( $declared );
		sort( $covered );

		$this->assertSame(
			$declared,
			$covered,
			"HookMap and this contract have drifted apart.\n"
			. "Every accessor on HookMap needs an entry in HookContractTest::accessors(),\n"
			. 'otherwise a new hook name ships with nothing checking it exists.'
		);
	}

	public function test_the_tutor_under_test_identifies_itself(): void {
		$this->assertNotSame(
			'unknown',
			self::tutor()->version(),
			'Could not read a version out of ' . self::tutor()->dir() . '/tutor.php. A failure that cannot name the Tutor version it came from is much harder to act on.'
		);

		$this->assertGreaterThan(
			100,
			self::tutor()->php_file_count(),
			'Found suspiciously little PHP in ' . self::tutor()->dir() . '. A truncated download would make every other assertion here meaningless.'
		);
	}

	/**
	 * Candidate names for an accessor, normalised to a list.
	 *
	 * @return string[]
	 */
	private static function candidates_for( string $accessor ): array {
		// A string for the single-hook accessor, an array for the rest.
		$value = HookMap::{$accessor}();

		return array_values( array_map( 'strval', is_array( $value ) ? $value : array( $value ) ) );
	}

	/**
	 * Look for each candidate in Tutor's source.
	 *
	 * @param string[] $candidates Hook names.
	 * @return array<string, string> Candidate => `path:line`, or '' when absent.
	 */
	private function evidence_for( string $kind, array $candidates ): array {
		$evidence = array();

		foreach ( $candidates as $candidate ) {
			$candidate = (string) $candidate;

			switch ( $kind ) {
				case self::KIND_AJAX:
					$evidence[ $candidate ] = self::tutor()->registers_ajax_action( $candidate );
					break;

				case self::KIND_REST:
					// A route fragment cannot be found in source the way a hook
					// name can: `rest_pre_dispatch` matches it against the route
					// a *client* asked for, and the fragments here deliberately
					// cover routes Tutor free does not register - Pro and the
					// mobile app do the enrolling over REST. What is checkable,
					// and what the guard genuinely depends on, is that the
					// namespace those fragments are scoped to is Tutor's own. A
					// namespace rename is the change that would silently switch
					// this guard off.
					$evidence[ $candidate ] = self::tutor()->registers_rest_namespace( self::namespace_of( $candidate ) );
					break;

				default:
					$evidence[ $candidate ] = self::tutor()->declares( $candidate );
					break;
			}
		}

		return $evidence;
	}

	/**
	 * `/tutor/v1/enrollments` => `tutor/v1`.
	 */
	private static function namespace_of( string $fragment ): string {
		$parts = array_values( array_filter( explode( '/', $fragment ), static fn( string $part ): bool => '' !== $part ) );

		return implode( '/', array_slice( $parts, 0, 2 ) );
	}

	/**
	 * Turn a miss into something a maintainer can act on.
	 *
	 * @param array<string, string> $evidence Candidate => `path:line` or ''.
	 */
	private function explain_failure( string $accessor, string $kind, array $evidence ): string {
		$lines = array();

		foreach ( $evidence as $candidate => $where ) {
			$lines[] = sprintf( '  - %s: %s', $candidate, '' === $where ? 'NOT FOUND' : $where );
		}

		return sprintf(
			"HookMap::%s() has no candidate that Tutor LMS %s provides.\n\n"
			. "Tutor source: %s\n"
			. "Looked for:   %s\n"
			. "%s\n\n"
			. "Tutor either renamed or dropped these. Update\n"
			. "src/Infrastructure/TutorLMS/HookMap.php::%s() with the new name, keep the old\n"
			. 'one alongside it for the versions that still have it, and record what you found in docs/tutor-hook-matrix.md.',
			$accessor,
			self::tutor()->version(),
			self::tutor()->dir(),
			self::describe_kind( $kind ),
			implode( "\n", $lines ),
			$accessor
		);
	}

	private static function describe_kind( string $kind ): string {
		switch ( $kind ) {
			case self::KIND_AJAX:
				return 'an admin-ajax action reachable as wp_ajax_<name>';

			case self::KIND_REST:
				return 'a REST namespace Tutor registers';

			default:
				return 'a name declared with do_action() or apply_filters()';
		}
	}

	private static function tutor(): TutorSource {
		if ( null === self::$tutor ) {
			self::$tutor = TutorSource::locate();
		}

		return self::$tutor;
	}
}
