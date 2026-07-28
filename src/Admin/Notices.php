<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Admin;

use SpaceWork\TutorLearningPaths\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Admin notices for environment problems and add-on conflicts.
 */
final class Notices {

	public function __construct( private readonly Compatibility $compatibility ) {}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		foreach ( $this->compatibility->problems() as $problem ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Tutor Learning Paths:', 'tutor-learning-paths' ),
				esc_html( $problem )
			);
		}

		if ( ! $this->compatibility->is_satisfied() ) {
			return;
		}

		foreach ( $this->compatibility->warnings() as $warning ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Tutor Learning Paths:', 'tutor-learning-paths' ),
				esc_html( $warning )
			);
		}
	}
}
