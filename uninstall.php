<?php
/**
 * Uninstall routine.
 *
 * Runs only when the site owner deletes the plugin from the Plugins screen.
 * Data is removed only when the "Delete data on uninstall" setting is enabled,
 * so nothing is ever discarded silently.
 *
 * @package AT8_Moonlight_User_Center
 */

// This file is executed by WordPress outside of the normal plugin bootstrap.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * functions.php only declares functions, so it is safe to load directly and
 * gives this script the single shared meta key list used by the privacy tools.
 */
require_once __DIR__ . '/includes/functions.php';

if ( ! defined( 'AT8_MLUC_UNINSTALL_OPTION' ) ) {
	define( 'AT8_MLUC_UNINSTALL_OPTION', 'at8_mluc_options' );
}

/**
 * Remove every trace of the plugin from a single site.
 *
 * @return void
 */
function at8_mluc_uninstall_site() {
	$options = get_option( AT8_MLUC_UNINSTALL_OPTION, array() );

	$delete_data = is_array( $options ) && ! empty( $options['delete_data'] );

	// Transients and scheduled events are always cleaned up, because they are
	// cache state rather than user content.
	at8_mluc_uninstall_clear_transients();
	wp_clear_scheduled_hook( 'at8_mluc_daily_maintenance' );

	if ( ! $delete_data ) {
		return;
	}

	/*
	 * The meta key list lives in functions.php so that the privacy exporter,
	 * the privacy eraser and this file always remove exactly the same keys.
	 * Only the plugin's own prefixed keys are ever touched.
	 */
	$meta_keys = at8_mluc_user_meta_keys();

	foreach ( $meta_keys as $meta_key ) {
		delete_metadata( 'user', 0, $meta_key, '', true );
	}

	// Social login bindings use dynamic per-provider keys
	// (at8_mluc_oauth_<provider>_id), so they are matched by prefix.
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup has no cache to use and must be exhaustive.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'at8_mluc_oauth_' ) . '%'
		)
	);

	// Custom field values use dynamic per-field keys (at8_mluc_field_<name>).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup has no cache to use and must be exhaustive.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'at8_mluc_field_' ) . '%'
		)
	);

	// Avatars published by an administrator are plugin content: remove them
	// together with their meta, but never touch other post types.
	$avatar_posts = get_posts(
		array(
			'post_type'        => 'at8_mluc_avatar',
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => false,
		)
	);

	foreach ( $avatar_posts as $avatar_id ) {
		wp_delete_post( (int) $avatar_id, true );
	}

	// Membership orders are plugin content as well.
	$order_posts = get_posts(
		array(
			'post_type'        => 'at8_mluc_order',
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => false,
		)
	);

	foreach ( $order_posts as $order_id ) {
		wp_delete_post( (int) $order_id, true );
	}

	wp_clear_scheduled_hook( 'at8_mluc_pay_autoclose' );

	delete_option( AT8_MLUC_UNINSTALL_OPTION );
	delete_option( 'at8_mluc_version' );
}

/**
 * Delete the plugin's rate limit transients.
 *
 * @return void
 */
function at8_mluc_uninstall_clear_transients() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup has no cache to use and must be exhaustive.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_at8_mluc_rl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_at8_mluc_rl_' ) . '%',
			$wpdb->esc_like( 'at8_mluc_points_lock_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup has no cache to use and must be exhaustive.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_at8_mluc_reg_tk_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_at8_mluc_reg_tk_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	$at8_mluc_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $at8_mluc_site_ids as $at8_mluc_site_id ) {
		switch_to_blog( (int) $at8_mluc_site_id );
		at8_mluc_uninstall_site();
		restore_current_blog();
	}
} else {
	at8_mluc_uninstall_site();
}
