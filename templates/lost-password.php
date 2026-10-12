<?php
/**
 * Password recovery form.
 *
 * Overridden by: wp-content/themes/<theme>/moonlight-user-center/lost-password.php
 *
 * @package Moonlight_User_Center
 *
 * @var array $args Template arguments.
 */

defined( 'ABSPATH' ) || exit;

$mluc_notice = isset( $args['notice'] ) && is_array( $args['notice'] ) ? $args['notice'] : array();
$mluc_title  = isset( $args['title'] ) && '' !== $args['title']
	? $args['title']
	: mluc_ui_label( 'heading_lostpassword', __( 'Reset Your Password', 'moonlight-user-center' ) );
?>
<div class="mluc-auth mluc-auth--lostpassword">

	<?php if ( '' !== $mluc_title ) : ?>
		<h2 class="mluc-auth-title"><?php echo esc_html( $mluc_title ); ?></h2>
	<?php endif; ?>

	<p class="mluc-description">
		<?php esc_html_e( 'Enter your username or email address and we will send you a link to reset your password.', 'moonlight-user-center' ); ?>
	</p>

	<?php mluc_get_template( 'parts/notice', array( 'notice' => $mluc_notice ) ); ?>

	<form class="mluc-form" method="post" action="<?php echo esc_url( mluc_get_lostpassword_url() ); ?>"
		data-mluc-action="mluc_lost_password"
		data-mluc-nonce="<?php echo esc_attr( wp_create_nonce( MLUC_Login::LOST_NONCE_ACTION ) ); ?>"
		data-mluc-notice="mluc-lostpassword-notice">

		<p class="mluc-field">
			<label for="mluc-lost-user"><?php esc_html_e( 'Username or Email Address', 'moonlight-user-center' ); ?></label>
			<input type="text" id="mluc-lost-user" name="user_login" autocomplete="username" required />
		</p>

		<p class="mluc-form-notice" id="mluc-lostpassword-notice" role="status" aria-live="polite" hidden></p>

		<p class="mluc-actions">
			<button type="submit" class="mluc-button"><?php esc_html_e( 'Send Reset Link', 'moonlight-user-center' ); ?></button>
		</p>
	</form>

	<ul class="mluc-auth-links">
		<li>
			<a href="<?php echo esc_url( mluc_get_login_url() ); ?>">
				<?php esc_html_e( 'Back to log in', 'moonlight-user-center' ); ?>
			</a>
		</li>
	</ul>
</div>
