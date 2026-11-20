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

if ( ! defined( 'AT8MLUC_UNINSTALL_OPTION' ) ) {
	define( 'AT8MLUC_UNINSTALL_OPTION', 'at8mluc_options' );
}

/**
 * Remove every trace of the plugin from a single site.
 *
 * @return void
 */
function at8mluc_uninstall_site() {
	$options = get_option( AT8MLUC_UNINSTALL_OPTION, array() );

	$delete_data = is_array( $options ) && ! empty( $options['delete_data'] );

	// Transients and scheduled events are always cleaned up, because they are
	// scheduler and cache state rather than user content. The legacy hook name
	// is cleared too: the 2.7.0 rename left its hourly event behind, and a
	// hook with no listener still costs a cron run.
	at8mluc_uninstall_clear_transients();
	wp_clear_scheduled_hook( 'at8mluc_pay_autoclose' );
	wp_clear_scheduled_hook( 'at8_mluc_pay_autoclose' );

	if ( ! $delete_data ) {
		return;
	}

	/*
	 * The meta key list lives in functions.php so that the privacy exporter,
	 * the privacy eraser and this file always remove exactly the same keys.
	 * Only the plugin's own prefixed keys are ever touched.
	 */
	$meta_keys = at8mluc_user_meta_keys();

	foreach ( $meta_keys as $meta_key ) {
		delete_metadata( 'user', 0, $meta_key, '', true );
	}

	/*
	 * Meta keys that carry a dynamic suffix: social login bindings
	 * (at8mluc_oauth_<provider>_id) and custom field values
	 * (at8mluc_field_<name>). The static keys are listed in functions.php and
	 * removed above; these two families cannot be listed, so they are matched
	 * by prefix. The old at8_mluc_ prefix is matched as well — a site that
	 * upgraded from 2.6.x still carries keys under it.
	 */
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup has no cache to use and must be exhaustive.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			$wpdb->esc_like( 'at8mluc_' ) . '%',
			$wpdb->esc_like( 'at8_mluc_' ) . '%'
		)
	);

	/*
	 * The points ledger and account tables belong to this plugin only. The
	 * names are listed rather than discovered: dropping a table whose name came
	 * out of a query is a worse habit than naming the four tables this plugin
	 * has ever created. The old at8_mluc_ names are the ones the 2.7.0
	 * migration left behind on a site that upgraded from 2.6.x.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables on uninstall was explicitly requested by the site owner.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}at8mluc_points_ledger" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables on uninstall was explicitly requested by the site owner.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}at8mluc_points_account" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables on uninstall was explicitly requested by the site owner.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}at8_mluc_points_ledger" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables on uninstall was explicitly requested by the site owner.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}at8_mluc_points_account" );

	delete_option( 'at8mluc_points_db' );
	delete_option( 'at8_mluc_points_db' );

	// Avatars published by an administrator are plugin content: remove them
	// together with their meta, but never touch other post types.
	$avatar_posts = get_posts(
		array(
			'post_type'        => 'at8mluc_avatar',
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
			'post_type'        => 'at8mluc_order',
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => false,
		)
	);

	foreach ( $order_posts as $order_id ) {
		wp_delete_post( (int) $order_id, true );
	}

	delete_option( AT8MLUC_UNINSTALL_OPTION );
	delete_option( 'at8mluc_version' );

	/*
	 * Sweep whatever is left under the plugin's own prefixes. Listing keys one
	 * by one is how a marker added in a later version (the 2.7.0 prefix
	 * migration, for instance) ends up surviving an uninstall; both prefixes
	 * belong to this plugin alone, so a prefix sweep is exhaustive by
	 * construction. Each name goes through delete_option() rather than a raw
	 * DELETE so a persistent object cache is invalidated too.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup has no cache to use; delete_option() below handles the cache.
	$at8mluc_leftovers = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'at8mluc_' ) . '%',
			$wpdb->esc_like( 'at8_mluc_' ) . '%'
		)
	);

	foreach ( (array) $at8mluc_leftovers as $at8mluc_option_name ) {
		delete_option( $at8mluc_option_name );
	}
}

/**
 * Delete every transient this plugin wrote.
 *
 * The entries are found by name rather than from a fixed list. The list fell
 * behind twice — the buy and paywall rate-limit buckets, and the OAuth state
 * value — and a name introduced in a later version would survive an uninstall
 * the same way. The old `at8_mluc_` prefix is matched too, because a site that
 * never ran the 2.7.0 migration can still hold entries under it.
 *
 * Each name goes through delete_transient() rather than a raw DELETE so a
 * persistent object cache is invalidated as well.
 *
 * @return void
 */
function at8mluc_uninstall_clear_transients() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup has no cache to use; delete_transient() below handles the cache.
	$at8mluc_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_' ) . '%at8mluc%',
			$wpdb->esc_like( '_transient_' ) . '%at8_mluc%'
		)
	);

	foreach ( (array) $at8mluc_names as $at8mluc_name ) {
		$at8mluc_name = (string) $at8mluc_name;

		// A timeout row whose value row is already gone; delete_transient()
		// cannot reach it, so it is removed directly.
		if ( 0 === strpos( $at8mluc_name, '_transient_timeout_' ) ) {
			delete_option( $at8mluc_name );
			continue;
		}

		if ( 0 === strpos( $at8mluc_name, '_transient_' ) ) {
			delete_transient( substr( $at8mluc_name, strlen( '_transient_' ) ) );
		}
	}
}

if ( is_multisite() ) {
	$at8mluc_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $at8mluc_site_ids as $at8mluc_site_id ) {
		switch_to_blog( (int) $at8mluc_site_id );
		at8mluc_uninstall_site();
		restore_current_blog();
	}
} else {
	at8mluc_uninstall_site();
}
