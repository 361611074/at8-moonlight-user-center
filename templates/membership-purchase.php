<?php
/**
 * Membership purchase card: levels and gateways.
 *
 * Rendered as the `purchase` upgrade path; order history lives in
 * membership-orders.php below the path cards.
 *
 * Online gateways (PayPal / Stripe) contribute their own flow through the
 * `at8_mluc_payment_order_response` filter and pass their front-end flags via
 * the `at8_mluc_template_args` filter.
 *
 * @package AT8_Moonlight_User_Center
 *
 * @var array $args Template arguments.
 * @since 2.2.0
 */

defined( 'ABSPATH' ) || exit;

$at8_mluc_levels       = isset( $args['levels'] ) && is_array( $args['levels'] ) ? $args['levels'] : array();
$at8_mluc_gateways     = isset( $args['gateways'] ) && is_array( $args['gateways'] ) ? $args['gateways'] : array();
$at8_mluc_symbol       = isset( $args['symbol'] ) ? (string) $args['symbol'] : '$';
$at8_mluc_instructions = isset( $args['instructions'] ) ? (string) $args['instructions'] : '';
$at8_mluc_nonce        = isset( $args['nonce'] ) ? (string) $args['nonce'] : '';
$at8_mluc_pay_notice   = isset( $args['pay_notice'] ) ? (string) $args['pay_notice'] : '';
$at8_mluc_paypal_on    = ! empty( $args['paypal_on'] );
$at8_mluc_paypal_sdk   = ! empty( $args['paypal_sdk'] ) ? (string) $args['paypal_sdk'] : '';
?>
<div class="at8-mluc-buy">
	<?php if ( 'paid' === $at8_mluc_pay_notice ) : ?>
		<p class="at8-mluc-buy-notice at8-mluc-buy-notice-ok"><?php esc_html_e( 'Payment successful. Your membership has been activated.', 'at8-moonlight-user-center' ); ?></p>
	<?php elseif ( 'failed' === $at8_mluc_pay_notice ) : ?>
		<p class="at8-mluc-buy-notice at8-mluc-buy-notice-err"><?php esc_html_e( 'Payment was not completed or verification failed. Please try again or contact the administrator.', 'at8-moonlight-user-center' ); ?></p>
	<?php elseif ( 'cancelled' === $at8_mluc_pay_notice ) : ?>
		<p class="at8-mluc-buy-notice"><?php esc_html_e( 'Payment cancelled. The order remains pending.', 'at8-moonlight-user-center' ); ?></p>
	<?php endif; ?>

	<div class="at8-mluc-buy-grid">
		<?php foreach ( $at8_mluc_levels as $at8_mluc_key => $at8_mluc_lv ) : ?>
			<?php
			if ( 'free' === $at8_mluc_key || '' === $at8_mluc_key ) {
				continue;
			}

			$at8_mluc_price    = isset( $at8_mluc_lv['price'] ) ? (float) $at8_mluc_lv['price'] : 0;
			$at8_mluc_validity = isset( $at8_mluc_lv['validity'] ) ? (int) $at8_mluc_lv['validity'] : 0;
			$at8_mluc_color    = ! empty( $at8_mluc_lv['color'] ) ? $at8_mluc_lv['color'] : '#2f6fed';
			?>
			<div class="at8-mluc-buy-card" style="border-top-color:<?php echo esc_attr( $at8_mluc_color ); ?>">
				<div class="at8-mluc-buy-name" style="color:<?php echo esc_attr( $at8_mluc_color ); ?>"><?php echo esc_html( $at8_mluc_lv['label'] ); ?></div>
				<div class="at8-mluc-buy-price"><?php echo esc_html( $at8_mluc_symbol . number_format( $at8_mluc_price, 2 ) ); ?></div>
				<div class="at8-mluc-buy-validity">
					<?php
					echo $at8_mluc_validity > 0
						? esc_html( sprintf( /* translators: %d: number of days the membership level stays valid. */ __( 'Valid for %d days', 'at8-moonlight-user-center' ), $at8_mluc_validity ) )
						: esc_html__( 'Permanent', 'at8-moonlight-user-center' );
					?>
				</div>
				<?php if ( $at8_mluc_price > 0 ) : ?>
					<div class="at8-mluc-buy-actions">
						<?php if ( $at8_mluc_gateways ) : ?>
							<?php $at8_mluc_first_gw = array_key_first( $at8_mluc_gateways ); ?>
							<?php foreach ( $at8_mluc_gateways as $at8_mluc_gid => $at8_mluc_gtitle ) : ?>
								<label class="at8-mluc-buy-gateway">
									<input type="radio" name="at8_mluc_buy_gateway_<?php echo esc_attr( $at8_mluc_key ); ?>" value="<?php echo esc_attr( $at8_mluc_gid ); ?>" <?php checked( $at8_mluc_gid, $at8_mluc_first_gw ); ?>>
									<span><?php echo esc_html( $at8_mluc_gtitle ); ?></span>
								</label>
							<?php endforeach; ?>
						<?php else : ?>
							<span class="at8-mluc-buy-gw-empty"><?php esc_html_e( 'No payment method is available. Please contact the administrator.', 'at8-moonlight-user-center' ); ?></span>
						<?php endif; ?>
					</div>
					<button type="button" class="at8-mluc-btn-buy" data-level="<?php echo esc_attr( $at8_mluc_key ); ?>"<?php echo $at8_mluc_gateways ? '' : ' disabled'; ?>><?php esc_html_e( 'Buy', 'at8-moonlight-user-center' ); ?></button>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<p class="at8-mluc-buy-msg" role="alert"></p>
	<div class="at8-mluc-pp-mount" hidden aria-label="PayPal"></div>

	<?php if ( isset( $at8_mluc_gateways['manual'] ) ) : ?>
		<div class="at8-mluc-buy-instructions">
			<strong><?php esc_html_e( 'Payment Instructions', 'at8-moonlight-user-center' ); ?></strong>
			<p><?php echo esc_html( $at8_mluc_instructions ); ?></p>
		</div>
	<?php endif; ?>
</div>
