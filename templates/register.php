<?php
/**
 * Registration form.
 *
 * Overridden by: wp-content/themes/<theme>/at8-moonlight-user-center/register.php
 *
 * @package AT8_Moonlight_User_Center
 *
 * @var array $args Template arguments.
 */

defined( 'ABSPATH' ) || exit;

$at8mluc_notice = isset( $args['notice'] ) && is_array( $args['notice'] ) ? $args['notice'] : array();
$at8mluc_title  = isset( $args['title'] ) && '' !== $args['title']
	? $args['title']
	: at8mluc_ui_label( 'heading_register', __( 'Create an Account', 'at8-moonlight-user-center' ) );
$at8mluc_user_login = isset( $args['user_login'] ) ? $args['user_login'] : '';
$at8mluc_email      = isset( $args['email'] ) ? $args['email'] : '';

if ( ! AT8MLUC_Register::is_enabled() ) :
	?>
	<p class="at8mluc-message at8mluc-message--info">
		<?php esc_html_e( 'Registration is currently disabled on this site.', 'at8-moonlight-user-center' ); ?>
	</p>
	<?php
	return;
endif;
?>
<div class="at8mluc-auth at8mluc-auth--register">

	<?php if ( '' !== $at8mluc_title ) : ?>
		<h2 class="at8mluc-auth-title"><?php echo esc_html( $at8mluc_title ); ?></h2>
	<?php endif; ?>

	<?php at8mluc_get_template( 'parts/notice', array( 'notice' => $at8mluc_notice ) ); ?>

	<form class="at8mluc-form" method="post" action="<?php echo esc_url( at8mluc_get_register_url() ); ?>"
		data-at8mluc-action="at8mluc_register"
		data-at8mluc-nonce="<?php echo esc_attr( wp_create_nonce( AT8MLUC_Register::NONCE_ACTION ) ); ?>"
		data-at8mluc-notice="at8mluc-register-notice">

		<p class="at8mluc-field">
			<label for="at8mluc-register-user"><?php esc_html_e( 'Username', 'at8-moonlight-user-center' ); ?></label>
			<input type="text" id="at8mluc-register-user" name="user_login" autocomplete="username" required
				value="<?php echo esc_attr( $at8mluc_user_login ); ?>" />
		</p>

		<p class="at8mluc-field">
			<label for="at8mluc-register-email"><?php esc_html_e( 'Email Address', 'at8-moonlight-user-center' ); ?></label>
			<input type="email" id="at8mluc-register-email" name="email" autocomplete="email" required
				value="<?php echo esc_attr( $at8mluc_email ); ?>" />
		</p>

		<p class="at8mluc-field">
			<label for="at8mluc-register-pass"><?php esc_html_e( 'Password', 'at8-moonlight-user-center' ); ?></label>
			<input type="password" id="at8mluc-register-pass" name="password" autocomplete="new-password" required
				minlength="8" aria-describedby="at8mluc-register-pass-hint" />
			<span class="at8mluc-field-hint" id="at8mluc-register-pass-hint">
				<?php esc_html_e( 'At least 8 characters.', 'at8-moonlight-user-center' ); ?>
			</span>
		</p>

		<?php
		/*
		 * Extra fields defined in User Center → Settings → Registration &amp;
		 * Profile Fields. Rendered as a fieldset so assistive technology
		 * announces the group.
		 */
		do_action( 'at8mluc_register_form_fields' );
		?>

		<?php
		/*
		 * Anti-spam measures. The honeypot is hidden from people; the token
		 * proves the form was rendered by this site and submitted once.
		 */
		?>
		<p class="at8mluc-hp" aria-hidden="true">
			<label for="at8mluc-register-hp"><?php esc_html_e( 'Leave this field empty', 'at8-moonlight-user-center' ); ?></label>
			<input type="text" id="at8mluc-register-hp" name="at8mluc_hp" value="" tabindex="-1" autocomplete="off" />
		</p>
		<input type="hidden" name="at8mluc_tk" value="<?php echo esc_attr( AT8MLUC_Register::issue_token() ); ?>" />

		<p class="at8mluc-form-notice" id="at8mluc-register-notice" role="status" aria-live="polite" hidden></p>

		<p class="at8mluc-actions">
			<button type="submit" class="at8mluc-button"><?php esc_html_e( 'Create Account', 'at8-moonlight-user-center' ); ?></button>
		</p>
	</form>

	<?php
	/**
	 * Fires at the bottom of the registration form, after the submit button.
	 *
	 * The Pro add-on hooks here to render social login buttons.
	 *
	 * @since 2.0.0
	 */
	do_action( 'at8mluc_register_form_bottom' );
	?>

	<ul class="at8mluc-auth-links">
		<li>
			<a href="<?php echo esc_url( at8mluc_get_login_url() ); ?>">
				<?php esc_html_e( 'Already have an account? Log in', 'at8-moonlight-user-center' ); ?>
			</a>
		</li>
	</ul>
</div>
