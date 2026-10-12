<?php
/**
 * Account dashboard: profile editor.
 *
 * The email address is shown for reference but is not editable here; changing
 * it requires the WordPress confirmation flow.
 *
 * Overridden by: wp-content/themes/<theme>/moonlight-user-center/account-profile.php
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

$mluc_phone = (string) get_user_meta( $user->ID, 'mluc_phone', true );
?>
<div class="mluc-card mluc-profile">

	<h3 class="mluc-card-title"><?php esc_html_e( 'Profile', 'moonlight-user-center' ); ?></h3>

	<form class="mluc-form" method="post" action="<?php echo esc_url( mluc_get_account_url() ); ?>"
		data-mluc-action="mluc_update_profile"
		data-mluc-nonce="<?php echo esc_attr( wp_create_nonce( MLUC_Profile::NONCE_ACTION ) ); ?>"
		data-mluc-notice="mluc-profile-notice">

		<p class="mluc-field">
			<label for="mluc-profile-display"><?php esc_html_e( 'Display name', 'moonlight-user-center' ); ?></label>
			<input type="text" id="mluc-profile-display" name="display_name" maxlength="100" required
				value="<?php echo esc_attr( $user->display_name ); ?>" />
			<span class="mluc-field-hint">
				<?php esc_html_e( 'This is the name shown publicly with your posts and comments.', 'moonlight-user-center' ); ?>
			</span>
		</p>

		<p class="mluc-field">
			<label for="mluc-profile-nickname"><?php esc_html_e( 'Nickname', 'moonlight-user-center' ); ?></label>
			<input type="text" id="mluc-profile-nickname" name="nickname" maxlength="100"
				value="<?php echo esc_attr( $user->nickname ); ?>" />
		</p>

		<div class="mluc-field-group">
			<p class="mluc-field">
				<label for="mluc-profile-first"><?php esc_html_e( 'First name', 'moonlight-user-center' ); ?></label>
				<input type="text" id="mluc-profile-first" name="first_name" maxlength="100"
					value="<?php echo esc_attr( $user->first_name ); ?>" />
			</p>

			<p class="mluc-field">
				<label for="mluc-profile-last"><?php esc_html_e( 'Last name', 'moonlight-user-center' ); ?></label>
				<input type="text" id="mluc-profile-last" name="last_name" maxlength="100"
					value="<?php echo esc_attr( $user->last_name ); ?>" />
			</p>
		</div>

		<p class="mluc-field">
			<label for="mluc-profile-url"><?php esc_html_e( 'Website', 'moonlight-user-center' ); ?></label>
			<input type="url" id="mluc-profile-url" name="user_url"
				value="<?php echo esc_url( $user->user_url ); ?>" />
		</p>

		<p class="mluc-field">
			<label for="mluc-profile-phone"><?php esc_html_e( 'Phone number', 'moonlight-user-center' ); ?></label>
			<input type="tel" id="mluc-profile-phone" name="phone" maxlength="40"
				value="<?php echo esc_attr( $mluc_phone ); ?>" />
		</p>

		<p class="mluc-field">
			<label for="mluc-profile-bio"><?php esc_html_e( 'Biographical info', 'moonlight-user-center' ); ?></label>
			<textarea id="mluc-profile-bio" name="description" rows="5"><?php echo esc_textarea( $user->description ); ?></textarea>
		</p>

		<p class="mluc-field mluc-field--static">
			<span class="mluc-field-label"><?php esc_html_e( 'Email address', 'moonlight-user-center' ); ?></span>
			<span class="mluc-field-value"><?php echo esc_html( $user->user_email ); ?></span>
			<span class="mluc-field-hint">
				<?php esc_html_e( 'Contact an administrator to change the email address on your account.', 'moonlight-user-center' ); ?>
			</span>
		</p>

		<?php
		/**
		 * Fires inside the profile form, before the submit button.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_User $user Current user.
		 */
		do_action( 'mluc_account_profile_fields', $user );
		?>

		<p class="mluc-form-notice" id="mluc-profile-notice" role="status" aria-live="polite" hidden></p>

		<p class="mluc-actions">
			<button type="submit" class="mluc-button"><?php esc_html_e( 'Save changes', 'moonlight-user-center' ); ?></button>
		</p>
	</form>
</div>
