<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Contract;

use PHPUnit\Framework\TestCase;
use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\HookMap;

/**
 * Keeps `HookMap` the only place a Tutor hook name is written.
 *
 * `HookContractTest` proves that the names in `HookMap` exist. It cannot prove
 * anything at all about a name that never reached `HookMap`, and that is exactly
 * how `PurchaseGuard` came to sit on `tutor_before_checkout_process` - a name no
 * Tutor from 3.0.2 to 4.0.4 has ever declared - for as long as it did. The
 * contract suite was green the whole time, because the broken name was not in
 * the table it checks.
 *
 * So this test reads the plugin's own source instead of Tutor's, and enforces
 * the two halves of the arrangement:
 *
 * - nothing outside `HookMap` may name a Tutor hook, and
 * - nothing in `HookMap` may be a name the plugin does not actually use.
 *
 * Needs no Tutor checkout and no WordPress. It is in the hook contract suite
 * because it is the thing that makes the hook contract suite trustworthy.
 */
final class PluginHookSourceTest extends TestCase {

	/**
	 * Functions that bind a callback to, or fire, a named hook.
	 */
	private const HOOK_FUNCTIONS = array(
		'add_action',
		'add_filter',
		'remove_action',
		'remove_filter',
		'has_action',
		'has_filter',
		'do_action',
		'apply_filters',
	);

	/**
	 * Directories scanned, relative to the plugin root.
	 */
	private const SCANNED_DIRS = array( 'src', 'templates' );

	/**
	 * The file allowed to hold Tutor hook names.
	 */
	private const HOOK_MAP_FILE = 'src/Infrastructure/TutorLMS/HookMap.php';

	public function test_no_tutor_hook_name_is_written_outside_the_hook_map(): void {
		$functions = implode( '|', self::HOOK_FUNCTIONS );

		/*
		 * A *complete* quoted name starting with "tutor", i.e. one followed by
		 * the end of the argument rather than by a concatenation. Composing a
		 * prefix with a HookMap name - `'tutor_action_' . $action`, exactly like
		 * `'wp_ajax_' . $action` - is the sanctioned pattern and must not be
		 * flagged, because the part that can go stale is the variable.
		 */
		$pattern = '/\b(?:' . $functions . ')\s*\(\s*([\'"])(tutor[^\'"]*)\1\s*(?:,|\))/';

		$offenders = array();

		foreach ( $this->plugin_files() as $relative => $code ) {
			if ( self::HOOK_MAP_FILE === $relative ) {
				continue;
			}

			if ( 0 === preg_match_all( $pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$line = substr_count( substr( $code, 0, (int) $match[2][1] ), "\n" ) + 1;

				$offenders[] = sprintf( '%s:%d  %s', $relative, $line, $match[2][0] );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"A Tutor LMS hook name is hard-coded outside HookMap:\n\n  "
			. implode( "\n  ", $offenders )
			. "\n\nMove each one into src/Infrastructure/TutorLMS/HookMap.php and add the accessor to\n"
			. "HookContractTest::accessors(). A name that lives anywhere else has nothing checking it\n"
			. 'exists, which is how the plugin ended up guarding tutor_before_checkout_process - a hook '
			. 'Tutor never had.'
		);
	}

	public function test_every_hook_map_accessor_is_actually_used(): void {
		$sources = $this->plugin_files();
		unset( $sources[ self::HOOK_MAP_FILE ] );

		$haystack = implode( "\n", $sources );
		$unused   = array();

		foreach ( $this->hook_map_accessors() as $accessor ) {
			if ( false === strpos( $haystack, $accessor . '(' ) ) {
				$unused[] = $accessor;
			}
		}

		$this->assertSame(
			array(),
			$unused,
			"HookMap declares accessors nothing in src/ or templates/ calls:\n\n  "
			. implode( "\n  ", $unused )
			. "\n\nEither wire them up or delete them. An unused entry still costs a leg of the hook\n"
			. 'contract matrix, and it quietly suggests a guard exists when it does not.'
		);
	}

	/**
	 * Public static accessors declared on HookMap itself.
	 *
	 * @return string[]
	 */
	private function hook_map_accessors(): array {
		$names = array();

		foreach ( ( new \ReflectionClass( HookMap::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC ) as $method ) {
			if ( HookMap::class === $method->getDeclaringClass()->getName() ) {
				$names[] = $method->getName();
			}
		}

		sort( $names );

		return $names;
	}

	/**
	 * Every PHP file the plugin ships, keyed by path relative to the root.
	 *
	 * @return array<string, string>
	 */
	private function plugin_files(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array();

		foreach ( self::SCANNED_DIRS as $dir ) {
			$base = $root . '/' . $dir;

			if ( ! is_dir( $base ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( 'php' !== strtolower( $file->getExtension() ) ) {
					continue;
				}

				$path     = (string) $file;
				$relative = str_replace( '\\', '/', ltrim( substr( $path, strlen( $root ) ), '/\\' ) );

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file, not a URL.
				$files[ $relative ] = (string) file_get_contents( $path );
			}
		}

		ksort( $files );

		$this->assertNotEmpty(
			$files,
			'Found no plugin PHP to scan under ' . $root . '. A test that reads nothing proves nothing.'
		);

		return $files;
	}
}
