<?php
/**
 * Account dashboard: overview.
 *
 * Overridden by: wp-content/themes/<theme>/at8-moonlight-user-center/account-overview.php
 *
 * @package AT8_Moonlight_User_Center
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

$at8_mluc_user          = isset( $args['user'] ) ? $args['user'] : null;
$at8_mluc_comment_count = isset( $args['comment_count'] ) ? (int) $args['comment_count'] : 0;
$at8_mluc_avatar_url    = isset( $args['avatar_url'] ) ? $args['avatar_url'] : '';
$at8_mluc_logout_url    = isset( $args['logout_url'] ) ? $args['logout_url'] : '';
$at8_mluc_profile_url   = isset( $args['profile_url'] ) ? $args['profile_url'] : '';

if ( ! $at8_mluc_user instanceof WP_User ) {
	return;
}

$at8_mluc_registered = $at8_mluc_user->user_registered
	? mysql2date( (string) get_option( 'date_format' ), $at8_mluc_user->user_registered, true )
	: '';
?>
<div class="at8-mluc-card at8-mluc-overview">

	<div class="at8-mluc-overview-header">
		<?php if ( ! empty( $at8_mluc_avatar_url ) ) : ?>
			<img class="at8-mluc-overview-avatar" src="<?php echo esc_url( $at8_mluc_avatar_url ); ?>"
				width="96" height="96" loading="lazy" decoding="async"
				alt="<?php echo esc_attr( $at8_mluc_user->display_name ); ?>" />
		<?php endif; ?>

		<div class="at8-mluc-overview-identity">
			<h3 class="at8-mluc-card-title"><?php echo esc_html( $at8_mluc_user->display_name ); ?></h3>
			<?php if ( ! empty( $at8_mluc_user->user_email ) ) : ?>
				<p class="at8-mluc-overview-email"><?php echo esc_html( $at8_mluc_user->user_email ); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<ul class="at8-mluc-overview-stats">
		<?php if ( '' !== $at8_mluc_registered ) : ?>
			<li>
				<span class="at8-mluc-stat-label"><?php esc_html_e( 'Member since', 'at8-moonlight-user-center' ); ?></span>
				<span class="at8-mluc-stat-value"><?php echo esc_html( $at8_mluc_registered ); ?></span>
			</li>
		<?php endif; ?>

		<li>
			<span class="at8-mluc-stat-label"><?php esc_html_e( 'Approved comments', 'at8-moonlight-user-center' ); ?></span>
			<span class="at8-mluc-stat-value"><?php echo esc_html( number_format_i18n( (int) $at8_mluc_comment_count ) ); ?></span>
		</li>
	</ul>

	<?php
	/*
	 * Membership and points summary. Membership is the headline: it is what
	 * unlocks content, and it is the number people check first.
	 */
	$at8_mluc_level       = AT8_MLUC_Membership::get_user_level( $at8_mluc_user->ID );
	$at8_mluc_level_label = $at8_mluc_level ? AT8_MLUC_Membership::get_level_label( $at8_mluc_level ) : __( 'None', 'at8-moonlight-user-center' );
	$at8_mluc_expires     = AT8_MLUC_Membership::get_user_expires( $at8_mluc_user->ID );
	$at8_mluc_points      = AT8_MLUC_Points::balance( $at8_mluc_user->ID );
	$at8_mluc_orders      = AT8_MLUC_Payments::get_user_orders( $at8_mluc_user->ID, 100 );
	$at8_mluc_paid        = 0;
	$at8_mluc_spent       = 0.0;

	foreach ( $at8_mluc_orders as $at8_mluc_order ) {
		if ( 'paid' !== (string) get_post_meta( $at8_mluc_order->ID, '_at8_mluc_pay_status', true ) ) {
			continue;
		}

		$at8_mluc_paid++;
		$at8_mluc_spent += (float) get_post_meta( $at8_mluc_order->ID, '_at8_mluc_pay_price', true );
	}
	?>

	<ul class="at8-mluc-overview-stats at8-mluc-overview-stats--wide">
		<li>
			<span class="at8-mluc-stat-label"><?php esc_html_e( 'Membership', 'at8-moonlight-user-center' ); ?></span>
			<span class="at8-mluc-stat-value"><?php echo esc_html( $at8_mluc_level_label ); ?></span>
			<?php if ( '' !== $at8_mluc_expires ) : ?>
				<span class="at8-mluc-stat-note">
					<?php
					printf(
						/* translators: %s: expiry date. */
						esc_html__( 'Valid until %s', 'at8-moonlight-user-center' ),
						esc_html( mysql2date( (string) get_option( 'date_format' ), $at8_mluc_expires, true ) )
					);
					?>
				</span>
			<?php endif; ?>
		</li>

		<li>
			<span class="at8-mluc-stat-label"><?php echo esc_html( AT8_MLUC_Points::label() ); ?></span>
			<span class="at8-mluc-stat-value"><?php echo esc_html( number_format_i18n( $at8_mluc_points ) ); ?></span>
		</li>

		<li>
			<span class="at8-mluc-stat-label"><?php esc_html_e( 'Paid orders', 'at8-moonlight-user-center' ); ?></span>
			<span class="at8-mluc-stat-value"><?php echo esc_html( number_format_i18n( $at8_mluc_paid ) ); ?></span>
			<?php if ( $at8_mluc_spent > 0 ) : ?>
				<span class="at8-mluc-stat-note">
					<?php
					printf(
						/* translators: %s: formatted total spent. */
						esc_html__( '%s in total', 'at8-moonlight-user-center' ),
						esc_html( AT8_MLUC_Payments::get_currency_symbol() . number_format_i18n( $at8_mluc_spent, 2 ) )
					);
					?>
				</span>
			<?php endif; ?>
		</li>
	</ul>

	<?php
	/**
	 * Fires inside the overview tab, after the built-in statistics.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_User $at8_mluc_user Current user.
	 */
	do_action( 'at8_mluc_account_overview_after', $at8_mluc_user );
	?>

	<p class="at8-mluc-actions">
		<a class="at8-mluc-button at8-mluc-button--secondary" href="<?php echo esc_url( $at8_mluc_profile_url ); ?>">
			<?php esc_html_e( 'Edit profile', 'at8-moonlight-user-center' ); ?>
		</a>
		<a class="at8-mluc-button at8-mluc-button--link" href="<?php echo esc_url( $at8_mluc_logout_url ); ?>">
			<?php esc_html_e( 'Log Out', 'at8-moonlight-user-center' ); ?>
		</a>
	</p>
</div>
