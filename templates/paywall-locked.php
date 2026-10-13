<?php
/**
 * Paywall: the locked card shown instead of the content.
 *
 * Overridden by: wp-content/themes/<theme>/at8-moonlight-user-center/paywall-locked.php
 *
 * @package AT8_Moonlight_User_Center
 *
 * @var array $args {
 *     @type int    $post_id   Post being unlocked.
 *     @type float  $price     Price.
 *     @type string $symbol    Currency symbol.
 *     @type array  $levels    Levels that unlock the post for free.
 *     @type string $nonce     Nonce for the unlock request.
 *     @type bool   $loggedin  Whether the visitor is logged in.
 *     @type string $login_url Login URL.
 * }
 */

defined( 'ABSPATH' ) || exit;

$at8_mluc_pw_post_id  = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
$at8_mluc_pw_price    = isset( $args['price'] ) ? (float) $args['price'] : 0.0;
$at8_mluc_pw_symbol   = isset( $args['symbol'] ) ? (string) $args['symbol'] : '';
$at8_mluc_pw_levels   = isset( $args['levels'] ) && is_array( $args['levels'] ) ? $args['levels'] : array();
$at8_mluc_pw_nonce    = isset( $args['nonce'] ) ? (string) $args['nonce'] : '';
$at8_mluc_pw_loggedin = ! empty( $args['loggedin'] );
$at8_mluc_pw_login    = isset( $args['login_url'] ) ? (string) $args['login_url'] : '';
$at8_mluc_pw_gateways = AT8_MLUC_Payments::get_gateways();
$at8_mluc_pw_names    = array();

foreach ( $at8_mluc_pw_levels as $at8_mluc_pw_key ) {
	$at8_mluc_pw_names[] = AT8_MLUC_Membership::get_level_label( $at8_mluc_pw_key );
}
?>
<div class="at8-mluc-paywall" data-at8-mluc-paywall="<?php echo esc_attr( (string) $at8_mluc_pw_post_id ); ?>">

	<span class="at8-mluc-paywall-icon dashicons dashicons-lock" aria-hidden="true"></span>

	<h3 class="at8-mluc-paywall-title">
		<?php esc_html_e( 'This content is for members', 'at8-moonlight-user-center' ); ?>
	</h3>

	<p class="at8-mluc-paywall-price">
		<?php
		printf(
			/* translators: 1: currency symbol, 2: price. */
			esc_html__( 'Unlock for %1$s%2$s', 'at8-moonlight-user-center' ),
			esc_html( $at8_mluc_pw_symbol ),
			esc_html( number_format_i18n( $at8_mluc_pw_price, 2 ) )
		);
		?>
	</p>

	<?php if ( ! empty( $at8_mluc_pw_names ) ) : ?>
		<p class="at8-mluc-paywall-levels">
			<?php
			printf(
				/* translators: %s: membership level names. */
				esc_html__( 'Free for %s members.', 'at8-moonlight-user-center' ),
				esc_html( implode( ', ', $at8_mluc_pw_names ) )
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( ! $at8_mluc_pw_loggedin ) : ?>
		<p class="at8-mluc-paywall-actions">
			<a class="at8-mluc-button" href="<?php echo esc_url( $at8_mluc_pw_login ); ?>">
				<?php esc_html_e( 'Log in to continue', 'at8-moonlight-user-center' ); ?>
			</a>
		</p>
	<?php elseif ( empty( $at8_mluc_pw_gateways ) ) : ?>
		<p class="at8-mluc-message at8-mluc-message--info">
			<?php esc_html_e( 'Payments are not being accepted at the moment.', 'at8-moonlight-user-center' ); ?>
		</p>
	<?php else : ?>
		<p class="at8-mluc-paywall-gateways">
			<?php foreach ( array_keys( $at8_mluc_pw_gateways ) as $at8_mluc_pw_gw ) : ?>
				<button type="button" class="at8-mluc-button at8-mluc-paywall-buy"
					data-at8-mluc-paywall-buy="<?php echo esc_attr( $at8_mluc_pw_gw ); ?>"
					data-post="<?php echo esc_attr( (string) $at8_mluc_pw_post_id ); ?>"
					data-nonce="<?php echo esc_attr( $at8_mluc_pw_nonce ); ?>">
					<?php
					printf(
						/* translators: %s: gateway name. */
						esc_html__( 'Pay with %s', 'at8-moonlight-user-center' ),
						esc_html( $at8_mluc_pw_gateways[ $at8_mluc_pw_gw ] )
					);
					?>
				</button>
			<?php endforeach; ?>
		</p>

		<p class="at8-mluc-paywall-notice" role="status" aria-live="polite" hidden></p>
	<?php endif; ?>
</div>
