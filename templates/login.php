<?php
/**
 * Login form.
 *
 * Overridden by: wp-content/themes/<theme>/moonlight-user-center/login.php
 *
 * @package Moonlight_User_Center
 *
 * @var array $args Template arguments.
 */

defined( 'ABSPATH' ) || exit;

$mluc_notice = isset( $args['notice'] ) && is_array( $args['notice'] ) ? $args['notice'] : array();
$mluc_title  = isset( $args['title'] ) && '' !== $args['title']
	? $args['title']
	: mluc_ui_label( 'heading_login', __( 'Log In', 'moonlight-user-center' ) );
$mluc_redirect_to = isset( $args['redirect_to'] ) ? $args['redirect_to'] : mluc_get_account_url();
$mluc_user_login  = isset( $args['user_login'] ) ? $args['user_login'] : '';
?>
<div class="mluc-auth mluc-auth--login">

	<?php if ( '' !== $mluc_title ) : ?>
		<h2 class="mluc-auth-title"><?php echo esc_html( $mluc_title ); ?></h2>
	<?php endif; ?>

	<?php mluc_get_template( 'parts/notice', array( 'notice' => $mluc_notice ) ); ?>

	<form class="mluc-form" method="post" action="<?php echo esc_url( mluc_get_login_url() ); ?>"
		data-mluc-action="mluc_login"
		data-mluc-nonce="<?php echo esc_attr( wp_create_nonce( MLUC_Login::NONCE_ACTION ) ); ?>"
		data-mluc-notice="mluc-login-notice">

		<p class="mluc-field">
			<label for="mluc-login-user"><?php esc_html_e( 'Username or Email Address', 'moonlight-user-center' ); ?></label>
			<input type="text" id="mluc-login-user" name="user_login" autocomplete="username" required
				value="<?php echo esc_attr( $mluc_user_login ); ?>" />
		</p>

		<p class="mluc-field">
			<label for="mluc-login-pass"><?php esc_html_e( 'Password', 'moonlight-user-center' ); ?></label>
			<input type="password" id="mluc-login-pass" name="password" autocomplete="current-password" required />
		</p>

		<p class="mluc-field mluc-field--checkbox">
			<label for="mluc-login-remember">
				<input type="checkbox" id="mluc-login-remember" name="remember" value="1" />
				<?php esc_html_e( 'Remember me', 'moonlight-user-center' ); ?>
			</label>
		</p>

		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $mluc_redirect_to ); ?>" />


		<p class="mluc-form-notice" id="mluc-login-notice" role="status" aria-live="polite" hidden></p>

		<p class="mluc-actions">
			<button type="submit" class="mluc-button"><?php esc_html_e( 'Log In', 'moonlight-user-center' ); ?></button>
		</p>
	</form>

	<ul class="mluc-auth-links">
		<?php if ( MLUC_Register::is_enabled() ) : ?>
			<li>
				<a href="<?php echo esc_url( mluc_get_register_url() ); ?>">
					<?php esc_html_e( 'Create an account', 'moonlight-user-center' ); ?>
				</a>
			</li>
		<?php endif; ?>
		<li>
			<a href="<?php echo esc_url( mluc_get_lostpassword_url() ); ?>">
				<?php esc_html_e( 'Forgot your password?', 'moonlight-user-center' ); ?>
			</a>
		</li>
	</ul>
</div>
