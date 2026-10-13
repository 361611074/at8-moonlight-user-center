<?php
/**
 * Membership order history.
 *
 * Renders below the upgrade path cards inside the membership account tab.
 *
 * @package AT8_Moonlight_User_Center
 *
 * @var array $args Template arguments.
 * @since 2.4.1
 */

defined( 'ABSPATH' ) || exit;

$at8_mluc_orders = isset( $args['orders'] ) && is_array( $args['orders'] ) ? $args['orders'] : array();
$at8_mluc_symbol = isset( $args['symbol'] ) ? (string) $args['symbol'] : '$';

if ( empty( $at8_mluc_orders ) ) {
	return;
}
?>
<div class="at8-mluc-order-list">
	<strong><?php esc_html_e( 'My Purchase History', 'at8-moonlight-user-center' ); ?></strong>
	<table class="at8-mluc-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Order', 'at8-moonlight-user-center' ); ?></th>
				<th><?php esc_html_e( 'Level', 'at8-moonlight-user-center' ); ?></th>
				<th><?php esc_html_e( 'Amount', 'at8-moonlight-user-center' ); ?></th>
				<th><?php esc_html_e( 'Status', 'at8-moonlight-user-center' ); ?></th>
				<th><?php esc_html_e( 'Date', 'at8-moonlight-user-center' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			$at8_mluc_status_labels = AT8_MLUC_Payments::status_labels();

			foreach ( $at8_mluc_orders as $at8_mluc_o ) :
				$at8_mluc_st = isset( $at8_mluc_status_labels[ $at8_mluc_o['status'] ] ) ? $at8_mluc_status_labels[ $at8_mluc_o['status'] ] : $at8_mluc_o['status'];
				?>
				<tr>
					<td><?php echo esc_html( $at8_mluc_o['title'] ); ?></td>
					<td><?php echo esc_html( AT8_MLUC_Membership::get_level_label( $at8_mluc_o['level'] ) ); ?></td>
					<td><?php echo esc_html( $at8_mluc_symbol . number_format( $at8_mluc_o['price'], 2 ) ); ?></td>
					<td><span class="at8-mluc-pill at8-mluc-pill-<?php echo esc_attr( $at8_mluc_o['status'] ); ?>"><?php echo esc_html( $at8_mluc_st ); ?></span></td>
					<td><?php echo esc_html( $at8_mluc_o['date'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
