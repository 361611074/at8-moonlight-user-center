<?php
/**
 * Login form.
 *
 * Overridden by: wp-content/themes/<theme>/at8-moonlight-user-center/login.php
 *
 * @package AT8_Moonlight_User_Center
 *
 * @var array $args Template arguments.
 */

defined( 'ABSPATH' ) || exit;

$at8_mluc_notice = isset( $args['notice'] ) && is_array( $args['notice'] ) ? $args['notice'] : array();
$at8_mluc_title  = isset( $args['title'] ) && '' !== $args['title']
	? $args['title']
	: at8_mluc_ui_label( 'heading_login', __( 'Log In', 'at8-moonlight-user-center' ) );
$at8_mluc_redirect_to = isset( $args['redirect_to'] ) ? $args['redirect_to'] : at8_mluc_get_account_url();
$at8_mluc_user_login  = isset( $args['user_login'] ) ? $args['user_login'] : '';
?>
<div class="at8-mluc-auth at8-mluc-auth--login">

	<?php if ( '' !== $at8_mluc_title ) : ?>
		<h2 class="at8-mluc-auth-title"><?php echo esc_html( $at8_mluc_title ); ?></h2>
	<?php endif; ?>

	<?php at8_mluc_get_template( 'parts/notice', array( 'notice' => $at8_mluc_notice ) ); ?>

	<form class="at8-mluc-form" method="post" action="<?php echo esc_url( at8_mluc_get_login_url() ); ?>"
		data-at8-mluc-action="at8_mluc_login"
		data-at8-mluc-nonce="<?php echo esc_attr( wp_create_nonce( AT8_MLUC_Login::NONCE_ACTION ) ); ?>"
		data-at8-mluc-notice="at8-mluc-login-notice">

		<p class="at8-mluc-field">
			<label for="at8-mluc-login-user"><?php esc_html_e( 'Username or Email Address', 'at8-moonlight-user-center' ); ?></label>
			<input type="text" id="at8-mluc-login-user" name="user_login" autocomplete="username" required
				value="<?php echo esc_attr( $at8_mluc_user_login ); ?>" />
		</p>

		<p class="at8-mluc-field">
			<label for="at8-mluc-login-pass"><?php esc_html_e( 'Password', 'at8-moonlight-user-center' ); ?></label>
			<input type="password" id="at8-mluc-login-pass" name="password" autocomplete="current-password" required />
		</p>

		<p class="at8-mluc-field at8-mluc-field--checkbox">
			<label for="at8-mluc-login-remember">
				<input type="checkbox" id="at8-mluc-login-remember" name="remember" value="1" />
				<?php esc_html_e( 'Remember me', 'at8-moonlight-user-center' ); ?>
			</label>
		</p>

		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $at8_mluc_redirect_to ); ?>" />


		<p class="at8-mluc-form-notice" id="at8-mluc-login-notice" role="status" aria-live="polite" hidden></p>

		<p class="at8-mluc-actions">
			<button type="submit" class="at8-mluc-button"><?php esc_html_e( 'Log In', 'at8-moonlight-user-center' ); ?></button>
		</p>
	</form>

	<?php
	/**
	 * Fires at the bottom of the login form, after the submit button.
	 *
	 * The Pro add-on hooks here to render social login buttons.
	 *
	 * @since 2.0.0
	 */
	do_action( 'at8_mluc_login_form_bottom' );
	?>

	<ul class="at8-mluc-auth-links">
		<?php if ( AT8_MLUC_Register::is_enabled() ) : ?>
			<li>
				<a href="<?php echo esc_url( at8_mluc_get_register_url() ); ?>">
					<?php esc_html_e( 'Create an account', 'at8-moonlight-user-center' ); ?>
				</a>
			</li>
		<?php endif; ?>
		<li>
			<a href="<?php echo esc_url( at8_mluc_get_lostpassword_url() ); ?>">
				<?php esc_html_e( 'Forgot your password?', 'at8-moonlight-user-center' ); ?>
			</a>
		</li>
	</ul>
</div>
