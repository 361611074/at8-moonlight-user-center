<?php
/**
 * Account dashboard: profile editor.
 *
 * The email address is shown for reference but is not editable here; changing
 * it requires the WordPress confirmation flow.
 *
 * Overridden by: wp-content/themes/<theme>/at8-moonlight-user-center/account-profile.php
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

$at8mluc_phone = (string) get_user_meta( $at8mluc_user->ID, 'at8mluc_phone', true );
?>
<div class="at8mluc-card at8mluc-profile">

	<h3 class="at8mluc-card-title"><?php esc_html_e( 'Profile', 'at8-moonlight-user-center' ); ?></h3>

	<form class="at8mluc-form" method="post" action="<?php echo esc_url( at8mluc_get_account_url() ); ?>"
		data-at8mluc-action="at8mluc_update_profile"
		data-at8mluc-nonce="<?php echo esc_attr( wp_create_nonce( AT8MLUC_Profile::NONCE_ACTION ) ); ?>"
		data-at8mluc-notice="at8mluc-profile-notice">

		<p class="at8mluc-field">
			<label for="at8mluc-profile-display"><?php esc_html_e( 'Display name', 'at8-moonlight-user-center' ); ?></label>
			<input type="text" id="at8mluc-profile-display" name="display_name" maxlength="100" required
				value="<?php echo esc_attr( $at8mluc_user->display_name ); ?>" />
			<span class="at8mluc-field-hint">
				<?php esc_html_e( 'This is the name shown publicly with your posts and comments.', 'at8-moonlight-user-center' ); ?>
			</span>
		</p>

		<p class="at8mluc-field">
			<label for="at8mluc-profile-nickname"><?php esc_html_e( 'Nickname', 'at8-moonlight-user-center' ); ?></label>
			<input type="text" id="at8mluc-profile-nickname" name="nickname" maxlength="100"
				value="<?php echo esc_attr( $at8mluc_user->nickname ); ?>" />
		</p>

		<div class="at8mluc-field-group">
			<p class="at8mluc-field">
				<label for="at8mluc-profile-first"><?php esc_html_e( 'First name', 'at8-moonlight-user-center' ); ?></label>
				<input type="text" id="at8mluc-profile-first" name="first_name" maxlength="100"
					value="<?php echo esc_attr( $at8mluc_user->first_name ); ?>" />
			</p>

			<p class="at8mluc-field">
				<label for="at8mluc-profile-last"><?php esc_html_e( 'Last name', 'at8-moonlight-user-center' ); ?></label>
				<input type="text" id="at8mluc-profile-last" name="last_name" maxlength="100"
					value="<?php echo esc_attr( $at8mluc_user->last_name ); ?>" />
			</p>
		</div>

		<p class="at8mluc-field">
			<label for="at8mluc-profile-url"><?php esc_html_e( 'Website', 'at8-moonlight-user-center' ); ?></label>
			<input type="url" id="at8mluc-profile-url" name="user_url"
				value="<?php echo esc_url( $at8mluc_user->user_url ); ?>" />
		</p>

		<p class="at8mluc-field">
			<label for="at8mluc-profile-phone"><?php esc_html_e( 'Phone number', 'at8-moonlight-user-center' ); ?></label>
			<input type="tel" id="at8mluc-profile-phone" name="phone" maxlength="40"
				value="<?php echo esc_attr( $at8mluc_phone ); ?>" />
		</p>

		<p class="at8mluc-field">
			<label for="at8mluc-profile-bio"><?php esc_html_e( 'Biographical info', 'at8-moonlight-user-center' ); ?></label>
			<textarea id="at8mluc-profile-bio" name="description" rows="5"><?php echo esc_textarea( $at8mluc_user->description ); ?></textarea>
		</p>

		<p class="at8mluc-field at8mluc-field--static">
			<span class="at8mluc-field-label"><?php esc_html_e( 'Email address', 'at8-moonlight-user-center' ); ?></span>
			<span class="at8mluc-field-value"><?php echo esc_html( $at8mluc_user->user_email ); ?></span>
			<span class="at8mluc-field-hint">
				<?php esc_html_e( 'Contact an administrator to change the email address on your account.', 'at8-moonlight-user-center' ); ?>
			</span>
		</p>

		<?php
		/**
		 * Fires inside the profile form, before the submit button.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_User $at8mluc_user Current user.
		 */
		do_action( 'at8mluc_account_profile_fields', $at8mluc_user );
		?>

		<p class="at8mluc-form-notice" id="at8mluc-profile-notice" role="status" aria-live="polite" hidden></p>

		<p class="at8mluc-actions">
			<button type="submit" class="at8mluc-button"><?php esc_html_e( 'Save changes', 'at8-moonlight-user-center' ); ?></button>
		</p>
	</form>
</div>
