<?php
/**
 * Account dashboard: password change.
 *
 * Overridden by: wp-content/themes/<theme>/at8-moonlight-user-center/account-security.php
 *
 * @package AT8_Moonlight_User_Center
 *
 * @var array $args {
 *     @type WP_User $user Current user.
 * }
 */

defined( 'ABSPATH' ) || exit;

$user = isset( $args['user'] ) ? $args['user'] : null;

if ( ! $user instanceof WP_User ) {
	return;
}
?>
<div class="at8-mluc-card at8-mluc-security">

	<h3 class="at8-mluc-card-title"><?php esc_html_e( 'Change Password', 'at8-moonlight-user-center' ); ?></h3>

	<p class="at8-mluc-description">
		<?php esc_html_e( 'Changing your password signs you out of every other device.', 'at8-moonlight-user-center' ); ?>
	</p>

	<form class="at8-mluc-form" method="post" action="<?php echo esc_url( at8_mluc_get_account_url() ); ?>"
		data-at8-mluc-action="at8_mluc_change_password"
		data-at8-mluc-nonce="<?php echo esc_attr( wp_create_nonce( AT8_MLUC_Login::PASSWORD_NONCE_ACTION ) ); ?>"
		data-at8-mluc-notice="at8-mluc-password-notice">

		<p class="at8-mluc-field">
			<label for="at8-mluc-pass-current"><?php esc_html_e( 'Current password', 'at8-moonlight-user-center' ); ?></label>
			<input type="password" id="at8-mluc-pass-current" name="current_password" autocomplete="current-password" required />
		</p>

		<p class="at8-mluc-field">
			<label for="at8-mluc-pass-new"><?php esc_html_e( 'New password', 'at8-moonlight-user-center' ); ?></label>
			<input type="password" id="at8-mluc-pass-new" name="new_password" autocomplete="new-password" required
				minlength="8" aria-describedby="at8-mluc-pass-hint" />
			<span class="at8-mluc-field-hint" id="at8-mluc-pass-hint">
				<?php esc_html_e( 'At least 8 characters.', 'at8-moonlight-user-center' ); ?>
			</span>
		</p>

		<p class="at8-mluc-field">
			<label for="at8-mluc-pass-confirm"><?php esc_html_e( 'Repeat new password', 'at8-moonlight-user-center' ); ?></label>
			<input type="password" id="at8-mluc-pass-confirm" name="confirm_password" autocomplete="new-password" required
				minlength="8" />
		</p>

		<?php
		/**
		 * Fires inside the password form, before the submit button.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_User $user Current user.
		 */
		do_action( 'at8_mluc_account_security_fields', $user );
		?>

		<p class="at8-mluc-form-notice" id="at8-mluc-password-notice" role="status" aria-live="polite" hidden></p>

		<p class="at8-mluc-actions">
			<button type="submit" class="at8-mluc-button"><?php esc_html_e( 'Update password', 'at8-moonlight-user-center' ); ?></button>
		</p>
	</form>
</div>
