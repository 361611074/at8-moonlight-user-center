<?php
/**
 * Account dashboard: password change.
 *
 * Overridden by: wp-content/themes/<theme>/moonlight-user-center/account-security.php
 *
 * @package Moonlight_User_Center
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
<div class="mluc-card mluc-security">

	<h3 class="mluc-card-title"><?php esc_html_e( 'Change Password', 'moonlight-user-center' ); ?></h3>

	<p class="mluc-description">
		<?php esc_html_e( 'Changing your password signs you out of every other device.', 'moonlight-user-center' ); ?>
	</p>

	<form class="mluc-form" method="post" action="<?php echo esc_url( mluc_get_account_url() ); ?>"
		data-mluc-action="mluc_change_password"
		data-mluc-nonce="<?php echo esc_attr( wp_create_nonce( MLUC_Login::PASSWORD_NONCE_ACTION ) ); ?>"
		data-mluc-notice="mluc-password-notice">

		<p class="mluc-field">
			<label for="mluc-pass-current"><?php esc_html_e( 'Current password', 'moonlight-user-center' ); ?></label>
			<input type="password" id="mluc-pass-current" name="current_password" autocomplete="current-password" required />
		</p>

		<p class="mluc-field">
			<label for="mluc-pass-new"><?php esc_html_e( 'New password', 'moonlight-user-center' ); ?></label>
			<input type="password" id="mluc-pass-new" name="new_password" autocomplete="new-password" required
				minlength="8" aria-describedby="mluc-pass-hint" />
			<span class="mluc-field-hint" id="mluc-pass-hint">
				<?php esc_html_e( 'At least 8 characters.', 'moonlight-user-center' ); ?>
			</span>
		</p>

		<p class="mluc-field">
			<label for="mluc-pass-confirm"><?php esc_html_e( 'Repeat new password', 'moonlight-user-center' ); ?></label>
			<input type="password" id="mluc-pass-confirm" name="confirm_password" autocomplete="new-password" required
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
		do_action( 'mluc_account_security_fields', $user );
		?>

		<p class="mluc-form-notice" id="mluc-password-notice" role="status" aria-live="polite" hidden></p>

		<p class="mluc-actions">
			<button type="submit" class="mluc-button"><?php esc_html_e( 'Update password', 'moonlight-user-center' ); ?></button>
		</p>
	</form>
</div>
