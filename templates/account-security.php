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

$at8mluc_user = isset( $args['user'] ) ? $args['user'] : null;

if ( ! $at8mluc_user instanceof WP_User ) {
	return;
}
?>
<div class="at8mluc-card at8mluc-security">

	<h3 class="at8mluc-card-title"><?php esc_html_e( 'Change Password', 'at8-moonlight-user-center' ); ?></h3>

	<p class="at8mluc-description">
		<?php esc_html_e( 'Changing your password signs you out of every other device.', 'at8-moonlight-user-center' ); ?>
	</p>

	<form class="at8mluc-form" method="post" action="<?php echo esc_url( at8mluc_get_account_url() ); ?>"
		data-at8mluc-action="at8mluc_change_password"
		data-at8mluc-nonce="<?php echo esc_attr( wp_create_nonce( AT8MLUC_Login::PASSWORD_NONCE_ACTION ) ); ?>"
		data-at8mluc-notice="at8mluc-password-notice">

		<p class="at8mluc-field">
			<label for="at8mluc-pass-current"><?php esc_html_e( 'Current password', 'at8-moonlight-user-center' ); ?></label>
			<input type="password" id="at8mluc-pass-current" name="current_password" autocomplete="current-password" required />
		</p>

		<p class="at8mluc-field">
			<label for="at8mluc-pass-new"><?php esc_html_e( 'New password', 'at8-moonlight-user-center' ); ?></label>
			<input type="password" id="at8mluc-pass-new" name="new_password" autocomplete="new-password" required
				minlength="8" aria-describedby="at8mluc-pass-hint" />
			<span class="at8mluc-field-hint" id="at8mluc-pass-hint">
				<?php esc_html_e( 'At least 8 characters.', 'at8-moonlight-user-center' ); ?>
			</span>
		</p>

		<p class="at8mluc-field">
			<label for="at8mluc-pass-confirm"><?php esc_html_e( 'Repeat new password', 'at8-moonlight-user-center' ); ?></label>
			<input type="password" id="at8mluc-pass-confirm" name="confirm_password" autocomplete="new-password" required
				minlength="8" />
		</p>

		<?php
		/**
		 * Fires inside the password form, before the submit button.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_User $at8mluc_user Current user.
		 */
		do_action( 'at8mluc_account_security_fields', $at8mluc_user );
		?>

		<p class="at8mluc-form-notice" id="at8mluc-password-notice" role="status" aria-live="polite" hidden></p>

		<p class="at8mluc-actions">
			<button type="submit" class="at8mluc-button"><?php esc_html_e( 'Update password', 'at8-moonlight-user-center' ); ?></button>
		</p>
	</form>
</div>
