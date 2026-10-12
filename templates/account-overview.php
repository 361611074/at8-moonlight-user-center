<?php
/**
 * Account dashboard: overview.
 *
 * Overridden by: wp-content/themes/<theme>/moonlight-user-center/account-overview.php
 *
 * @package Moonlight_User_Center
 *
 * @var array $args {
 *     @type WP_User $user          Current user.
 *     @type int     $comment_count Approved comment count.
 *     @type string  $avatar_url    Avatar URL.
 *     @type string  $logout_url    Nonce-protected logout URL.
 *     @type string  $profile_url   Profile tab URL.
 * }
 */

defined( 'ABSPATH' ) || exit;

$user          = isset( $args['user'] ) ? $args['user'] : null;
$comment_count = isset( $args['comment_count'] ) ? (int) $args['comment_count'] : 0;
$avatar_url    = isset( $args['avatar_url'] ) ? $args['avatar_url'] : '';
$logout_url    = isset( $args['logout_url'] ) ? $args['logout_url'] : '';
$profile_url   = isset( $args['profile_url'] ) ? $args['profile_url'] : '';

if ( ! $user instanceof WP_User ) {
	return;
}

$mluc_registered = $user->user_registered
	? mysql2date( (string) get_option( 'date_format' ), $user->user_registered, true )
	: '';
?>
<div class="mluc-card mluc-overview">

	<div class="mluc-overview-header">
		<?php if ( ! empty( $avatar_url ) ) : ?>
			<img class="mluc-overview-avatar" src="<?php echo esc_url( $avatar_url ); ?>"
				width="96" height="96" loading="lazy" decoding="async"
				alt="<?php echo esc_attr( $user->display_name ); ?>" />
		<?php endif; ?>

		<div class="mluc-overview-identity">
			<h3 class="mluc-card-title"><?php echo esc_html( $user->display_name ); ?></h3>
			<?php if ( ! empty( $user->user_email ) ) : ?>
				<p class="mluc-overview-email"><?php echo esc_html( $user->user_email ); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<ul class="mluc-overview-stats">
		<?php if ( '' !== $mluc_registered ) : ?>
			<li>
				<span class="mluc-stat-label"><?php esc_html_e( 'Member since', 'moonlight-user-center' ); ?></span>
				<span class="mluc-stat-value"><?php echo esc_html( $mluc_registered ); ?></span>
			</li>
		<?php endif; ?>

		<li>
			<span class="mluc-stat-label"><?php esc_html_e( 'Approved comments', 'moonlight-user-center' ); ?></span>
			<span class="mluc-stat-value"><?php echo esc_html( number_format_i18n( (int) $comment_count ) ); ?></span>
		</li>
	</ul>

	<?php
	/**
	 * Fires inside the overview tab, after the built-in statistics.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_User $user Current user.
	 */
	do_action( 'mluc_account_overview_after', $user );
	?>

	<p class="mluc-actions">
		<a class="mluc-button mluc-button--secondary" href="<?php echo esc_url( $profile_url ); ?>">
			<?php esc_html_e( 'Edit profile', 'moonlight-user-center' ); ?>
		</a>
		<a class="mluc-button mluc-button--link" href="<?php echo esc_url( $logout_url ); ?>">
			<?php esc_html_e( 'Log Out', 'moonlight-user-center' ); ?>
		</a>
	</p>
</div>
