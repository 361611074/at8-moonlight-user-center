<?php
/**
 * Storage-layer suite for the points account (2.6.0).
 *
 * Asserts the properties the concurrency suite cannot see directly:
 * schema shape, fence monotonicity, transaction atomicity, lazy migration
 * idempotency and repair.
 *
 * Run: wp eval-file atomicity_test.php
 */

global $wpdb;

$GLOBALS['ap'] = 0;
$GLOBALS['af'] = 0;

/**
 * Record one assertion.
 *
 * @param bool   $condition Assertion.
 * @param string $label     Description.
 * @return void
 */
function aok( $condition, $label ) {
	if ( $condition ) {
		$GLOBALS['ap']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['af']++;
		echo "FAIL  $label\n";
	}
}

/**
 * Create a throwaway user.
 *
 * @return int
 */
function at_user() {
	$name = 'at_' . wp_generate_password( 6, false );

	return (int) wp_create_user( $name, 'x!*At12345', $name . '@example.test' );
}

/**
 * Remove a fixture user and its rows.
 *
 * @param int $uid User ID.
 * @return void
 */
function at_cleanup( $uid ) {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}at8mluc_points_ledger WHERE user_id = %d", $uid ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}at8mluc_points_account WHERE user_id = %d", $uid ) );
	wp_delete_user( $uid );
}

/**
 * Read the raw account row.
 *
 * @param int $uid User ID.
 * @return array<string, mixed>|null
 */
function at_account( $uid ) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare( "SELECT balance, fence FROM {$wpdb->prefix}at8mluc_points_account WHERE user_id = %d", $uid ),
		ARRAY_A
	);
}

/* ------------------------------------------- 1. 表结构与索引 */

$ledger  = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}at8mluc_points_ledger'" );
$account = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}at8mluc_points_account'" );

aok( $ledger === $wpdb->prefix . 'at8mluc_points_ledger', 'the ledger table exists' );
aok( $account === $wpdb->prefix . 'at8mluc_points_account', 'the account table exists' );

$indexes = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}at8mluc_points_ledger", ARRAY_A );
$unique  = array();

foreach ( (array) $indexes as $index ) {
	if ( ! (int) $index['Non_unique'] && 'PRIMARY' !== $index['Key_name'] ) {
		$unique[ $index['Key_name'] ][] = strtolower( (string) $index['Column_name'] );
	}
}

$has_idem = false;
foreach ( $unique as $columns ) {
	if ( array( 'user_id', 'idem_key' ) === $columns ) {
		$has_idem = true;
	}
}

aok( $has_idem, 'the ledger carries a UNIQUE (user_id, idem_key) index' );

/* ------------------------------------------- 2. fence 单调递增 */

$uid = at_user();
AT8MLUC_Points::adjust( $uid, 10, 'first', 'admin' );
$after_first = at_account( $uid );
AT8MLUC_Points::adjust( $uid, 10, 'second', 'admin' );
$after_second = at_account( $uid );

aok( 1 === (int) $after_first['fence'], 'the first movement takes the account from version 0 to 1' );
aok( 2 === (int) $after_second['fence'], 'the next movement moves the version forward to 2' );
aok( 20 === (int) $after_second['balance'], 'the balance accumulates both movements' );

$fences = $wpdb->get_col( $wpdb->prepare( "SELECT fence FROM {$wpdb->prefix}at8mluc_points_ledger WHERE user_id = %d ORDER BY id", $uid ) );
aok( array( 1, 2 ) === array_map( 'intval', $fences ), 'every ledger row records the version it was written at' );

/* ------------------------------------------- 3. 过期版本写入被数据库拒绝 */

$stale = $wpdb->query(
	$wpdb->prepare(
		"UPDATE {$wpdb->prefix}at8mluc_points_account SET balance = balance + %d, fence = %d WHERE user_id = %d AND fence = %d",
		500,
		2,
		$uid,
		1
	)
);

aok( 0 === (int) $stale, 'a write carrying an old version affects no row' );
aok( 20 === (int) AT8MLUC_Points::balance( $uid ), 'the newer balance is untouched by the stale write' );

/* ------------------------------------------- 4. 交易原子性 */

$before = at_account( $uid );

$break = function ( $query ) {
	if ( false !== strpos( $query, 'INSERT INTO wp_at8mluc_points_ledger' ) ) {
		return 'INSERT INTO wp_at8mluc_points_ledger (no_such_column) VALUES (1)';
	}

	return $query;
};
add_filter( 'query', $break );
$failed = AT8MLUC_Points::adjust( $uid, 40, 'atomicity probe', 'admin' );
remove_filter( 'query', $break );

$after = at_account( $uid );

