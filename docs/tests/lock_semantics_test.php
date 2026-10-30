<?php
/**
 * Lock semantics tests: fencing, takeover, stale owners, hook overruns,
 * crash recovery. Runs in one process but manipulates the lock row exactly
 * the way a competing process would.
 *
 * Run: wp eval-file lock_semantics_test.php
 */

$GLOBALS['lp'] = 0;
$GLOBALS['lf'] = 0;
function lok( $c, $l ) {
	if ( $c ) {
		$GLOBALS['lp']++;
		echo "PASS  $l\n";
	} else {
		$GLOBALS['lf']++;
		echo "FAIL  $l\n";
	}
}

function l_user() {
	$name = 'lk_' . wp_generate_password( 6, false );

	return (int) wp_create_user( $name, 'x!*Lk12345', $name . '@example.test' );
}

function l_rows( $uid ) {
	return AT8_MLUC_Points::ledger( $uid, 100 );
}

function l_lock_row( $uid ) {
	global $wpdb;

	return $wpdb->get_var(
		$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'at8_mluc_points_lock_' . (int) $uid )
	);
}

function l_set_lock_row( $uid, $value ) {
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
			$value,
			'at8_mluc_points_lock_' . (int) $uid
		)
	);
}

function l_expire_lock( $uid ) {
	$cur = l_lock_row( $uid );

	if ( null === $cur ) {
		return;
	}

	$parts    = explode( '|', $cur );
	$parts[1] = (string) ( time() - 30 );
	l_set_lock_row( $uid, implode( '|', $parts ) );
}

function l_call( $class, $method, $args ) {
	$m = new ReflectionMethod( $class, $method );
	$m->setAccessible( true );

	// Pass by reference for apply_locked's &$lock parameter.
	$refs = array();
	foreach ( $args as $i => $v ) {
		$refs[ $i ] = &$args[ $i ];
	}

	return $m->invokeArgs( null, $refs );
}

/* ---------- T1：锁被接管后，原持有者的写入被拒绝，且不留任何残留 ---------- */

$uid = l_user();
$lock = l_call( 'AT8_MLUC_Points', 'lock', array( $uid ) );
lok( is_array( $lock ), 'T1: the mutex is acquired' );

$before_rows    = count( l_rows( $uid ) );
$before_balance = AT8_MLUC_Points::balance( $uid );

// Simulate another process taking the lease over: it swaps the row value and
// moves the fence forward.
$takeover = 'rivaltoken|' . ( time() + 10 ) . '|2';
l_set_lock_row( $uid, $takeover );

$lock_copy = $lock;
$applied   = l_call( 'AT8_MLUC_Points', 'apply_locked', array( $uid, 10, 'stale writer', 'admin', '', $lock_copy ) );

lok( is_wp_error( $applied ) && 'at8_mluc_points_lock' === $applied->get_error_code(), 'T1: the stale owner is rejected with a lock error' );
lok( $before_rows === count( l_rows( $uid ) ), 'T1: the stale owner writes no ledger row' );
lok( $before_balance === AT8_MLUC_Points::balance( $uid ), 'T1: the stale owner does not move the balance' );

// The stale owner must not delete the row the new owner now holds.
l_call( 'AT8_MLUC_Points', 'unlock', array( $lock ) );
lok( 0 === strpos( (string) l_lock_row( $uid ), 'rivaltoken' ), 'T1: the stale owner cannot release the new owner\'s lock' );

l_set_lock_row( $uid, 'x' );
l_call( 'AT8_MLUC_Points', 'unlock', array( array( 'name' => 'at8_mluc_points_lock_' . $uid, 'value' => 'x' ) ) );
global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'at8_mluc_points_lock_' . $uid ) );
wp_delete_user( $uid );

/* ---------- T2：钩子耗时期间锁被接管 → 请求中止且无半写 ---------- */

$uid = l_user();
$hook_takeover = function ( $locked_uid ) {
	if ( (int) $locked_uid !== (int) $GLOBALS['lk_hook_uid'] ) {
		return;
	}

	// Stand in for a slow third-party hook: while it runs, the lease lapses
	// and another process takes over.
	l_expire_lock( (int) $locked_uid );
	l_set_lock_row( (int) $locked_uid, 'hookrival|' . ( time() + 10 ) . '|2' );
};
$GLOBALS['lk_hook_uid'] = $uid;
add_action( 'at8_mluc_points_lock_acquired', $hook_takeover );

$res = AT8_MLUC_Points::adjust( $uid, 15, 'hook overrun', 'admin' );
remove_action( 'at8_mluc_points_lock_acquired', $hook_takeover );

lok( is_wp_error( $res ) && 'at8_mluc_points_lock' === $res->get_error_code(), 'T2: an overrun hook cannot let the request write (lock error)' );
lok( 0 === count( l_rows( $uid ) ), 'T2: no ledger row is left behind (' . count( l_rows( $uid ) ) . ')' );
lok( 0 === AT8_MLUC_Points::balance( $uid ), 'T2: the balance did not move' );

global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'at8_mluc_points_lock_' . $uid ) );
wp_delete_user( $uid );

/* ---------- T3：进程异常退出（锁残留）后重新发放 ---------- */

$uid  = l_user();
$lock = l_call( 'AT8_MLUC_Points', 'lock', array( $uid ) );
lok( is_array( $lock ), 'T3: a lease is held and then abandoned (no unlock)' );

// Simulate the crashed process: the row is left behind and lapses.
l_expire_lock( $uid );

$res = AT8_MLUC_Points::adjust( $uid, 20, 'recovered after crash', 'admin' );

lok( ! is_wp_error( $res ) && 20 === (int) $res, 'T3: the next request takes the lapsed lease over and awards (20)' );
lok( 1 === count( l_rows( $uid ) ), 'T3: exactly one ledger row for the recovered award' );
lok( null === l_lock_row( $uid ), 'T3: the lease is released after the takeover completes' );
wp_delete_user( $uid );

/* ---------- T4：fence 只前进，不后退 ---------- */

$uid  = l_user();
$lock = l_call( 'AT8_MLUC_Points', 'lock', array( $uid ) );
$v1   = (int) explode( '|', $lock['value'] )[2];
$lock = l_call( 'AT8_MLUC_Points', 'renew_lock', array( $lock ) );
$v2   = (int) explode( '|', $lock['value'] )[2];
$lock = l_call( 'AT8_MLUC_Points', 'renew_lock', array( $lock ) );
$v3   = (int) explode( '|', $lock['value'] )[2];

lok( 1 === $v1 && $v2 === $v1 + 1 && $v3 === $v2 + 1, 'T4: the fence increments on every renewal (1 -> 2 -> 3)' );
l_call( 'AT8_MLUC_Points', 'unlock', array( $lock ) );
lok( null === l_lock_row( $uid ), 'T4: the owner can release its own lease' );
wp_delete_user( $uid );

/* ---------- T5：进程退出后的 claim 接管（与锁并行） ---------- */

$uid  = l_user();
$name = 'at8_mluc_points_claim_' . md5( (int) $uid . '|purchase:999' );
global $wpdb;
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, 'dead|' . ( time() - 120 ) ) );

$claim = l_call( 'AT8_MLUC_Points', 'claim', array( $uid, 'purchase:999' ) );
lok( is_array( $claim ), 'T5: an abandoned claim is taken over through compare-and-swap' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
wp_delete_user( $uid );

echo "==== {$GLOBALS['lp']} passed, {$GLOBALS['lf']} failed ====\n";
