<?php
/**
 * Prerequisite editor, rendered inside the course metabox.
 *
 * Overridable from a theme at:
 *   your-theme/tutor-learning-paths/admin/course-fields.php
 *
 * @var \SpaceWork\TutorLearningPaths\Domain\Course\CourseConfig $config Course configuration.
 * @var \SpaceWork\TutorLearningPaths\Domain\Rule\Rule[]         $rules  Stored rules.
 * @var \WP_Post                                                $post   Course being edited.
 *
 * @package SpaceWork\TutorLearningPaths
 */

declare( strict_types = 1 );

use SpaceWork\TutorLearningPaths\Domain\Rule\Operator;
use SpaceWork\TutorLearningPaths\Domain\Rule\RuleRegistry;
use SpaceWork\TutorLearningPaths\Support\Visibility;

defined( 'ABSPATH' ) || exit;
$rule_types = RuleRegistry::all();
?>
<div class="tlp-rule-editor" data-course-id="<?php echo esc_attr( (string) $post->ID ); ?>">

	<p class="tlp-field">
		<label>
			<input type="checkbox" class="tlp-enabled" <?php checked( $config->enabled ); ?> />
			<?php esc_html_e( 'Require prerequisites before learners can enrol in this course', 'tutor-learning-paths' ); ?>
		</label>
	</p>

	<div class="tlp-panel" <?php echo $config->enabled ? '' : 'hidden'; ?>>

		<p class="tlp-field">
			<label for="tlp-logic"><?php esc_html_e( 'Learners must satisfy', 'tutor-learning-paths' ); ?></label>
			<select id="tlp-logic" class="tlp-logic">
				<?php foreach ( Operator::choices() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $config->logic->value, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="tlp-minimum" <?php echo Operator::AtLeast === $config->logic ? '' : 'hidden'; ?>>
				<label for="tlp-min-required"><?php esc_html_e( 'Minimum', 'tutor-learning-paths' ); ?></label>
				<input type="number" id="tlp-min-required" class="tlp-min-required" min="1" step="1"
					value="<?php echo esc_attr( (string) $config->min_required ); ?>" />
			</span>
		</p>

		<div class="tlp-field">
			<label for="tlp-course-search"><?php esc_html_e( 'Prerequisite courses', 'tutor-learning-paths' ); ?></label>
			<input type="search" id="tlp-course-search" class="tlp-course-search widefat"
				placeholder="<?php esc_attr_e( 'Search courses by title or ID…', 'tutor-learning-paths' ); ?>"
				autocomplete="off" />
			<label for="tlp-new-rule-type"><?php esc_html_e( 'Requirement for newly selected courses', 'tutor-learning-paths' ); ?></label>
			<select id="tlp-new-rule-type" class="tlp-new-rule-type">
				<?php foreach ( $rule_types as $slug => $type ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $type->label() ); ?></option>
				<?php endforeach; ?>
			</select>
			<ul class="tlp-search-results" hidden></ul>

			<ul class="tlp-selected-courses">
				<?php foreach ( $rules as $rule ) : ?>
					<li class="tlp-selected-course" data-source-id="<?php echo esc_attr( (string) $rule->source_id ); ?>"
						data-rule-type="<?php echo esc_attr( $rule->rule_type ); ?>">
						<span class="tlp-course-title"><?php echo esc_html( get_the_title( $rule->source_id ) ); ?></span>
						<code>#<?php echo esc_html( (string) $rule->source_id ); ?></code>
						<select class="tlp-rule-type" aria-label="<?php esc_attr_e( 'Prerequisite type', 'tutor-learning-paths' ); ?>">
							<?php foreach ( $rule_types as $slug => $type ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $rule->rule_type, $slug ); ?>><?php echo esc_html( $type->label() ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button-link tlp-remove">
							<?php esc_html_e( 'Remove', 'tutor-learning-paths' ); ?>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<p class="tlp-field">
			<label for="tlp-visibility"><?php esc_html_e( 'While this course is locked', 'tutor-learning-paths' ); ?></label>
			<select id="tlp-visibility" class="tlp-visibility">
				<?php foreach ( Visibility::choices() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $config->visibility, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p class="tlp-field">
			<label for="tlp-locked-text"><?php esc_html_e( 'Message shown to locked-out learners', 'tutor-learning-paths' ); ?></label>
			<textarea id="tlp-locked-text" class="tlp-locked-text widefat" rows="2"
				placeholder="<?php esc_attr_e( 'Leave empty to use the site default.', 'tutor-learning-paths' ); ?>"><?php
					echo esc_textarea( $config->locked_text );
				?></textarea>
		</p>

		<p class="tlp-field">
			<label for="tlp-redirect-to"><?php esc_html_e( 'Blocked-user redirect post ID', 'tutor-learning-paths' ); ?></label>
			<input type="number" id="tlp-redirect-to" class="tlp-redirect-to" min="0" step="1"
				value="<?php echo esc_attr( (string) $config->redirect_to ); ?>" />
			<span class="description"><?php esc_html_e( 'Use 0 to keep the learner on the course page.', 'tutor-learning-paths' ); ?></span>
		</p>

		<p class="tlp-field">
			<label>
				<input type="checkbox" class="tlp-admin-bypass" <?php checked( $config->admin_bypass ); ?> />
				<?php esc_html_e( 'Administrators can always enter', 'tutor-learning-paths' ); ?>
			</label>
			<br />
			<label>
				<input type="checkbox" class="tlp-instructor-bypass" <?php checked( $config->instructor_bypass ); ?> />
				<?php esc_html_e( 'The course instructor can always enter', 'tutor-learning-paths' ); ?>
			</label>
		</p>

	</div>

	<p class="tlp-actions">
		<button type="button" class="button button-primary tlp-save">
			<?php esc_html_e( 'Save prerequisites', 'tutor-learning-paths' ); ?>
		</button>
		<span class="tlp-status" role="status" aria-live="polite"></span>
	</p>

</div>
