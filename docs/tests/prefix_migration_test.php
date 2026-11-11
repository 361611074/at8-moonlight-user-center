<?php
/**
 * Regression suite for the 2.7.0 rename migration.
 *
 * The migration runs unattended on live sites, so the properties that matter
 * are not "does it copy" but "what does it leave behind when something goes
 * wrong". Every assertion below is about a state the site can survive:
 * nothing overwritten, nothing reported as done that is not done, no second
 * sweep while the first is still running.
 *
 * Run: wp eval-file prefix_migration_test.php
 */

global $wpdb;

$GLOBALS['pm_pass'] = 0;
$GLOBALS['pm_fail'] = 0;

/**
 * Record one assertion.
 *
 * @param bool   $condition Assertion.
 * @param string $label     Description.
 * @return void
 */
function pm_ok( $condition, $label ) {
	if ( $condition ) {
		$GLOBALS['pm_pass']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['pm_fail']++;
		echo "FAIL  $label\n";
	}
}

/**
 * Create a throwaway user.
 *
 * @return int
 */
function pm_user() {
	$name = 'pm_' . wp_generate_password( 6, false );
	$id   = wp_create_user( $name, wp_generate_password( 20, false ), $name . '@example.invalid' );

	if ( is_wp_error( $id ) ) {
		echo "SETUP FAILED: {$id->get_error_message()}\n";
		exit( 1 );
	}

	return (int) $id;
}

/**
 * Reset every marker the migration uses, so the next call runs from scratch.
 *
 * @return void
 */
function pm_reset_markers() {
	delete_option( AT8MLUC_Prefix_Migration::DONE_OPTION );
	delete_option( AT8MLUC_Prefix_Migration::LOCK_OPTION );
	delete_option( AT8MLUC_Prefix_Migration::LAST_ERROR_OPTION );
	delete_transient( AT8MLUC_Prefix_Migration::RETRY_TRANSIENT );
}

/**
 * Row count of one of the points tables, or -1 when it cannot be read.
 *
 * @param string $table Table name.
 * @return int
 */
function pm_rows( $table ) {
	global $wpdb;

	$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

	return null === $count ? -1 : (int) $count;
}

$ledger  = $wpdb->prefix . 'at8mluc_points_ledger';
$account = $wpdb->prefix . 'at8mluc_points_account';

/*
 * A site that already completed the migration: the run below must leave the
 * existing points rows exactly as they were, because a second copy would
 * double every balance.
 */
$ledger_before  = pm_rows( $ledger );
$account_before = pm_rows( $account );

pm_reset_markers();

// ---------------------------------------------------------------- fixtures ---
$uid = pm_user();

/*
 * Option copying only covers the plugin's own known option names, so the
 * fixture has to be one of them. The property under test is the guard, not
 * the copy: a name that already has a value must never be overwritten.
 */
$sentinel       = 'pm_absent';
$option_before  = get_option( 'at8mluc_custom_fields', $sentinel );
$option_expected = ( $sentinel === $option_before ) ? array( 'pm' => 'sentinel' ) : $option_before;

update_option( 'at8_mluc_custom_fields', array( 'pm' => 'sentinel' ), false );

add_user_meta( $uid, 'at8_mluc_test_flag', 'first', false );
add_user_meta( $uid, 'at8_mluc_test_flag', 'second', false );

$post_id = wp_insert_post(
	array(
		'post_type'   => 'at8_mluc_avatar',
		'post_title'  => 'PM migration fixture',
		'post_status' => 'publish',
	)
);

update_post_meta( $post_id, '_at8_mluc_test_field', 'value' );

// ------------------------------------------------------------------- run 1 ---
AT8MLUC_Prefix_Migration::maybe_run();

pm_ok( $option_expected === get_option( 'at8mluc_custom_fields', $sentinel ), 'options: an empty new name is filled, an existing one is never overwritten' );
pm_ok( array( 'pm' => 'sentinel' ) === get_option( 'at8_mluc_custom_fields', null ), 'options: the old row is left in place, not deleted' );

$flag = get_user_meta( $uid, 'at8mluc_test_flag', false );

