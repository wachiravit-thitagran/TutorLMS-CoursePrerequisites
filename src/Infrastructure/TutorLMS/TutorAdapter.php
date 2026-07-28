<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS;

defined( 'ABSPATH' ) || exit;

/**
 * Tutor LMS adapter for the 3.x and 4.x generations.
 *
 * Every lookup follows the same shape: prefer the public helper when the
 * running Tutor build exposes it, otherwise fall back to a direct, prepared
 * query against the storage Tutor has used consistently for years. The
 * fallback exists so a helper being renamed degrades into a slower path rather
 * than into learners losing access - or worse, silently gaining it.
 *
 * Results are memoised per request: the course archive asks the same questions
 * dozens of times per page load.
 */
final class TutorAdapter implements TutorAdapterInterface {

	/** @var array<string, mixed> */
	private array $memo = array();

	public function is_available(): bool {
		return function_exists( 'tutor' ) || defined( 'TUTOR_VERSION' );
	}

	public function version(): string {
		if ( defined( 'TUTOR_VERSION' ) ) {
			return (string) constant( 'TUTOR_VERSION' );
		}

		return '';
	}

	public function course_post_type(): string {
		if ( function_exists( 'tutor' ) ) {
			$tutor = tutor();
			if ( is_object( $tutor ) && ! empty( $tutor->course_post_type ) ) {
				return (string) $tutor->course_post_type;
			}
		}

		return 'courses';
	}

	/**
	 * @return string[]
	 */
	public function content_post_types(): array {
		$types = array( 'lesson', 'topics', 'tutor_quiz', 'tutor_assignments', 'tutor_zoom_meeting', 'tutor-google-meet' );

		if ( function_exists( 'tutor' ) ) {
			$tutor = tutor();
			foreach ( array( 'lesson_post_type', 'topics_post_type', 'quiz_post_type', 'assignment_post_type' ) as $property ) {
				if ( is_object( $tutor ) && ! empty( $tutor->{$property} ) ) {
					$types[] = (string) $tutor->{$property};
				}
			}
		}

		/**
		 * Filters the post types treated as protected course content.
		 *
		 * @param string[] $types Post type slugs.
		 */
		return array_values( array_unique( (array) apply_filters( 'tlp_content_post_types', $types ) ) );
	}

	public function course_exists( int $course_id ): bool {
		if ( $course_id <= 0 ) {
			return false;
		}

		return $this->memo(
			"exists:{$course_id}",
			function () use ( $course_id ): bool {
				$post = get_post( $course_id );

				return $post instanceof \WP_Post && $post->post_type === $this->course_post_type();
			}
		);
	}

	public function course_title( int $course_id ): string {
		$title = get_the_title( $course_id );

		return '' !== $title ? $title : __( '(untitled course)', 'tutor-learning-paths' );
	}

	public function course_permalink( int $course_id ): string {
		$link = get_permalink( $course_id );

		return is_string( $link ) ? $link : '';
	}

	public function is_enrolled( int $user_id, int $course_id ): bool {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return false;
		}

		return $this->memo(
			"enrolled:{$user_id}:{$course_id}",
			function () use ( $user_id, $course_id ): bool {
				if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'is_enrolled' ) ) {
					return (bool) tutor_utils()->is_enrolled( $course_id, $user_id );
				}

				global $wpdb;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$found = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts}
						 WHERE post_type = 'tutor_enrolled'
						   AND post_parent = %d
						   AND post_author = %d
						   AND post_status = 'completed'
						 LIMIT 1",
						$course_id,
						$user_id
					)
				);

				return null !== $found;
			}
		);
	}

	public function is_course_completed( int $user_id, int $course_id ): bool {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return false;
		}

		return $this->memo(
			"completed:{$user_id}:{$course_id}",
			function () use ( $user_id, $course_id ): bool {
				if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'is_completed_course' ) ) {
					// The third argument bypasses Tutor's process-wide cache.
					// Our own memo is explicitly cleared on completion events.
					$result = tutor_utils()->is_completed_course( $course_id, $user_id, false );

					// Tutor returns a row object on success and false or null otherwise.
					return ! empty( $result );
				}

				global $wpdb;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$found = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT comment_ID FROM {$wpdb->comments}
						 WHERE comment_agent = 'TutorLMSPlugin'
						   AND comment_type = 'course_completed'
						   AND comment_post_ID = %d
						   AND user_id = %d
						 LIMIT 1",
						$course_id,
						$user_id
					)
				);

				return null !== $found;
			}
		);
	}

	public function resolve_course_id( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}

		return $this->memo(
			"course_of:{$post_id}",
			function () use ( $post_id ): int {
				$post = get_post( $post_id );

				if ( ! $post instanceof \WP_Post ) {
					return 0;
				}

				if ( $post->post_type === $this->course_post_type() ) {
					return (int) $post->ID;
				}

				if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_course_id_by_subcontent' ) ) {
					$resolved = (int) tutor_utils()->get_course_id_by_subcontent( $post_id );

					if ( $resolved > 0 ) {
						return $resolved;
					}
				}

				// Fallback: lessons and quizzes hang off a topic, topics off the course.
				$parent = (int) $post->post_parent;

				if ( $parent > 0 ) {
					$parent_post = get_post( $parent );

					if ( $parent_post instanceof \WP_Post ) {
						if ( $parent_post->post_type === $this->course_post_type() ) {
							return (int) $parent_post->ID;
						}

						$grandparent = get_post( (int) $parent_post->post_parent );

						if ( $grandparent instanceof \WP_Post && $grandparent->post_type === $this->course_post_type() ) {
							return (int) $grandparent->ID;
						}
					}
				}

				return 0;
			}
		);
	}

	public function is_instructor_of( int $user_id, int $course_id ): bool {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return false;
		}

		return $this->memo(
			"instructor:{$user_id}:{$course_id}",
			function () use ( $user_id, $course_id ): bool {
				$post = get_post( $course_id );

				if ( $post instanceof \WP_Post && (int) $post->post_author === $user_id ) {
					return true;
				}

				// Multi-instructor support, when the running build offers it.
				if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'is_instructor_of_this_course' ) ) {
					return (bool) tutor_utils()->is_instructor_of_this_course( $user_id, $course_id );
				}

				return false;
			}
		);
	}

	public function is_administrator( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return $this->memo(
			"admin:{$user_id}",
			static fn(): bool => user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_tutor' )
		);
	}

	public function official_prerequisites_active(): bool {
		return $this->memo(
			'official_prereq',
			static function (): bool {
				if ( ! function_exists( 'tutor_utils' ) || ! method_exists( tutor_utils(), 'is_addon_enabled' ) ) {
					return false;
				}

				if ( ! defined( 'TUTOR_PRO_VERSION' ) ) {
					return false;
				}

				// Addon basename, as registered by Tutor Pro. Detection only.
				return (bool) tutor_utils()->is_addon_enabled( 'tutor-prerequisites/tutor-prerequisites.php' );
			}
		);
	}

	/**
	 * Clear facts that may have changed during the current request.
	 */
	public function clear_runtime_cache(): void {
		$this->memo = array();
	}

	/**
	 * Per-request memoisation.
	 *
	 * @param string   $key      Cache key.
	 * @param callable $resolver Produces the value on a miss.
	 * @return mixed
	 */
	private function memo( string $key, callable $resolver ) {
		if ( ! array_key_exists( $key, $this->memo ) ) {
			$this->memo[ $key ] = $resolver();
		}

		return $this->memo[ $key ];
	}
}