aok( is_wp_error( $failed ), 'a refused ledger insert is reported as an error' );
aok( (int) $before['balance'] === (int) $after['balance'], 'the balance is rolled back when the ledger insert fails' );
aok( (int) $before['fence'] === (int) $after['fence'], 'the version is rolled back too, so the next writer is not blocked' );
aok( 2 === count( AT8MLUC_Points::ledger( $uid, 50 ) ), 'no orphan ledger row survives the rollback' );

/* ------------------------------------------- 5. 幂等键由数据库裁决 */

$r1 = AT8MLUC_Points::adjust( $uid, 10, 'keyed', 'admin', 'purchase:at-1' );
$r2 = AT8MLUC_Points::adjust( $uid, 10, 'keyed repeat', 'admin', 'purchase:at-1' );

aok( 30 === $r1, 'the first keyed movement applies (30)' );
aok( 30 === $r2, 'the repeat reports the same balance (30)' );
aok( 3 === count( AT8MLUC_Points::ledger( $uid, 50 ) ), 'the repeat writes no second row' );

// The database itself refuses the duplicate, whatever the application does.
$wpdb->suppress_errors( true );
$duplicate = $wpdb->query(
	$wpdb->prepare(
		"INSERT INTO {$wpdb->prefix}at8mluc_points_ledger (user_id, amount, reason, source, by_user, idem_key, fence, created_at) VALUES (%d, 1, 'raw duplicate', 'admin', 0, %s, 9, %d)",
		$uid,
		'purchase:at-1',
		time()
	)
);
$wpdb->suppress_errors( false );

aok( false === $duplicate, 'a raw duplicate insert is rejected by the UNIQUE index' );

/* ------------------------------------------- 6. 迁移幂等 */

$legacy = at_user();
add_user_meta( $legacy, 'at8mluc_points_entry', array( 'time' => time() - 60, 'amount' => 9, 'reason' => 'Legacy', 'source' => 'admin', 'by' => 0, 'key' => '', 'u' => 'at-legacy-1' ) );
add_user_meta( $legacy, 'at8mluc_points_entry', array( 'time' => time() - 30, 'amount' => 3, 'reason' => 'Legacy 2', 'source' => 'admin', 'by' => 0, 'key' => 'legacy:key', 'u' => 'at-legacy-2' ) );

aok( 12 === (int) AT8MLUC_Points::balance( $legacy ), 'legacy meta migrates to the same balance (12)' );
aok( 2 === count( AT8MLUC_Points::ledger( $legacy, 50 ) ), 'both legacy movements became rows' );
aok( 12 === (int) AT8MLUC_Points::balance( $legacy ), 'a second read does not duplicate anything' );
aok( 2 === count( AT8MLUC_Points::ledger( $legacy, 50 ) ), 'the ledger still holds two rows after repeated reads' );

$legacy_row = at_account( $legacy );
aok( 0 === (int) $legacy_row['fence'], 'the migrated account starts at version 0' );

aok( 13 === AT8MLUC_Points::adjust( $legacy, 1, 'after migration', 'admin' ), 'a movement on a migrated account applies (13)' );

/* ------------------------------------------- 7. 修复 */

$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}at8mluc_points_account SET balance = 999 WHERE user_id = %d", $uid ) );
aok( 999 === (int) AT8MLUC_Points::balance( $uid ), 'a tampered account row is visible before the repair' );
aok( 30 === AT8MLUC_Points::reconcile( $uid ), 'reconcile() rebuilds it from the ledger (30)' );
aok( 30 === (int) AT8MLUC_Points::balance( $uid ), 'reads return the repaired balance' );

/* ------------------------------------------- 8. 零与负数 */

aok( 30 === AT8MLUC_Points::adjust( $uid, 0, 'noop', 'admin' ), 'a zero movement is a no-op that reports the balance' );
aok( is_wp_error( AT8MLUC_Points::adjust( $uid, -31, 'overdraft', 'admin' ) ), 'an overdraft is rejected' );
aok( 30 === (int) AT8MLUC_Points::balance( $uid ), 'the rejected overdraft leaves the balance alone' );
aok( 0 === AT8MLUC_Points::adjust( $uid, -30, 'exact', 'admin' ), 'spending the exact balance is allowed (0)' );

$reasons = wp_list_pluck( AT8MLUC_Points::ledger( $uid, 50 ), 'reason' );
aok( ! in_array( 'overdraft', $reasons, true ), 'the rejected overdraft wrote no row' );
aok( 4 === count( $reasons ), 'the ledger holds exactly the four accepted movements (' . count( $reasons ) . ')' );

at_cleanup( $uid );
at_cleanup( $legacy );

echo "==== {$GLOBALS['ap']} passed, {$GLOBALS['af']} failed ====\n";
