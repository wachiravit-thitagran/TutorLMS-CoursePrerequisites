<?php
declare( strict_types = 1 );

namespace SpaceWork\TutorLearningPaths\Admin;

use SpaceWork\TutorLearningPaths\Infrastructure\Cache\AccessCache;
use SpaceWork\TutorLearningPaths\Support\Settings;
use SpaceWork\TutorLearningPaths\Support\Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide defaults and lifecycle policy.
 */
final class SettingsPage {

	private const NONCE = 'tlp_save_settings';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_tlp_save_settings', array( $this, 'save' ) );
	}

	public function add_page(): void {
		add_options_page(
			__( 'Tutor Learning Paths', 'tutor-learning-paths' ),
			__( 'Tutor Learning Paths', 'tutor-learning-paths' ),
			'manage_tlp_settings',
			'tutor-learning-paths',
			array( $this, 'render' )
		);
	}

	/**
	 * @param array<string, mixed> $input Untrusted settings input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$visibility = isset( $input['default_visibility'] )
			? sanitize_key( (string) $input['default_visibility'] )
			: Visibility::VISIBLE_LOCKED;
		$conflict   = isset( $input['conflict_policy'] )
			? sanitize_key( (string) $input['conflict_policy'] )
			: 'warn';

		return array(
			'enabled'             => ! empty( $input['enabled'] ),
			'default_visibility'  => Visibility::is_valid( $visibility ) ? $visibility : Visibility::VISIBLE_LOCKED,
			'default_locked_text' => isset( $input['default_locked_text'] )
				? sanitize_textarea_field( (string) $input['default_locked_text'] )
				: '',
			'admin_bypass'        => ! empty( $input['admin_bypass'] ),
			'instructor_bypass'   => ! empty( $input['instructor_bypass'] ),
			'block_purchase'      => ! empty( $input['block_purchase'] ),
			'log_level'           => 'warning',
			'cache_ttl'           => min( 86400, max( 0, isset( $input['cache_ttl'] ) ? absint( $input['cache_ttl'] ) : 900 ) ),
			'conflict_policy'     => in_array( $conflict, array( 'warn', 'disable_self' ), true ) ? $conflict : 'warn',
		);
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_tlp_settings' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'tutor-learning-paths' ), 403 );
		}

		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$input = isset( $_POST['tlp_settings'] ) ? (array) wp_unslash( $_POST['tlp_settings'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$policy = isset( $_POST['tlp_uninstall_policy'] ) ? sanitize_key( wp_unslash( (string) $_POST['tlp_uninstall_policy'] ) ) : 'keep';

		if ( ! in_array( $policy, array( 'keep', 'cache_and_logs', 'everything' ), true ) ) {
			$policy = 'keep';
		}

		Settings::update( self::sanitize( $input ) );
		update_option( 'tlp_uninstall_policy', $policy, false );
		AccessCache::flush_all();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'tutor-learning-paths',
					'updated' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_tlp_settings' ) ) {
			return;
		}

		$settings = Settings::all();
		$policy   = (string) get_option( 'tlp_uninstall_policy', 'keep' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice flag only.
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Tutor Learning Paths settings saved.', 'tutor-learning-paths' ) .
				'</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tutor Learning Paths settings', 'tutor-learning-paths' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tlp_save_settings" />
				<?php wp_nonce_field( self::NONCE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Prerequisite engine', 'tutor-learning-paths' ); ?></th>
						<td><label><input type="checkbox" name="tlp_settings[enabled]" value="1" <?php checked( $settings['enabled'] ); ?> /> <?php esc_html_e( 'Enable access rules', 'tutor-learning-paths' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="tlp-default-visibility"><?php esc_html_e( 'Default locked behaviour', 'tutor-learning-paths' ); ?></label></th>
						<td><select id="tlp-default-visibility" name="tlp_settings[default_visibility]">
							<?php foreach ( Visibility::choices() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['default_visibility'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="tlp-default-message"><?php esc_html_e( 'Default locked message', 'tutor-learning-paths' ); ?></label></th>
						<td><textarea class="large-text" rows="3" id="tlp-default-message" name="tlp_settings[default_locked_text]"><?php echo esc_textarea( (string) $settings['default_locked_text'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Bypasses', 'tutor-learning-paths' ); ?></th>
						<td>
							<label><input type="checkbox" name="tlp_settings[admin_bypass]" value="1" <?php checked( $settings['admin_bypass'] ); ?> /> <?php esc_html_e( 'Administrators', 'tutor-learning-paths' ); ?></label><br />
							<label><input type="checkbox" name="tlp_settings[instructor_bypass]" value="1" <?php checked( $settings['instructor_bypass'] ); ?> /> <?php esc_html_e( 'Course instructors', 'tutor-learning-paths' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Purchases', 'tutor-learning-paths' ); ?></th>
						<td><label><input type="checkbox" name="tlp_settings[block_purchase]" value="1" <?php checked( $settings['block_purchase'] ); ?> /> <?php esc_html_e( 'Block purchase unless the course uses “Purchasable” mode', 'tutor-learning-paths' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="tlp-cache-ttl"><?php esc_html_e( 'Access cache lifetime', 'tutor-learning-paths' ); ?></label></th>
						<td><input type="number" min="0" max="86400" id="tlp-cache-ttl" name="tlp_settings[cache_ttl]" value="<?php echo esc_attr( (string) $settings['cache_ttl'] ); ?>" /> <?php esc_html_e( 'seconds (0 disables)', 'tutor-learning-paths' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="tlp-conflict-policy"><?php esc_html_e( 'Official prerequisite conflict', 'tutor-learning-paths' ); ?></label></th>
						<td><select id="tlp-conflict-policy" name="tlp_settings[conflict_policy]">
							<option value="warn" <?php selected( $settings['conflict_policy'], 'warn' ); ?>><?php esc_html_e( 'Warn and keep this plugin active', 'tutor-learning-paths' ); ?></option>
							<option value="disable_self" <?php selected( $settings['conflict_policy'], 'disable_self' ); ?>><?php esc_html_e( 'Disable this plugin’s guards', 'tutor-learning-paths' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="tlp-uninstall-policy"><?php esc_html_e( 'On uninstall', 'tutor-learning-paths' ); ?></label></th>
						<td><select id="tlp-uninstall-policy" name="tlp_uninstall_policy">
							<option value="keep" <?php selected( $policy, 'keep' ); ?>><?php esc_html_e( 'Keep all data', 'tutor-learning-paths' ); ?></option>
							<option value="cache_and_logs" <?php selected( $policy, 'cache_and_logs' ); ?>><?php esc_html_e( 'Remove cache and logs', 'tutor-learning-paths' ); ?></option>
							<option value="everything" <?php selected( $policy, 'everything' ); ?>><?php esc_html_e( 'Remove all plugin data', 'tutor-learning-paths' ); ?></option>
						</select></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
