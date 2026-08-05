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
 * Not every name is a hook and not every name is Tutor's, so the evidence comes
 * in kinds - see the KIND_* constants. Two of them earn a word here:
 *
 * - A name that only exists from a given Tutor version (`tutor_can_purchase_course`,
 *   4.0.0 and up) is expressed as a floor, and *below* the floor the contract
 *   asserts the name is absent rather than skipping the leg. A skipped
 *   assertion looks like coverage and is not.
 * - A name owned by another project (WooCommerce) cannot be found in Tutor's
 *   source at all, so it is pinned to the value that was read out of that
 *   project. Editing it in `HookMap` fails here until it is edited here too,
 *   which is the prompt to go and re-read that project's source.
 *
 * What this test does *not* prove is that a hook is reached on the page we want
 * it on. `tests/Integration/CourseLockNoticeTest.php` covers that, against the
 * one Tutor version the integration matrix installs.
 *
 * Nor can it say anything about a name that never reached `HookMap` - that is
 * `PluginHookSourceTest`, in this same suite.
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
	 * The name is one of Tutor's request actions, reached as `tutor_action_<name>`.
	 */
	private const KIND_TUTOR_ACTION = 'tutor_action';

	/**
	 * The name is a REST route fragment, matched against a requested route.
	 */
	private const KIND_REST = 'rest';

	/**
	 * The name is a Tutor-owned string the guards read rather than bind to - a
	 * post meta key, a request field.
	 */
	private const KIND_LITERAL = 'literal';

	/**
	 * The name belongs to a project other than Tutor, so no amount of reading
	 * Tutor's source can confirm it.
	 */
	private const KIND_EXTERNAL = 'external';

	/**
	 * Names owned by somebody other than Tutor, pinned to the value that was
	 * read out of that project's source.
	 *
	 * Only WooCommerce so far. `woocommerce_add_to_cart_validation` is applied in
	 * `includes/class-wc-form-handler.php` and `includes/class-wc-ajax.php`,
	 * confirmed against woocommerce/woocommerce trunk (11.1.0-dev, August 2026);
	 * WooCommerce has carried it since 1.x and does not rename public hooks
	 * without a deprecation shim, which is why there is no WooCommerce version
	 * axis in CI to match the Tutor one - the name is stable in a way Tutor's are
	 * demonstrably not.
	 *
	 * Pinning is a change detector, and deliberately so: it means nobody can edit
	 * the name in `HookMap` without editing it here too, and editing it here is
	 * the prompt to go and check WooCommerce's source again.
	 *
	 * @var array<string, array{owner: string, names: string[]}>
	 */
	private const EXTERNAL_NAMES = array(
		'woo_add_to_cart_filter' => array(
			'owner' => 'WooCommerce',
			'names' => array( 'woocommerce_add_to_cart_validation' ),
		),
	);

	/**
	 * The Tutor release that introduced the native purchase gate.
	 *
	 * `tutor_can_purchase_course` first appears in 4.0.0 (present in
	 * v4.0.0-rc.2, absent from 3.9.12). Lower this if a 3.x build ever back-ports
	 * it - `test_the_native_purchase_path_has_a_gate_on_every_supported_tutor`
	 * will say so.
	 */
	private const PURCHASE_GATE_SINCE = '4.0.0';

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
	 * The optional third element is the first Tutor release that has the name.
	 * Below it the contract asserts the name is *absent* rather than skipping,
	 * because a skipped leg of the matrix carries no information at all.
	 *
	 * @return array<string, array{0: string, 1: string, 2?: string}>
	 */
	public static function accessors(): array {
		return array(
			'course page notice'   => array( 'course_page_notice_actions', self::KIND_ACTION ),
			'before enrol'         => array( 'before_enrol_actions', self::KIND_ACTION ),
			'enrolment ajax'       => array( 'enrolment_ajax_actions', self::KIND_AJAX ),
			'enrolment rest'       => array( 'enrolment_rest_fragments', self::KIND_REST ),
			'enrolment data'       => array( 'enrolment_data_filters', self::KIND_ACTION ),
			'after enrol'          => array( 'after_enrol_actions', self::KIND_ACTION ),
			'course completed'     => array( 'course_completed_actions', self::KIND_ACTION ),
			'course builder ready' => array( 'course_builder_loaded_action', self::KIND_ACTION ),
			'woo add to cart'      => array( 'woo_add_to_cart_filter', self::KIND_EXTERNAL ),
			'native purchase gate' => array( 'purchase_gate_filters', self::KIND_ACTION, self::PURCHASE_GATE_SINCE ),
			'native checkout'      => array( 'native_checkout_actions', self::KIND_TUTOR_ACTION ),
			'checkout object ids'  => array( 'checkout_object_ids_field', self::KIND_LITERAL ),
			'course product meta'  => array( 'course_product_meta_key', self::KIND_LITERAL ),
		);
	}

	/**
	 * The check this whole file exists for.
	 *
	 * @dataProvider accessors
	 */
	public function test_the_installed_tutor_still_provides_at_least_one_candidate( string $accessor, string $kind, string $since = '' ): void {
		$candidates = self::candidates_for( $accessor );

		$this->assertNotEmpty(
			$candidates,
			sprintf( 'HookMap::%s() returned nothing to check.', $accessor )
		);

		if ( self::KIND_EXTERNAL === $kind ) {
			$this->assert_external_names_are_unchanged( $accessor, $candidates );

			return;
		}

		$evidence = $this->evidence_for( $kind, $candidates );

		if ( '' !== $since && ! $this->tutor_is_at_least( $since ) ) {
			$this->assertSame(
				array(),
				array_filter( $evidence ),
				$this->explain_unexpected_presence( $accessor, $since, $evidence )
			);

			return;
		}

		$this->assertNotEmpty(
			array_filter( $evidence ),
			$this->explain_failure( $accessor, $kind, $evidence )
		);
	}

	/**
	 * Both halves of the native purchase path, stated together.
	 *
	 * The two accessors involved are not alternatives that happen to overlap;
	 * they cover different ranges of Tutor and the split is the whole point.
	 * Tutor 4.0 asks `tutor_can_purchase_course` before it will put a course in a
	 * cart, render the checkout page or take a payment. Tutor 3.x asks nothing,
	 * so the only thing left to guard there is the checkout submit itself.
	 *
	 * Asserting the 3.x absence rather than shrugging at it is what stops
	 * somebody "fixing" a red 3.0.2 leg by deleting the older gate.
	 */
	public function test_the_native_purchase_path_has_a_gate_on_every_supported_tutor(): void {
		$submit = array_filter(
			$this->evidence_for( self::KIND_TUTOR_ACTION, HookMap::native_checkout_actions() )
		);

		$this->assertNotEmpty(
			$submit,
			sprintf(
				"Tutor %s wires none of HookMap::native_checkout_actions() as tutor_action_<name>.\n"
				. 'That leaves the native checkout with no gate at all on this build - the exact hole '
				. 'the tutor_before_checkout_process bug left open. Find the name Tutor now dispatches '
				. 'the checkout submit under before touching anything else.',
				self::tutor()->version()
			)
		);

		$gate = array_filter(
			$this->evidence_for( self::KIND_ACTION, HookMap::purchase_gate_filters() )
		);

		if ( $this->tutor_is_at_least( self::PURCHASE_GATE_SINCE ) ) {
			$this->assertNotEmpty(
				$gate,
				sprintf(
					"Tutor %s is %s or newer but declares none of HookMap::purchase_gate_filters().\n"
					. 'Tutor dropped or renamed its purchase gate. Until it is replaced the native path '
					. 'falls back to refusing at the checkout submit, which still works but reports a '
					. 'generic failure instead of explaining which prerequisite is missing.',
					self::tutor()->version(),
					self::PURCHASE_GATE_SINCE
				)
			);

			return;
		}

		$this->assertSame(
			array(),
			$gate,
			sprintf(
				"Tutor %s predates %s and should not have a purchase gate, but one of\n"
				. "HookMap::purchase_gate_filters() is declared in its source.\n"
				. 'Good news, not bad: lower HookContractTest::PURCHASE_GATE_SINCE to the version that '
				. 'back-ported it and record the new floor in docs/tutor-hook-matrix.md.',
				self::tutor()->version(),
				self::PURCHASE_GATE_SINCE
			)
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

				case self::KIND_TUTOR_ACTION:
					$evidence[ $candidate ] = self::tutor()->registers_tutor_action( $candidate );
					break;

				case self::KIND_LITERAL:
					$evidence[ $candidate ] = self::tutor()->uses_literal( $candidate );
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

			case self::KIND_TUTOR_ACTION:
				return 'a Tutor request action reachable as tutor_action_<name>';

			case self::KIND_REST:
				return 'a REST namespace Tutor registers';

			case self::KIND_LITERAL:
				return 'a quoted string literal in Tutor source';

			default:
				return 'a name declared with do_action() or apply_filters()';
		}
	}

	/**
	 * Hold a name owned by another project to the value this contract pins.
	 *
	 * @param string[] $candidates Names HookMap returned.
	 */
	private function assert_external_names_are_unchanged( string $accessor, array $candidates ): void {
		$this->assertArrayHasKey(
			$accessor,
			self::EXTERNAL_NAMES,
			sprintf(
				"HookMap::%s() is marked as owned by another project, but HookContractTest::EXTERNAL_NAMES\n"
				. 'does not say which one or what the name should be.',
				$accessor
			)
		);

		$pinned = self::EXTERNAL_NAMES[ $accessor ];

		$this->assertSame(
			$pinned['names'],
			$candidates,
			sprintf(
				"HookMap::%s() no longer matches the name pinned for %s.\n\n"
				. "Pinned:   %s\n"
				. "HookMap:  %s\n\n"
				. "Reading Tutor's source cannot settle this, because %s owns the name. Confirm the new\n"
				. "one against %s's source, update HookContractTest::EXTERNAL_NAMES to match, and record\n"
				. 'what you checked it against in docs/tutor-hook-matrix.md.',
				$accessor,
				$pinned['owner'],
				implode( ', ', $pinned['names'] ),
				implode( ', ', $candidates ),
				$pinned['owner'],
				$pinned['owner']
			)
		);
	}

	/**
	 * Whether the Tutor under test is at least a given version.
	 *
	 * @throws \RuntimeException When the installed Tutor does not state a version.
	 */
	private function tutor_is_at_least( string $floor ): bool {
		$version = self::tutor()->version();

		if ( 'unknown' === $version ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A CLI message, never rendered to a browser.
				'Cannot decide whether ' . self::tutor()->dir() . ' is at least Tutor ' . $floor
				. ' because its plugin header states no version. Fix that before trusting any '
				. 'version-aware expectation in this suite.'
			);
		}

		return version_compare( $version, $floor, '>=' );
	}

	/**
	 * Turn "we said this version would not have it, and it does" into advice.
	 *
	 * @param array<string, string> $evidence Candidate => `path:line` or ''.
	 */
	private function explain_unexpected_presence( string $accessor, string $since, array $evidence ): string {
		$lines = array();

		foreach ( array_filter( $evidence ) as $candidate => $where ) {
			$lines[] = sprintf( '  - %s: %s', $candidate, $where );
		}

		return sprintf(
			"HookMap::%s() is documented as existing only from Tutor %s, but Tutor %s already has it:\n%s\n\n"
			. "That is a widening, not a break. Lower the floor for this accessor in\n"
			. 'HookContractTest::accessors() and update docs/tutor-hook-matrix.md.',
			$accessor,
			$since,
			self::tutor()->version(),
			implode( "\n", $lines )
		);
	}

	private static function tutor(): TutorSource {
		if ( null === self::$tutor ) {
			self::$tutor = TutorSource::locate();
		}

		return self::$tutor;
	}
}
