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

$at8mluc_notice = isset( $args['notice'] ) && is_array( $args['notice'] ) ? $args['notice'] : array();
$at8mluc_title  = isset( $args['title'] ) && '' !== $args['title']
	? $args['title']
	: at8mluc_ui_label( 'heading_login', __( 'Log In', 'at8-moonlight-user-center' ) );
$at8mluc_redirect_to = isset( $args['redirect_to'] ) ? $args['redirect_to'] : at8mluc_get_account_url();
$at8mluc_user_login  = isset( $args['user_login'] ) ? $args['user_login'] : '';
?>
<div class="at8mluc-auth at8mluc-auth--login">

	<?php if ( '' !== $at8mluc_title ) : ?>
		<h2 class="at8mluc-auth-title"><?php echo esc_html( $at8mluc_title ); ?></h2>
	<?php endif; ?>

	<?php at8mluc_get_template( 'parts/notice', array( 'notice' => $at8mluc_notice ) ); ?>

	<form class="at8mluc-form" method="post" action="<?php echo esc_url( at8mluc_get_login_url() ); ?>"
		data-at8mluc-action="at8mluc_login"
		data-at8mluc-nonce="<?php echo esc_attr( wp_create_nonce( AT8MLUC_Login::NONCE_ACTION ) ); ?>"
		data-at8mluc-notice="at8mluc-login-notice">

		<p class="at8mluc-field">
			<label for="at8mluc-login-user"><?php esc_html_e( 'Username or Email Address', 'at8-moonlight-user-center' ); ?></label>
			<input type="text" id="at8mluc-login-user" name="user_login" autocomplete="username" required
				value="<?php echo esc_attr( $at8mluc_user_login ); ?>" />
		</p>

		<p class="at8mluc-field">
			<label for="at8mluc-login-pass"><?php esc_html_e( 'Password', 'at8-moonlight-user-center' ); ?></label>
			<input type="password" id="at8mluc-login-pass" name="password" autocomplete="current-password" required />
		</p>

		<p class="at8mluc-field at8mluc-field--checkbox">
			<label for="at8mluc-login-remember">
				<input type="checkbox" id="at8mluc-login-remember" name="remember" value="1" />
				<?php esc_html_e( 'Remember me', 'at8-moonlight-user-center' ); ?>
			</label>
		</p>

		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $at8mluc_redirect_to ); ?>" />


		<p class="at8mluc-form-notice" id="at8mluc-login-notice" role="status" aria-live="polite" hidden></p>

		<p class="at8mluc-actions">
			<button type="submit" class="at8mluc-button"><?php esc_html_e( 'Log In', 'at8-moonlight-user-center' ); ?></button>
		</p>
	</form>

	<?php
	/*
	 * OAuth failures leave a short-lived notice cookie behind before the
	 * redirect, so this page can show a message without reading request
	 * input. Only known codes are rendered.
	 */
	$at8mluc_oauth_error = isset( $_COOKIE['at8mluc_oauth_notice'] ) ? sanitize_key( wp_unslash( $_COOKIE['at8mluc_oauth_notice'] ) ) : '';

	if ( '' !== $at8mluc_oauth_error && ! headers_sent() ) {
		setcookie(
			'at8mluc_oauth_notice',
			'',
			array(
				'expires'  => time() - 3600,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	$at8mluc_oauth_messages = array(
		'state'          => __( 'Security check failed, please try again.', 'at8-moonlight-user-center' ),
		'cancelled'      => __( 'Authorization was cancelled, please retry or use another method.', 'at8-moonlight-user-center' ),
		'token'          => __( 'Could not sign you in with that provider, please try again.', 'at8-moonlight-user-center' ),
		'profile'        => __( 'Could not read your profile from that provider, please try again.', 'at8-moonlight-user-center' ),
		'unsupported'    => __( 'That sign-in method is not supported.', 'at8-moonlight-user-center' ),
		'register_closed' => __( 'Registration is closed and no matching account was found.', 'at8-moonlight-user-center' ),
		'register_fail'  => __( 'Account creation failed, please try again.', 'at8-moonlight-user-center' ),
	);
	if ( '' !== $at8mluc_oauth_error && isset( $at8mluc_oauth_messages[ $at8mluc_oauth_error ] ) ) :
		?>
		<p class="at8mluc-form-notice at8mluc-form-notice--error" role="alert">
			<?php echo esc_html( $at8mluc_oauth_messages[ $at8mluc_oauth_error ] ); ?>
		</p>
	<?php endif; ?>

	<?php
	/**
	 * Fires at the bottom of the login form, after the submit button.
	 *
	 * Social login buttons render here (see includes/class-oauth.php).
	 *
	 * @since 2.0.0
	 */
	do_action( 'at8mluc_login_form_bottom' );
	?>

	<ul class="at8mluc-auth-links">
		<?php if ( AT8MLUC_Register::is_enabled() ) : ?>
			<li>
				<a href="<?php echo esc_url( at8mluc_get_register_url() ); ?>">
					<?php esc_html_e( 'Create an account', 'at8-moonlight-user-center' ); ?>
				</a>
			</li>
		<?php endif; ?>
		<li>
			<a href="<?php echo esc_url( at8mluc_get_lostpassword_url() ); ?>">
				<?php esc_html_e( 'Forgot your password?', 'at8-moonlight-user-center' ); ?>
			</a>
		</li>
	</ul>
</div>
