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

$at8mluc_orders = isset( $args['orders'] ) && is_array( $args['orders'] ) ? $args['orders'] : array();
$at8mluc_symbol = isset( $args['symbol'] ) ? (string) $args['symbol'] : '$';

if ( empty( $at8mluc_orders ) ) {
	return;
}
?>
<div class="at8mluc-order-list">
	<strong><?php esc_html_e( 'My Purchase History', 'at8-moonlight-user-center' ); ?></strong>
	<table class="at8mluc-table">
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
			$at8mluc_status_labels = AT8MLUC_Payments::status_labels();

			foreach ( $at8mluc_orders as $at8mluc_o ) :
				$at8mluc_st = isset( $at8mluc_status_labels[ $at8mluc_o['status'] ] ) ? $at8mluc_status_labels[ $at8mluc_o['status'] ] : $at8mluc_o['status'];
				?>
				<tr>
					<td><?php echo esc_html( $at8mluc_o['title'] ); ?></td>
					<td><?php echo esc_html( AT8MLUC_Membership::get_level_label( $at8mluc_o['level'] ) ); ?></td>
					<td><?php echo esc_html( $at8mluc_symbol . number_format( $at8mluc_o['price'], 2 ) ); ?></td>
					<td><span class="at8mluc-pill at8mluc-pill-<?php echo esc_attr( $at8mluc_o['status'] ); ?>"><?php echo esc_html( $at8mluc_st ); ?></span></td>
					<td><?php echo esc_html( $at8mluc_o['date'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
