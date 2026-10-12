<?php
/**
 * Membership tab: current level and level comparison.
 *
 * @package AT8_Moonlight_User_Center
 *
 * @var array $args Template arguments.
 * @since 2.2.0
 */

defined( 'ABSPATH' ) || exit;

$at8_mluc_user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
$at8_mluc_level   = isset( $args['level'] ) ? (string) $args['level'] : '';
$at8_mluc_expires = isset( $args['expires'] ) ? (int) $args['expires'] : 0;
$at8_mluc_expired = isset( $args['expired'] ) ? (bool) $args['expired'] : false;
$at8_mluc_levels  = isset( $args['levels'] ) && is_array( $args['levels'] ) ? $args['levels'] : array();
?>
<div class="at8-mluc-membership">

	<section class="at8-mluc-card" aria-label="<?php esc_attr_e( 'Current Membership', 'at8-moonlight-user-center' ); ?>">
		<h3 class="at8-mluc-card-title"><?php esc_html_e( 'Current Membership', 'at8-moonlight-user-center' ); ?></h3>
		<p class="at8-mluc-membership-level">
			<span class="at8-mluc-level-badge at8-mluc-level-<?php echo esc_attr( $at8_mluc_level ); ?>">
				<?php
				echo esc_html(
					'' !== $at8_mluc_level
						? AT8_MLUC_Membership::get_level_label( $at8_mluc_level )
						: at8_mluc_ui_label( 'no_member', __( 'Non-member', 'at8-moonlight-user-center' ) )
				);
				?>
			</span>
		</p>
		<?php if ( '' === $at8_mluc_level || 'free' === $at8_mluc_level ) : ?>
			<p class="at8-mluc-membership-tip">
				<?php if ( 'free' === $at8_mluc_level ) : ?>
					<?php esc_html_e( 'You are currently a Basic member. Upgrade to unlock more content.', 'at8-moonlight-user-center' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'You do not have a membership yet. Upgrade to unlock more content.', 'at8-moonlight-user-center' ); ?>
				<?php endif; ?>
			</p>
		<?php else : ?>
			<p class="at8-mluc-membership-tip">
				<?php if ( $at8_mluc_expires ) : ?>
					<?php if ( $at8_mluc_expired ) : ?>
						<?php esc_html_e( 'Your membership has expired. Please renew to continue.', 'at8-moonlight-user-center' ); ?>
					<?php else : ?>
						<?php
						printf(
							/* translators: %s: membership expiry date, localized. */
							esc_html__( 'Expires: %s', 'at8-moonlight-user-center' ),
							esc_html( date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $at8_mluc_expires ) )
						);
						?>
					<?php endif; ?>
				<?php else : ?>
					<?php esc_html_e( 'Lifetime member', 'at8-moonlight-user-center' ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</section>

	<section class="at8-mluc-card" aria-label="<?php esc_attr_e( 'Membership Levels', 'at8-moonlight-user-center' ); ?>">
		<h3 class="at8-mluc-card-title"><?php esc_html_e( 'Membership Levels', 'at8-moonlight-user-center' ); ?></h3>
		<p class="at8-mluc-card-subtitle">
			<?php esc_html_e( 'Compare the benefits and your current status of each level.', 'at8-moonlight-user-center' ); ?>
		</p>
		<div class="at8-mluc-table-scroll">
			<table class="at8-mluc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Level', 'at8-moonlight-user-center' ); ?></th>
						<th><?php esc_html_e( 'Description', 'at8-moonlight-user-center' ); ?></th>
						<th><?php esc_html_e( 'Status', 'at8-moonlight-user-center' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $at8_mluc_levels as $at8_mluc_key => $at8_mluc_lv ) : ?>
						<?php
						$at8_mluc_description = ! empty( $at8_mluc_lv['description'] )
							? $at8_mluc_lv['description']
							: __( 'Browse basic content.', 'at8-moonlight-user-center' );
						?>
						<tr class="<?php echo $at8_mluc_key === $at8_mluc_level ? 'is-current' : ''; ?>">
							<td>
								<span class="at8-mluc-level-badge at8-mluc-level-<?php echo esc_attr( $at8_mluc_key ); ?>">
									<?php echo esc_html( AT8_MLUC_Membership::get_level_label( $at8_mluc_key ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $at8_mluc_description ); ?></td>
							<td>
								<?php if ( $at8_mluc_key === $at8_mluc_level ) : ?>
									<span class="at8-mluc-level-current"><?php esc_html_e( 'Current', 'at8-moonlight-user-center' ); ?></span>
								<?php elseif ( AT8_MLUC_Membership::get_level_sort_order( $at8_mluc_key ) < AT8_MLUC_Membership::get_level_sort_order( $at8_mluc_level ) ) : ?>
									<span class="at8-mluc-level-included"><?php esc_html_e( 'Included', 'at8-moonlight-user-center' ); ?></span>
								<?php else : ?>
									<span class="at8-mluc-level-locked"><?php esc_html_e( 'Locked', 'at8-moonlight-user-center' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

</div>
