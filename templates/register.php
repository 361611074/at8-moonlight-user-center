<?php
/**
 * Registration form.
 *
 * Overridden by: wp-content/themes/<theme>/moonlight-user-center/register.php
 *
 * @package Moonlight_User_Center
 *
 * @var array $args Template arguments.
 */

defined( 'ABSPATH' ) || exit;

$mluc_notice = isset( $args['notice'] ) && is_array( $args['notice'] ) ? $args['notice'] : array();
$mluc_title  = isset( $args['title'] ) && '' !== $args['title']
	? $args['title']
	: mluc_ui_label( 'heading_register', __( 'Create an Account', 'moonlight-user-center' ) );
$mluc_user_login = isset( $args['user_login'] ) ? $args['user_login'] : '';
$mluc_email      = isset( $args['email'] ) ? $args['email'] : '';

if ( ! MLUC_Register::is_enabled() ) :
	?>
	<p class="mluc-message mluc-message--info">
		<?php esc_html_e( 'Registration is currently disabled on this site.', 'moonlight-user-center' ); ?>
	</p>
	<?php
	return;
endif;
?>
<div class="mluc-auth mluc-auth--register">

	<?php if ( '' !== $mluc_title ) : ?>
		<h2 class="mluc-auth-title"><?php echo esc_html( $mluc_title ); ?></h2>
	<?php endif; ?>

	<?php mluc_get_template( 'parts/notice', array( 'notice' => $mluc_notice ) ); ?>

	<form class="mluc-form" method="post" action="<?php echo esc_url( mluc_get_register_url() ); ?>"
		data-mluc-action="mluc_register"
		data-mluc-nonce="<?php echo esc_attr( wp_create_nonce( MLUC_Register::NONCE_ACTION ) ); ?>"
		data-mluc-notice="mluc-register-notice">

		<p class="mluc-field">
			<label for="mluc-register-user"><?php esc_html_e( 'Username', 'moonlight-user-center' ); ?></label>
			<input type="text" id="mluc-register-user" name="user_login" autocomplete="username" required
				value="<?php echo esc_attr( $mluc_user_login ); ?>" />
		</p>

		<p class="mluc-field">
			<label for="mluc-register-email"><?php esc_html_e( 'Email Address', 'moonlight-user-center' ); ?></label>
			<input type="email" id="mluc-register-email" name="email" autocomplete="email" required
				value="<?php echo esc_attr( $mluc_email ); ?>" />
		</p>

		<p class="mluc-field">
			<label for="mluc-register-pass"><?php esc_html_e( 'Password', 'moonlight-user-center' ); ?></label>
			<input type="password" id="mluc-register-pass" name="password" autocomplete="new-password" required
				minlength="8" aria-describedby="mluc-register-pass-hint" />
			<span class="mluc-field-hint" id="mluc-register-pass-hint">
				<?php esc_html_e( 'At least 8 characters.', 'moonlight-user-center' ); ?>
			</span>
		</p>

		<?php
		/*
		 * Anti-spam measures. The honeypot is hidden from people; the token
		 * proves the form was rendered by this site and submitted once.
		 */
		?>
		<p class="mluc-hp" aria-hidden="true">
			<label for="mluc-register-hp"><?php esc_html_e( 'Leave this field empty', 'moonlight-user-center' ); ?></label>
			<input type="text" id="mluc-register-hp" name="mluc_hp" value="" tabindex="-1" autocomplete="off" />
		</p>
		<input type="hidden" name="mluc_tk" value="<?php echo esc_attr( MLUC_Register::issue_token() ); ?>" />

		<p class="mluc-form-notice" id="mluc-register-notice" role="status" aria-live="polite" hidden></p>

		<p class="mluc-actions">
			<button type="submit" class="mluc-button"><?php esc_html_e( 'Create Account', 'moonlight-user-center' ); ?></button>
		</p>
	</form>

	<ul class="mluc-auth-links">
		<li>
			<a href="<?php echo esc_url( mluc_get_login_url() ); ?>">
				<?php esc_html_e( 'Already have an account? Log in', 'moonlight-user-center' ); ?>
			</a>
		</li>
	</ul>
</div>
