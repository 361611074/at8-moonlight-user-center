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

$user = isset( $args['user'] ) ? $args['user'] : null;

if ( ! $user instanceof WP_User ) {
	return;
}

$library         = isset( $args['library'] ) && is_array( $args['library'] ) ? $args['library'] : array();
$avatar_url      = isset( $args['avatar_url'] ) ? $args['avatar_url'] : '';
$at8_mluc_current_id = isset( $args['current_id'] ) ? absint( $args['current_id'] ) : 0;
?>
<div class="at8-mluc-card at8-mluc-avatar">

	<h3 class="at8-mluc-card-title"><?php esc_html_e( 'Avatar', 'at8-moonlight-user-center' ); ?></h3>

	<div class="at8-mluc-avatar-current">
		<img data-at8-mluc-avatar-preview
			src="<?php echo esc_url( $avatar_url ); ?>"
			width="96" height="96" loading="lazy" decoding="async"
			alt="<?php esc_attr_e( 'Your current avatar', 'at8-moonlight-user-center' ); ?>" />
	</div>

	<p class="at8-mluc-form-notice" id="at8-mluc-avatar-notice" role="status" aria-live="polite" hidden></p>

	<?php if ( empty( $library ) ) : ?>

		<p class="at8-mluc-message at8-mluc-message--info">
			<?php esc_html_e( 'No avatars are available yet. Please check back later.', 'at8-moonlight-user-center' ); ?>
		</p>

	<?php else : ?>

		<div class="at8-mluc-avatar-grid" data-at8-mluc-avatar-picker data-at8-mluc-notice="at8-mluc-avatar-notice">
			<?php foreach ( $library as $at8_mluc_item ) : ?>
				<?php
				$at8_mluc_is_active = ( (int) $at8_mluc_item['attachment_id'] === $at8_mluc_current_id );
				$at8_mluc_label     = '' !== $at8_mluc_item['title']
					? $at8_mluc_item['title']
					/* translators: %s: avatar number. */
					: sprintf( __( 'Avatar %s', 'at8-moonlight-user-center' ), number_format_i18n( $at8_mluc_item['id'] ) );
				$at8_mluc_classes   = 'at8-mluc-avatar-option' . ( $at8_mluc_is_active ? ' is-active' : '' );
				$at8_mluc_pressed   = $at8_mluc_is_active ? 'true' : 'false';
				?>
				<button type="button"
					class="<?php echo esc_attr( $at8_mluc_classes ); ?>"
					data-at8-mluc-avatar-id="<?php echo esc_attr( (string) $at8_mluc_item['id'] ); ?>"
					aria-pressed="<?php echo esc_attr( $at8_mluc_pressed ); ?>">
					<img src="<?php echo esc_url( $at8_mluc_item['url'] ); ?>"
						width="72" height="72" loading="lazy" decoding="async"
						alt="<?php echo esc_attr( $at8_mluc_label ); ?>" />
					<span class="screen-reader-text"><?php echo esc_html( $at8_mluc_label ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>

		<p class="at8-mluc-actions">
			<button type="button" class="at8-mluc-button at8-mluc-button--link"
				data-at8-mluc-avatar-reset="reset"
				data-at8-mluc-avatar-id="">
				<?php esc_html_e( 'Use the default avatar', 'at8-moonlight-user-center' ); ?>
			</button>
		</p>

	<?php endif; ?>
</div>
