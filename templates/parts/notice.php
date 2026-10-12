<?php
/**
 * Inline notice.
 *
 * Overridden by: wp-content/themes/<theme>/at8-moonlight-user-center/parts/notice.php
 *
 * @package AT8_Moonlight_User_Center
 *
 * @var array $args {
 *     @type array $notice {
 *         @type string $type    One of success, error, info.
 *         @type string $message Message text.
 *     }
 * }
 */

defined( 'ABSPATH' ) || exit;

$at8_mluc_notice = isset( $args['notice'] ) && is_array( $args['notice'] ) ? $args['notice'] : array();
$at8_mluc_type   = isset( $at8_mluc_notice['type'] ) ? sanitize_html_class( $at8_mluc_notice['type'] ) : 'info';
$at8_mluc_text   = isset( $at8_mluc_notice['message'] ) ? (string) $at8_mluc_notice['message'] : '';

if ( '' === $at8_mluc_text ) {
	return;
}

if ( ! in_array( $at8_mluc_type, array( 'success', 'error', 'info', 'pending' ), true ) ) {
	$at8_mluc_type = 'info';
}

$at8_mluc_role = 'error' === $at8_mluc_type ? 'alert' : 'status';
?>
<p class="at8-mluc-message at8-mluc-message--<?php echo esc_attr( $at8_mluc_type ); ?>" role="<?php echo esc_attr( $at8_mluc_role ); ?>">
	<?php echo esc_html( $at8_mluc_text ); ?>
</p>
