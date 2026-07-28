<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Admin;

use SpaceWork\TutorLearningPaths\Infrastructure\TutorLMS\TutorAdapterInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Searchable course picker.
 *
 * A select element with every course in it is unusable past a few hundred
 * courses and slow to render well before that, so the picker queries on demand.
 */
final class CourseSearchAjax {

	private const NONCE = 'tlp_course_rules';

	public function __construct( private readonly TutorAdapterInterface $tutor ) {}

	public function register(): void {
		add_action( 'wp_ajax_tlp_search_courses', array( $this, 'handle' ) );
	}

	public function handle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'tutor-learning-paths' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['term'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$exclude = isset( $_POST['exclude'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['exclude'] ) ) : array();

		$args = array(
			'post_type'              => $this->tutor->course_post_type(),
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page'         => 20,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'post__not_in'           => array_values( array_filter( $exclude ) ),
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		// A bare number is almost always somebody pasting a course ID.
		if ( ctype_digit( $term ) ) {
			$args['p'] = (int) $term;
		} elseif ( '' !== $term ) {
			$args['s'] = $term;
		}

		$query   = new \WP_Query( $args );
		$results = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! self::can_include( $post ) ) {
				continue;
			}

			$results[] = array(
				'id'     => (int) $post->ID,
				'title'  => get_the_title( $post ),
				'status' => (string) $post->post_status,
				'author' => get_the_author_meta( 'display_name', (int) $post->post_author ),
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Never reveal a non-public course the current user cannot read.
	 */
	public static function can_include( \WP_Post $post ): bool {
		if ( 'publish' === $post->post_status ) {
			return true;
		}

		return current_user_can( 'read_post', $post->ID ) || current_user_can( 'edit_post', $post->ID );
	}
}
