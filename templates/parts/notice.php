<?php
/**
 * Inline notice.
 *
 * Overridden by: wp-content/themes/<theme>/moonlight-user-center/parts/notice.php
 *
 * @package Moonlight_User_Center
 *
 * @var array $args {
 *     @type array $notice {
 *         @type string $type    One of success, error, info.
 *         @type string $message Message text.
 *     }
 * }
 */

defined( 'ABSPATH' ) || exit;

$mluc_notice = isset( $args['notice'] ) && is_array( $args['notice'] ) ? $args['notice'] : array();
$mluc_type   = isset( $mluc_notice['type'] ) ? sanitize_html_class( $mluc_notice['type'] ) : 'info';
$mluc_text   = isset( $mluc_notice['message'] ) ? (string) $mluc_notice['message'] : '';

if ( '' === $mluc_text ) {
	return;
}

if ( ! in_array( $mluc_type, array( 'success', 'error', 'info', 'pending' ), true ) ) {
	$mluc_type = 'info';
}

$mluc_role = 'error' === $mluc_type ? 'alert' : 'status';
?>
<p class="mluc-message mluc-message--<?php echo esc_attr( $mluc_type ); ?>" role="<?php echo esc_attr( $mluc_role ); ?>">
	<?php echo esc_html( $mluc_text ); ?>
</p>
