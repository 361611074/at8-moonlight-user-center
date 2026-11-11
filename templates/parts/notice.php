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

$at8mluc_notice = isset( $args['notice'] ) && is_array( $args['notice'] ) ? $args['notice'] : array();
$at8mluc_type   = isset( $at8mluc_notice['type'] ) ? sanitize_html_class( $at8mluc_notice['type'] ) : 'info';
$at8mluc_text   = isset( $at8mluc_notice['message'] ) ? (string) $at8mluc_notice['message'] : '';

if ( '' === $at8mluc_text ) {
	return;
}

if ( ! in_array( $at8mluc_type, array( 'success', 'error', 'info', 'pending' ), true ) ) {
	$at8mluc_type = 'info';
}

$at8mluc_role = 'error' === $at8mluc_type ? 'alert' : 'status';
?>
<p class="at8mluc-message at8mluc-message--<?php echo esc_attr( $at8mluc_type ); ?>" role="<?php echo esc_attr( $at8mluc_role ); ?>">
	<?php echo esc_html( $at8mluc_text ); ?>
</p>