pm_ok( 2 === count( $flag ), 'user meta: every row of a repeated key moves (' . count( $flag ) . ' of 2)' );
pm_ok( array() === get_user_meta( $uid, 'at8_mluc_test_flag', false ), 'user meta: no row is left under the old key' );

pm_ok( 'value' === get_post_meta( $post_id, '_at8mluc_test_field', true ), 'post meta: the underscore key moves' );
pm_ok( 'value' !== get_post_meta( $post_id, '_at8_mluc_test_field', true ), 'post meta: the old name is gone' );

clean_post_cache( $post_id );
pm_ok( 'at8mluc_avatar' === get_post_type( $post_id ), 'post types: the avatar post is moved to the new slug' );

pm_ok( AT8MLUC_VERSION === (string) get_option( AT8MLUC_Prefix_Migration::DONE_OPTION, '' ), 'marker: a clean run records the completion marker' );
pm_ok( false === get_option( AT8MLUC_Prefix_Migration::LOCK_OPTION, false ), 'lock: the lease is released after the run' );
pm_ok( false === get_transient( AT8MLUC_Prefix_Migration::RETRY_TRANSIENT ), 'retry: a clean run schedules no retry' );

pm_ok( $ledger_before === pm_rows( $ledger ), 'points: the ledger row count is unchanged (' . $ledger_before . ')' );
pm_ok( $account_before === pm_rows( $account ), 'points: the account row count is unchanged (' . $account_before . ')' );

// ------------------------------------------------------------------- run 2 ---
AT8MLUC_Prefix_Migration::maybe_run();

pm_ok( $ledger_before === pm_rows( $ledger ), 'a second run copies no points rows twice' );
pm_ok( 2 === count( get_user_meta( $uid, 'at8mluc_test_flag', false ) ), 'a second run does not duplicate meta rows' );

// ----------------------------------------------------------------- conflict ---
/*
 * Both names present: the row the site is already reading must win and the
 * old row must survive. Nothing is overwritten and nothing is deleted.
 */
delete_user_meta( $uid, 'at8mluc_test_flag' );
add_user_meta( $uid, 'at8_mluc_test_flag', 'legacy', false );
add_user_meta( $uid, 'at8mluc_test_flag', 'current', false );

pm_reset_markers();
AT8MLUC_Prefix_Migration::maybe_run();

$current = get_user_meta( $uid, 'at8mluc_test_flag', false );
$legacy  = get_user_meta( $uid, 'at8_mluc_test_flag', false );

pm_ok( array( 'current' ) === $current, 'conflict: the value the site already has is not overwritten' );
pm_ok( array( 'legacy' ) === $legacy, 'conflict: the older row is kept, not deleted' );
pm_ok( AT8MLUC_VERSION === (string) get_option( AT8MLUC_Prefix_Migration::DONE_OPTION, '' ), 'conflict: an already-settled key does not block completion' );

// --------------------------------------------------------------------- lock ---
/*
 * A live lease belongs to another request: this one must do nothing at all,
 * not even write the marker.
 */
add_user_meta( $uid, 'at8_mluc_probe_lock', 'untouched', false );

pm_reset_markers();
update_option( AT8MLUC_Prefix_Migration::LOCK_OPTION, 'someone_else|' . ( time() + 300 ), false );

AT8MLUC_Prefix_Migration::maybe_run();

pm_ok( array() === get_user_meta( $uid, 'at8mluc_probe_lock', false ), 'lock: a held lease stops the run before any work' );
pm_ok( array( 'untouched' ) === get_user_meta( $uid, 'at8_mluc_probe_lock', false ), 'lock: the fixture is still under its old key' );
pm_ok( '' === (string) get_option( AT8MLUC_Prefix_Migration::DONE_OPTION, '' ), 'lock: a skipped run writes no completion marker' );
pm_ok( 0 === strpos( (string) get_option( AT8MLUC_Prefix_Migration::LOCK_OPTION, '' ), 'someone_else|' ), 'lock: the other lease is left untouched' );

// -------------------------------------------------------------- stale lease ---
/*
 * An expired lease is taken over, so a request that dies mid-run cannot block
 * the site forever.
 */
update_option( AT8MLUC_Prefix_Migration::LOCK_OPTION, 'stale_holder|' . ( time() - 60 ), false );

