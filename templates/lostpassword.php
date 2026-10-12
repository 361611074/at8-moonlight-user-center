<?php
/**
 * Password recovery form.
 *
 * Overridden by: wp-content/themes/<theme>/at8-moonlight-user-center/lostpassword.php
 *
 * @package AT8_Moonlight_User_Center
 *
 * @var array $args Template arguments.
 */

defined( 'ABSPATH' ) || exit;

$at8_mluc_notice = isset( $args['notice'] ) && is_array( $args['notice'] ) ? $args['notice'] : array();
$at8_mluc_title  = isset( $args['title'] ) && '' !== $args['title']
	? $args['title']
	: at8_mluc_ui_label( 'heading_lostpassword', __( 'Reset Your Password', 'at8-moonlight-user-center' ) );
?>
<div class="at8-mluc-auth at8-mluc-auth--lostpassword">

	<?php if ( '' !== $at8_mluc_title ) : ?>
		<h2 class="at8-mluc-auth-title"><?php echo esc_html( $at8_mluc_title ); ?></h2>
	<?php endif; ?>

	<p class="at8-mluc-description">
		<?php esc_html_e( 'Enter your username or email address and we will send you a link to reset your password.', 'at8-moonlight-user-center' ); ?>
	</p>

	<?php at8_mluc_get_template( 'parts/notice', array( 'notice' => $at8_mluc_notice ) ); ?>

	<form class="at8-mluc-form" method="post" action="<?php echo esc_url( at8_mluc_get_lostpassword_url() ); ?>"
		data-at8-mluc-action="at8_mluc_lost_password"
		data-at8-mluc-nonce="<?php echo esc_attr( wp_create_nonce( AT8_MLUC_Login::LOST_NONCE_ACTION ) ); ?>"
		data-at8-mluc-notice="at8-mluc-lostpassword-notice">

		<p class="at8-mluc-field">
			<label for="at8-mluc-lost-user"><?php esc_html_e( 'Username or Email Address', 'at8-moonlight-user-center' ); ?></label>
			<input type="text" id="at8-mluc-lost-user" name="user_login" autocomplete="username" required />
		</p>

		<p class="at8-mluc-form-notice" id="at8-mluc-lostpassword-notice" role="status" aria-live="polite" hidden></p>

		<p class="at8-mluc-actions">
			<button type="submit" class="at8-mluc-button"><?php esc_html_e( 'Send Reset Link', 'at8-moonlight-user-center' ); ?></button>
		</p>
	</form>

	<ul class="at8-mluc-auth-links">
		<li>
			<a href="<?php echo esc_url( at8_mluc_get_login_url() ); ?>">
				<?php esc_html_e( 'Back to log in', 'at8-moonlight-user-center' ); ?>
			</a>
		</li>
	</ul>
</div>
