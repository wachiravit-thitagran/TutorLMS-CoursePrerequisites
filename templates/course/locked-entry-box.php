<?php
/**
 * Disabled Tutor enrollment/purchase action while a prerequisite is unmet.
 *
 * Overridable from a theme at:
 *   your-theme/tutor-learning-paths/course/locked-entry-box.php
 *
 * @var bool $show_free_price Whether this is the free-enrollment card.
 *
 * @package SpaceWork\TutorLearningPaths
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>

<?php if ( $show_free_price ) : ?>
	<div class="tutor-course-single-pricing">
		<span class="tutor-fs-4 tutor-fw-bold tutor-color-black">
			<?php esc_html_e( 'Free', 'tutor' ); ?>
		</span>
	</div>
<?php endif; ?>

<div class="tutor-course-single-btn-group">
	<button type="button"
		class="tutor-btn tutor-btn-primary tutor-btn-lg tutor-btn-block tutor-mt-24 tutor-enroll-course-button tlp-enroll-locked"
		disabled="disabled"
		aria-disabled="true">
		<?php esc_html_e( 'Enrollment unavailable', 'tutor-learning-paths' ); ?>
	</button>
</div>
<p class="tutor-fs-7 tutor-color-muted tutor-mt-20 tutor-text-center tlp-enroll-locked-help">
	<?php esc_html_e( 'Complete the required courses to enroll.', 'tutor-learning-paths' ); ?>
</p>
