<?php
/**
 * Account dashboard: avatar picker.
 *
 * Overridden by: wp-content/themes/<theme>/at8-moonlight-user-center/account-avatar.php
 *
 * @package AT8_Moonlight_User_Center
 *
 * @var array $args {
 *     @type WP_User $user       Current user.
 *     @type array   $library    Avatars published by the site administrator.
 *     @type int     $current_id Attachment ID currently in use.
 *     @type string  $avatar_url Current avatar URL.
 * }
 */

defined( 'ABSPATH' ) || exit;

$at8mluc_user = isset( $args['user'] ) ? $args['user'] : null;

if ( ! $at8mluc_user instanceof WP_User ) {
	return;
}

$at8mluc_library         = isset( $args['library'] ) && is_array( $args['library'] ) ? $args['library'] : array();
$at8mluc_avatar_url      = isset( $args['avatar_url'] ) ? $args['avatar_url'] : '';
$at8mluc_current_id = isset( $args['current_id'] ) ? absint( $args['current_id'] ) : 0;
?>
<div class="at8mluc-card at8mluc-avatar">

	<h3 class="at8mluc-card-title"><?php esc_html_e( 'Avatar', 'at8-moonlight-user-center' ); ?></h3>

	<div class="at8mluc-avatar-current">
		<img data-at8mluc-avatar-preview
			src="<?php echo esc_url( $at8mluc_avatar_url ); ?>"
			width="96" height="96" loading="lazy" decoding="async"
			alt="<?php esc_attr_e( 'Your current avatar', 'at8-moonlight-user-center' ); ?>" />
	</div>

	<p class="at8mluc-form-notice" id="at8mluc-avatar-notice" role="status" aria-live="polite" hidden></p>

	<?php if ( empty( $at8mluc_library ) ) : ?>

		<p class="at8mluc-message at8mluc-message--info">
			<?php esc_html_e( 'No avatars are available yet. Please check back later.', 'at8-moonlight-user-center' ); ?>
		</p>

	<?php else : ?>

		<div class="at8mluc-avatar-grid" data-at8mluc-avatar-picker data-at8mluc-notice="at8mluc-avatar-notice">
			<?php foreach ( $at8mluc_library as $at8mluc_item ) : ?>
				<?php
				$at8mluc_is_active = ( (int) $at8mluc_item['attachment_id'] === $at8mluc_current_id );
				$at8mluc_label     = '' !== $at8mluc_item['title']
					? $at8mluc_item['title']
					/* translators: %s: avatar number. */
					: sprintf( __( 'Avatar %s', 'at8-moonlight-user-center' ), number_format_i18n( $at8mluc_item['id'] ) );
				$at8mluc_classes   = 'at8mluc-avatar-option' . ( $at8mluc_is_active ? ' is-active' : '' );
				$at8mluc_pressed   = $at8mluc_is_active ? 'true' : 'false';
				?>
				<button type="button"
					class="<?php echo esc_attr( $at8mluc_classes ); ?>"
					data-at8mluc-avatar-id="<?php echo esc_attr( (string) $at8mluc_item['id'] ); ?>"
					aria-pressed="<?php echo esc_attr( $at8mluc_pressed ); ?>">
					<img src="<?php echo esc_url( $at8mluc_item['url'] ); ?>"
						width="72" height="72" loading="lazy" decoding="async"
						alt="<?php echo esc_attr( $at8mluc_label ); ?>" />
					<span class="screen-reader-text"><?php echo esc_html( $at8mluc_label ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>

		<p class="at8mluc-actions">
			<button type="button" class="at8mluc-button at8mluc-button--link"
				data-at8mluc-avatar-reset="reset"
				data-at8mluc-avatar-id="">
				<?php esc_html_e( 'Use the default avatar', 'at8-moonlight-user-center' ); ?>
			</button>
		</p>

	<?php endif; ?>
</div>
