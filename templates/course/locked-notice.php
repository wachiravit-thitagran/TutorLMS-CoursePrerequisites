<?php
/**
 * Notice shown on the course page when a learner cannot enter yet.
 *
 * Overridable from a theme at:
 *   your-theme/tutor-learning-paths/course/locked-notice.php
 *
 * @var \SpaceWork\TutorLearningPaths\Domain\Access\AccessResult $result Denial detail.
 * @var array<int, array{id: int, title: string, url: string, done: bool}> $checklist Prerequisite progress.
 *
 * @package SpaceWork\TutorLearningPaths
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$tlp_done  = count( array_filter( $checklist, static fn( array $item ): bool => $item['done'] ) );
$tlp_total = count( $checklist );
?>
<div class="tlp-locked-notice" role="note">

	<p class="tlp-locked-notice__headline">
		<span class="tlp-locked-notice__icon" aria-hidden="true">&#128274;</span>
		<?php echo esc_html( $result->message ); ?>
	</p>

	<?php if ( $tlp_total > 0 ) : ?>
		<p class="tlp-locked-notice__progress">
			<?php
			printf(
				/* translators: 1: completed prerequisites, 2: total prerequisites. */
				esc_html__( '%1$d of %2$d requirements met', 'tutor-learning-paths' ),
				(int) $tlp_done,
				(int) $tlp_total
			);
			?>
		</p>

		<ul class="tlp-locked-notice__list">
			<?php foreach ( $checklist as $tlp_item ) : ?>
				<li class="tlp-locked-notice__item <?php echo $tlp_item['done'] ? 'is-done' : 'is-pending'; ?>">
					<span class="tlp-locked-notice__marker" aria-hidden="true"><?php echo $tlp_item['done'] ? '&#10003;' : '&#9675;'; ?></span>
					<?php if ( '' !== $tlp_item['url'] ) : ?>
						<a href="<?php echo esc_url( $tlp_item['url'] ); ?>"><?php echo esc_html( $tlp_item['title'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $tlp_item['title'] ); ?>
					<?php endif; ?>
					<span class="screen-reader-text">
						<?php
						echo $tlp_item['done']
							? esc_html__( 'Completed', 'tutor-learning-paths' )
							: esc_html__( 'Not completed yet', 'tutor-learning-paths' );
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

</div>
