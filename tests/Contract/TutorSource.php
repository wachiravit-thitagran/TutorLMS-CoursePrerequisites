<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Tests\Contract;

/**
 * A read-only view of one installed copy of Tutor LMS.
 *
 * The hook contract needs to answer one question - "does this build of Tutor
 * still declare the name we attach to" - without booting WordPress, because the
 * point of that test is to run it against many Tutor versions cheaply. So it
 * reads Tutor's PHP source off disk and looks for the declaration itself.
 *
 * Scanning source is a deliberate choice over `has_action()`: a hook that
 * exists is only observable at runtime on the exact page that fires it, and
 * "the page that fires it" is the thing we cannot reproduce for six Tutor
 * versions in under a minute each.
 */
final class TutorSource {

	/**
	 * Directories under the plugin root with no PHP worth reading.
	 *
	 * `assets` and `v2-library` are built front-end payloads, `cypress` and
	 * `tests` are Tutor's own test code, and a hook found only there would be a
	 * false positive.
	 */
	private const SKIP_DIRS = array(
		'.git',
		'assets',
		'cypress',
		'languages',
		'node_modules',
		'stories',
		'tests',
		'v2-library',
		'vendor',
	);

	/**
	 * Tutor's PHP, read once per test class.
	 *
	 * @var array<string, string>|null Relative path => file contents.
	 */
	private ?array $files = null;

	private function __construct( private readonly string $dir ) {}

	/**
	 * Find the installed Tutor LMS, or explain how to install one.
	 *
	 * @throws \RuntimeException When no Tutor LMS can be found.
	 */
	public static function locate(): self {
		foreach ( self::candidate_dirs() as $candidate ) {
			$dir = rtrim( $candidate, '/\\' );

			if ( '' !== $dir && is_file( $dir . '/tutor.php' ) ) {
				return new self( $dir );
			}
		}

		throw new \RuntimeException(
			"Could not find an installed Tutor LMS to check the hook contract against.\n"
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A CLI message, never rendered to a browser.
			. "Looked in:\n  - " . implode( "\n  - ", self::candidate_dirs() ) . "\n\n"
			. "Install one first, for example:\n"
			. "  TUTOR_PLUGIN_DIR=\"\$PWD/.tutor\" bin/install-tutor.sh 4.0.4\n"
			. 'then point the suite at it with TUTOR_DIR, or let install-tutor.sh export it.'
		);
	}

	/**
	 * Where Tutor might be, most explicit first.
	 *
	 * @return string[]
	 */
	private static function candidate_dirs(): array {
		$dirs = array();

		// Set by bin/install-tutor.sh, which is how CI passes the checkout it
		// just made.
		$explicit = getenv( 'TUTOR_DIR' );

		if ( is_string( $explicit ) && '' !== $explicit ) {
			$dirs[] = $explicit;
		}

		// Defined by Tutor itself, so an integration run needs no configuration.
		if ( defined( 'TUTOR_FILE' ) ) {
			$dirs[] = dirname( (string) constant( 'TUTOR_FILE' ) );
		}

		$core = getenv( 'WP_CORE_DIR' );

		if ( is_string( $core ) && '' !== $core ) {
			$dirs[] = rtrim( $core, '/\\' ) . '/wp-content/plugins/tutor';
		}

		$dirs[] = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress/wp-content/plugins/tutor';

		return array_values( array_unique( $dirs ) );
	}

	public function dir(): string {
		return $this->dir;
	}

	/**
	 * The version Tutor advertises in its plugin header.
	 */
	public function version(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file, not a URL.
		$header = (string) file_get_contents( $this->dir . '/tutor.php', false, null, 0, 8192 );

		if ( preg_match( '/^[\s*#\/]*Version:\s*(.+)$/mi', $header, $matches ) ) {
			return trim( $matches[1] );
		}

		return 'unknown';
	}

	public function php_file_count(): int {
		return count( $this->files() );
	}