AT8MLUC_Prefix_Migration::maybe_run();

pm_ok( array( 'untouched' ) === get_user_meta( $uid, 'at8mluc_probe_lock', false ), 'stale lease: an expired lease is taken over and the run proceeds' );
pm_ok( false === get_option( AT8MLUC_Prefix_Migration::LOCK_OPTION, false ), 'stale lease: the taken-over lease is released by its new holder' );
pm_ok( AT8MLUC_VERSION === (string) get_option( AT8MLUC_Prefix_Migration::DONE_OPTION, '' ), 'stale lease: the taken-over run completes normally' );

// ------------------------------------------------------------------- backoff ---
add_user_meta( $uid, 'at8_mluc_probe_backoff', 'untouched', false );

pm_reset_markers();
set_transient( AT8MLUC_Prefix_Migration::RETRY_TRANSIENT, 1, 900 );

AT8MLUC_Prefix_Migration::maybe_run();

pm_ok( array() === get_user_meta( $uid, 'at8mluc_probe_backoff', false ), 'backoff: a scheduled retry holds the run back' );
pm_ok( false === get_option( AT8MLUC_Prefix_Migration::LOCK_OPTION, false ), 'backoff: a held-back run takes no lease' );

// ------------------------------------------------------------- failure path ---
/*
 * Force a failing step the way a broken database would: rewrite the meta
 * UPDATE into a statement the driver rejects, so the step has to report a
 * failure rather than a silent zero.
 */
pm_reset_markers();

$GLOBALS['pm_fail_open'] = 0;

add_filter(
	'query',
	function ( $query ) {
		global $wpdb;

		if ( 0 === strpos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, 'usermeta' ) && false !== strpos( $query, 'at8mluc_' ) ) {
			$GLOBALS['pm_fail_open'] = 1;

			return 'UPDATE ' . $wpdb->usermeta . ' SET meta_key = meta_key WHERE 1 = 0 AND no_such_column = 1';
		}

		return $query;
	}
);

add_user_meta( $uid, 'at8_mluc_test_fail', 'value', false );

AT8MLUC_Prefix_Migration::maybe_run();

pm_ok( 1 === $GLOBALS['pm_fail_open'], 'failure: the injected statement reached the driver' );
pm_ok( '' === (string) get_option( AT8MLUC_Prefix_Migration::DONE_OPTION, '' ), 'failure: a failed step leaves the completion marker unwritten' );
pm_ok( false !== get_transient( AT8MLUC_Prefix_Migration::RETRY_TRANSIENT ), 'failure: a failed run schedules a retry' );
pm_ok( is_array( get_option( AT8MLUC_Prefix_Migration::LAST_ERROR_OPTION, null ) ), 'failure: the failing steps are recorded' );
pm_ok( false === get_option( AT8MLUC_Prefix_Migration::LOCK_OPTION, false ), 'failure: the lease is released even when a step fails' );

// ------------------------------------------------------------------- cleanup ---
delete_user_meta( $uid, 'at8mluc_test_fail' );
delete_user_meta( $uid, 'at8_mluc_test_fail' );
delete_user_meta( $uid, 'at8mluc_test_flag' );
delete_user_meta( $uid, 'at8_mluc_test_flag' );
delete_user_meta( $uid, 'at8mluc_probe_lock' );
delete_user_meta( $uid, 'at8_mluc_probe_lock' );
delete_user_meta( $uid, 'at8mluc_probe_backoff' );
delete_user_meta( $uid, 'at8_mluc_probe_backoff' );
delete_option( 'at8_mluc_custom_fields' );

if ( $sentinel === $option_before ) {
	// The fixture filled a name that had no value before this run.
	delete_option( 'at8mluc_custom_fields' );
}

wp_delete_post( $post_id, true );
wp_delete_user( $uid );

pm_reset_markers();

/*
 * Leave the site in the state a healthy install has: migration marked done,
 * because everything above is a fixture and the real rows were migrated long
 * ago.
 */
update_option( AT8MLUC_Prefix_Migration::DONE_OPTION, AT8MLUC_VERSION, false );

echo "==== {$GLOBALS['pm_pass']} passed, {$GLOBALS['pm_fail']} failed ====\n";
