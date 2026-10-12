<?php
/**
 * Account dashboard: avatar picker.
 *
 * Overridden by: wp-content/themes/<theme>/moonlight-user-center/account-avatar.php
 *
 * @package Moonlight_User_Center
 *
 * @var array $args {
 *     @type WP_User $user       Current user.
 *     @type array   $library    Avatars published by the site administrator.
 *     @type int     $current_id Attachment ID currently in use.
 *     @type string  $avatar_url Current avatar URL.
 * }
 */

defined( 'ABSPATH' ) || exit;

$user = isset( $args['user'] ) ? $args['user'] : null;

if ( ! $user instanceof WP_User ) {
	return;
}

$library         = isset( $args['library'] ) && is_array( $args['library'] ) ? $args['library'] : array();
$avatar_url      = isset( $args['avatar_url'] ) ? $args['avatar_url'] : '';
$mluc_current_id = isset( $args['current_id'] ) ? absint( $args['current_id'] ) : 0;
?>
<div class="mluc-card mluc-avatar">

	<h3 class="mluc-card-title"><?php esc_html_e( 'Avatar', 'moonlight-user-center' ); ?></h3>

	<div class="mluc-avatar-current">
		<img data-mluc-avatar-preview
			src="<?php echo esc_url( $avatar_url ); ?>"
			width="96" height="96" loading="lazy" decoding="async"
			alt="<?php esc_attr_e( 'Your current avatar', 'moonlight-user-center' ); ?>" />
	</div>

	<p class="mluc-form-notice" id="mluc-avatar-notice" role="status" aria-live="polite" hidden></p>

	<?php if ( empty( $library ) ) : ?>

		<p class="mluc-message mluc-message--info">
			<?php esc_html_e( 'No avatars are available yet. Please check back later.', 'moonlight-user-center' ); ?>
		</p>

	<?php else : ?>

		<div class="mluc-avatar-grid" data-mluc-avatar-picker data-mluc-notice="mluc-avatar-notice">
			<?php foreach ( $library as $mluc_item ) : ?>
				<?php
				$mluc_is_active = ( (int) $mluc_item['attachment_id'] === $mluc_current_id );
				$mluc_label     = '' !== $mluc_item['title']
					? $mluc_item['title']
					/* translators: %s: avatar number. */
					: sprintf( __( 'Avatar %s', 'moonlight-user-center' ), number_format_i18n( $mluc_item['id'] ) );
				$mluc_classes   = 'mluc-avatar-option' . ( $mluc_is_active ? ' is-active' : '' );
				$mluc_pressed   = $mluc_is_active ? 'true' : 'false';
				?>
				<button type="button"
					class="<?php echo esc_attr( $mluc_classes ); ?>"
					data-mluc-avatar-id="<?php echo esc_attr( (string) $mluc_item['id'] ); ?>"
					aria-pressed="<?php echo esc_attr( $mluc_pressed ); ?>">
					<img src="<?php echo esc_url( $mluc_item['url'] ); ?>"
						width="72" height="72" loading="lazy" decoding="async"
						alt="<?php echo esc_attr( $mluc_label ); ?>" />
					<span class="screen-reader-text"><?php echo esc_html( $mluc_label ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>

		<p class="mluc-actions">
			<button type="button" class="mluc-button mluc-button--link"
				data-mluc-avatar-reset="reset"
				data-mluc-avatar-id="">
				<?php esc_html_e( 'Use the default avatar', 'moonlight-user-center' ); ?>
			</button>
		</p>

	<?php endif; ?>
</div>