	/**
	 * Where Tutor declares an action or filter, if it does.
	 *
	 * Matches `do_action( 'name'` and `apply_filters( 'name'` including the
	 * `_ref_array` and `_deprecated` variants, and tolerates the call being
	 * spread over several lines - Tutor writes `apply_filters(\n\t'name',` for
	 * anything with a long payload, and a single-line grep misses those.
	 *
	 * @return string Relative `path:line` of the first declaration, or ''.
	 */
	public function declares( string $name ): string {
		$pattern = '/\b(?:do_action|apply_filters)(?:_ref_array|_deprecated)?\s*\(\s*'
			. '(?:(?:\/\/|#)[^\n]*\n\s*|\/\*.*?\*\/\s*)*'
			. '([\'"])' . preg_quote( $name, '/' ) . '\1/s';

		return $this->first_match( $pattern );
	}

	/**
	 * Where Tutor wires an admin-ajax action, if it does.
	 *
	 * The plugin attaches to `wp_ajax_<action>`, so the evidence is that string
	 * appearing in Tutor's source at all - whether Tutor writes it as a literal
	 * in `add_action()` or builds it up, the resulting hook name is the same.
	 *
	 * @return string Relative `path:line`, or ''.
	 */
	public function registers_ajax_action( string $action ): string {
		return $this->first_match( '/\bwp_ajax(?:_nopriv)?_' . preg_quote( $action, '/' ) . '\b/' );
	}

	/**
	 * Where Tutor wires one of its own request actions, if it does.
	 *
	 * Tutor dispatches `do_action( 'tutor_action_' . $tutor_action )` from a
	 * request field, so the composed name never appears in a `do_action()` call
	 * and `declares()` cannot see it. What does appear is the `add_action(
	 * 'tutor_action_<name>', ... )` that Tutor's own handler registers, which is
	 * the evidence that the action is dispatched and handled - exactly the same
	 * reasoning as `registers_ajax_action()`.
	 *
	 * @return string Relative `path:line`, or ''.
	 */
	public function registers_tutor_action( string $action ): string {
		return $this->first_match( '/\btutor_action_' . preg_quote( $action, '/' ) . '\b/' );
	}

	/**
	 * Where Tutor claims a REST namespace, if it does.
	 *
	 * @return string Relative `path:line`, or ''.
	 */
	public function registers_rest_namespace( string $rest_namespace ): string {
		return $this->uses_literal( $rest_namespace );
	}

	/**
	 * Where Tutor writes a quoted string literal, if it does.
	 *
	 * The evidence available for a name that is neither a hook nor an endpoint -
	 * a post meta key, a request field. Weaker than `declares()`, because a
	 * literal can appear for an unrelated reason, but it does catch the change
	 * that matters: a key Tutor no longer uses under that name at all.
	 *
	 * @return string Relative `path:line`, or ''.
	 */
	public function uses_literal( string $literal ): string {
		return $this->first_match( '/([\'"])' . preg_quote( $literal, '/' ) . '\1/' );
	}

	/**
	 * First file and line matching a pattern, as `relative/path.php:12`.
	 */
	private function first_match( string $pattern ): string {
		foreach ( $this->files() as $relative => $code ) {
			if ( 1 !== preg_match( $pattern, $code, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			$line = substr_count( substr( $code, 0, (int) $matches[0][1] ), "\n" ) + 1;

			return $relative . ':' . $line;
		}

		return '';
	}

	/**
	 * Every PHP file in the plugin, read once and kept.
	 *
	 * Tutor is roughly five megabytes of PHP, which is cheaper to hold in memory
	 * for the length of one test class than to re-read for every candidate name.
	 *
	 * @return array<string, string>
	 */
	private function files(): array {
		if ( null !== $this->files ) {
			return $this->files;
		}

		$this->files = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $file ): bool {
					if ( $file->isDir() ) {
						return ! in_array( $file->getFilename(), self::SKIP_DIRS, true );
					}

					return 'php' === strtolower( $file->getExtension() );
				}
			)
		);

		foreach ( $iterator as $file ) {
			$path     = (string) $file;
			$relative = ltrim( substr( $path, strlen( $this->dir ) ), '/\\' );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file, not a URL.
			$this->files[ str_replace( '\\', '/', $relative ) ] = (string) file_get_contents( $path );
		}

		ksort( $this->files );

		return $this->files;
	}
}
