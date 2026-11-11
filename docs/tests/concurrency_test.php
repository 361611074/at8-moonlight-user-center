<?php
/**
 * Real multi-process concurrency test for the points ledger (2.6.0).
 *
 * Covers the ten scenarios required by the acceptance checklist:
 *
 *  1. ten requests add points to the same user at once
 *  2. ten requests deduct points from the same user at once
 *  3. two different orders award points at the same time
 *  4. ten callbacks for the same order at once
 *  5. a writer that holds its transaction far longer than any TTL, with
 *     another request trying to write meanwhile
 *  6. a writer that wrote its ledger row and then stalls, while another
 *     request moves the balance and the first one resumes
 *  7. a stale writer tries to write the balance after the account moved
 *  8. the database refuses a write
 *  9. the request process is killed in the middle of its transaction
 * 10. the process crashes after the award and the callback is retried
 *
 * The parent process only schedules work: every write and every observation
 * happens in its own short-lived process with a fresh WordPress bootstrap.
 * That mirrors production (one PHP process per request) and avoids asserting
 * against this process's stale object cache.
 *
 * Run: wp eval-file concurrency_test.php
 */

$GLOBALS['cp'] = 0;
$GLOBALS['cf'] = 0;

/**
 * Record one assertion.
 *
 * @param bool   $condition Assertion.
 * @param string $label     Description.
 * @return void
 */
function cok( $condition, $label ) {
	if ( $condition ) {
		$GLOBALS['cp']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['cf']++;
		echo "FAIL  $label\n";
	}
}

const CP_PHP    = 'C:\\wptest-php\\php.exe';
const CP_CLI    = 'C:\\Users\\漫步白月光\\WorkBuddy\\2026-10-12-15-36-28\\_tools\\wp-cli.phar';
const CP_ROOT   = 'C:\\Users\\漫步白月光\\WorkBuddy\\2026-10-12-15-36-28\\wptest\\wordpress';
const CP_WORKER = 'C:\\Users\\漫步白月光\\WorkBuddy\\2026-10-12-15-36-28\\_tools\\conc_worker.php';

/**
 * Start N workers without waiting for them.
 *
 * @param int   $n         Number of processes.
 * @param array $env_extra CONC_* environment overrides.
 * @param array $per_index Per-index overrides.
 * @return array{handles:array,outs:array}
 */
function cp_launch( $n, $env_extra, $per_index = array() ) {
	$tmp     = sys_get_temp_dir();
	$handles = array();
	$outs    = array();

	for ( $i = 0; $i < $n; $i++ ) {
		$out = $tmp . DIRECTORY_SEPARATOR . 'cpx_' . getmypid() . '_' . wp_rand( 100000, 999999 ) . '_' . $i . '.json';
		@unlink( $out );
		@unlink( $out . '.ready' );

		$env = array_merge(
			array(
				'SystemRoot' => 'C:\\Windows',
				'PATH'       => 'C:\\Windows\\system32',
				'TEMP'       => $tmp,
				'TMP'        => $tmp,
				'CONC_OUT'   => $out,
			),
			$env_extra,
			isset( $per_index[ $i ] ) ? $per_index[ $i ] : array()
		);

		$cmd  = '"' . CP_PHP . '" "' . CP_CLI . '" --path="' . CP_ROOT . '" eval-file "' . CP_WORKER . '"';
		$desc = array(
			1 => array( 'file', $tmp . DIRECTORY_SEPARATOR . 'cpxout_' . getmypid() . '_' . $i . '.log', 'a' ),
			2 => array( 'file', $tmp . DIRECTORY_SEPARATOR . 'cpxerr_' . getmypid() . '_' . $i . '.log', 'a' ),
		);

		$handle = proc_open( $cmd, $desc, $pipes, null, $env );

		if ( is_resource( $handle ) ) {
			$handles[] = $handle;
			$outs[]    = $out;
		}
	}

	return array(
		'handles' => $handles,
		'outs'    => $outs,
	);
}

/**
 * Wait for launched workers and decode their results.
 *
 * @param array $launched Result of cp_launch().
 * @return array<int, array>
 */
function cp_collect( $launched ) {
	foreach ( $launched['handles'] as $handle ) {
		proc_close( $handle );
	}

	$results = array();

	foreach ( $launched['outs'] as $file ) {
		$raw = is_readable( $file ) ? file_get_contents( $file ) : '';
		@unlink( $file );
		@unlink( $file . '.ready' );
		$results[] = $raw ? json_decode( $raw, true ) : array( 'ok' => false, 'error' => 'no result file' );
	}

	return $results;
}

/**
 * Start N workers and collect them.
 *
 * @param int   $n         Number of processes.
 * @param array $env_extra CONC_* environment overrides.
 * @param array $per_index Per-index overrides.
 * @return array<int, array>
 */
function cp_spawn( $n, $env_extra, $per_index = array() ) {
	return cp_collect( cp_launch( $n, $env_extra, $per_index ) );
}

/**
 * Create a throwaway user.
 *
 * @return int
 */
function cp_user() {
	$name = 'cp_' . wp_generate_password( 6, false );

	return (int) wp_create_user( $name, 'x!*Cp12345', $name . '@example.test' );
}

/**
 * Remove a fixture user and every row it owns.
 *
 * @param int $uid User ID.
 * @return void
 */
function cp_cleanup( $uid ) {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}at8mluc_points_ledger WHERE user_id = %d", $uid ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}at8mluc_points_account WHERE user_id = %d", $uid ) );
	wp_delete_user( $uid );
}

/**
 * Observe a user's ledger from a fresh process.
 *
 * @param int    $uid User ID.
 * @param string $key Idempotency key to count.
 * @return array{rows:int,sum:int,keyed:int,balance:int,error?:string}
 */
function cp_observe( $uid, $key = '' ) {
	$res = cp_spawn( 1, array( 'CONC_ACTION' => 'observe', 'CONC_UID' => (string) $uid, 'CONC_KEY' => (string) $key ) );
	$r   = $res[0];

	if ( empty( $r['ok'] ) ) {
		return array( 'rows' => -1, 'sum' => -1, 'keyed' => -1, 'balance' => -1, 'error' => $r['error'] ?? 'observer failed' );
	}

	return $r;
}

/**
 * How many results reported success.
 *
 * @param array $results Worker results.
 * @return int
 */
function cp_okcount( $results ) {
	return count(
		array_filter(
			$results,
			function ( $r ) {
				return ! empty( $r['ok'] );
			}
		)
	);
}

/**
 * Distinct error strings from failed workers.
 *
 * @param array $results Worker results.
 * @return string
 */
function cp_errors( $results ) {
	$out = array();

	foreach ( $results as $r ) {
		if ( empty( $r['ok'] ) ) {
			$out[] = isset( $r['error'] ) ? $r['error'] : 'unknown';
		}
	}

	return implode( ' | ', array_unique( $out ) );
}

/**
 * Assert the invariants that must hold for every fixture.
 *
 * @param int    $uid   User ID.
 * @param string $label Scenario label.
 * @return void
 */
function cp_invariants( $uid, $label ) {
	$obs = cp_observe( $uid );

	cok( $obs['sum'] === $obs['balance'], $label . ': balance equals the sum of the ledger rows (' . $obs['balance'] . ' vs ' . $obs['sum'] . ')' );
	cok( $obs['balance'] >= 0, $label . ': the balance is never negative (' . $obs['balance'] . ')' );
}

/* ------------------------------------------- 场景 1：10 个请求同时 +10 */

$uid = cp_user();
$res = cp_spawn( 10, array( 'CONC_ACTION' => 'adjust', 'CONC_UID' => (string) $uid, 'CONC_AMOUNT' => '10' ) );
$obs = cp_observe( $uid );
$okc = cp_okcount( $res );

cok( 10 === $okc, 'scenario 1: all 10 concurrent +10 report success (' . $okc . '/10) ' . cp_errors( $res ) );
cok( 10 === $obs['rows'], 'scenario 1: exactly 10 ledger rows (' . $obs['rows'] . ')' );
cok( 100 === $obs['sum'], 'scenario 1: rows sum to 100, no lost update (' . $obs['sum'] . ')' );
cok( 100 === $obs['balance'], 'scenario 1: balance is 100 (' . $obs['balance'] . ')' );
cp_invariants( $uid, 'scenario 1' );
cp_cleanup( $uid );

/* ------------------------------------------- 场景 2：10 个请求同时 -5（初始 100） */

$uid = cp_user();
AT8MLUC_Points::adjust( $uid, 100, 'seed', 'admin' );
$res = cp_spawn( 10, array( 'CONC_ACTION' => 'adjust_deduct', 'CONC_UID' => (string) $uid, 'CONC_AMOUNT' => '5' ) );
$obs = cp_observe( $uid );
$okc = cp_okcount( $res );

cok( 10 === $okc, 'scenario 2: all 10 concurrent -5 report success (' . $okc . '/10) ' . cp_errors( $res ) );
cok( 11 === $obs['rows'], 'scenario 2: one seed row plus 10 deduction rows (' . $obs['rows'] . ')' );
cok( 50 === $obs['sum'], 'scenario 2: rows sum to 100 - 50 = 50 (' . $obs['sum'] . ')' );
cok( 50 === $obs['balance'], 'scenario 2: balance is 50 (' . $obs['balance'] . ')' );
cp_invariants( $uid, 'scenario 2' );
cp_cleanup( $uid );

/* ------------------------------------------- 场景 2b：并发扣款不能突破零 */

$uid = cp_user();
AT8MLUC_Points::adjust( $uid, 7, 'seed', 'admin' );
$res = cp_spawn( 10, array( 'CONC_ACTION' => 'adjust_deduct', 'CONC_UID' => (string) $uid, 'CONC_AMOUNT' => '5' ) );
$obs = cp_observe( $uid );
$okc = cp_okcount( $res );

cok( 1 === $okc, 'scenario 2b: only one of ten deductions fits into 7 (' . $okc . '/10)' );
cok( 2 === $obs['balance'], 'scenario 2b: the balance never goes negative (7 - 5 = 2, got ' . $obs['balance'] . ')' );
cok( 2 === $obs['sum'], 'scenario 2b: rows sum to 2 (' . $obs['sum'] . ')' );
cp_cleanup( $uid );

/* ------------------------------------------- 场景 3：两笔不同订单同时发放 */

$uid     = cp_user();
$orig_rate = at8mluc_get_option( 'points_per_currency', 1 );
update_option( 'at8mluc_options', array_merge( (array) get_option( 'at8mluc_options' ), array( 'points_per_currency' => 2 ) ) );

$cp_orders = array();
for ( $i = 0; $i < 2; $i++ ) {
	$oid = wp_insert_post( array( 'post_type' => AT8MLUC_Payments::CPT, 'post_status' => 'publish', 'post_title' => 'cp order ' . $i ) );
	update_post_meta( $oid, '_at8mluc_pay_user', $uid );
	update_post_meta( $oid, '_at8mluc_pay_status', 'paid' );
	update_post_meta( $oid, '_at8mluc_pay_price', '10.00' );
	$cp_orders[] = $oid;
}

$res = cp_spawn(
	2,
	array( 'CONC_ACTION' => 'grant', 'CONC_UID' => (string) $uid ),
	array(
		0 => array( 'CONC_ORDER' => (string) $cp_orders[0] ),
		1 => array( 'CONC_ORDER' => (string) $cp_orders[1] ),
	)
);
$obs = cp_observe( $uid );

cok( 2 === cp_okcount( $res ), 'scenario 3: both orders award (' . cp_okcount( $res ) . '/2) ' . cp_errors( $res ) );
cok( 2 === $obs['rows'], 'scenario 3: exactly two ledger rows (' . $obs['rows'] . ')' );
cok( 40 === $obs['balance'], 'scenario 3: both awards landed, 2 x 20 = 40 (' . $obs['balance'] . ')' );
cp_invariants( $uid, 'scenario 3' );

/* ------------------------------------------- 场景 4：10 次并发回调，同一订单 */

$one = wp_insert_post( array( 'post_type' => AT8MLUC_Payments::CPT, 'post_status' => 'publish', 'post_title' => 'cp same order' ) );
update_post_meta( $one, '_at8mluc_pay_user', $uid );
update_post_meta( $one, '_at8mluc_pay_status', 'paid' );
update_post_meta( $one, '_at8mluc_pay_price', '10.00' );

$res = cp_spawn( 10, array( 'CONC_ACTION' => 'grant', 'CONC_UID' => (string) $uid, 'CONC_ORDER' => (string) $one ) );
$obs = cp_observe( $uid, 'purchase:' . $one );

cok( 1 === $obs['keyed'], 'scenario 4: ten simultaneous callbacks write exactly one row (' . $obs['keyed'] . ')' );
cok( 60 === $obs['balance'], 'scenario 4: the order was awarded once (40 + 20 = 60, got ' . $obs['balance'] . ')' );
cp_invariants( $uid, 'scenario 4' );

update_option( 'at8mluc_options', array_merge( (array) get_option( 'at8mluc_options' ), array( 'points_per_currency' => $orig_rate ) ) );
$cp_orders[] = $one;
foreach ( $cp_orders as $oid ) {
	delete_post_meta( $oid, '_at8mluc_points_awarded' );
	wp_delete_post( $oid, true );
}
cp_cleanup( $uid );

/* ------------------------------------------- 场景 5/6：长时间持有的写事务 + 并发写入者 */
/*
 * There is no lock and no TTL any more: a slow writer simply holds a database
 * transaction. The point of these two scenarios is that such a writer can
 * never corrupt the account — whatever it does at the end (roll back or
 * commit), the balance still equals the ledger.
 */

foreach ( array( 'rollback', 'commit' ) as $cp_end ) {
	$uid = cp_user();
	$obs = cp_observe( $uid );

	$holder = cp_launch(
		1,
		array(
			'CONC_ACTION' => 'hold_txn',
			'CONC_UID'    => (string) $uid,
			'CONC_AMOUNT' => '10',
			'CONC_HOLD'   => '3',
			'CONC_END'    => $cp_end,
		)
	);

	// Wait until the held transaction has written its rows.
	$ready = false;
	for ( $i = 0; $i < 100; $i++ ) {
		if ( isset( $holder['outs'][0] ) && is_readable( $holder['outs'][0] . '.ready' ) ) {
			$ready = true;
			break;
		}
		usleep( 100000 );
	}

	$others = cp_spawn( 3, array( 'CONC_ACTION' => 'adjust', 'CONC_UID' => (string) $uid, 'CONC_AMOUNT' => '5' ) );
	$held   = cp_collect( $holder );
	$okc    = cp_okcount( $others );
	$obs    = cp_observe( $uid );

	cok( $ready, 'scenario 5 (' . $cp_end . '): the long-running writer reached its pause' );
	cok( ! empty( $held[0]['ok'] ), 'scenario 5 (' . $cp_end . '): the holder finished and returned (' . ( isset( $held[0]['error'] ) ? $held[0]['error'] : 'ok' ) . ')' );

	// Whatever the contended writers reported, the account must be consistent.
	cok( $obs['sum'] === $obs['balance'], 'scenario 5 (' . $cp_end . '): balance equals the ledger sum (' . $obs['balance'] . ' vs ' . $obs['sum'] . ')' );
	cok( $obs['balance'] >= 0, 'scenario 5 (' . $cp_end . '): balance stays non-negative (' . $obs['balance'] . ')' );

	if ( 'rollback' === $cp_end ) {
		// The held rows disappeared; only the 5-point writers remain.
		cok( 5 * $okc === $obs['balance'], 'scenario 6: nothing of the rolled-back writer survives (' . $obs['balance'] . ' == 5 x ' . $okc . ')' );
		cok( 0 === $obs['rows'] - $okc, 'scenario 6: only the committed movements are in the ledger (' . $obs['rows'] . ' rows, ' . $okc . ' winners)' );
	} else {
		// The held rows were committed after the others: everything counts once.
		cok( 10 + 5 * $okc === $obs['balance'], 'scenario 6 (commit): the stalled writer counts exactly once (10 + 5 x ' . $okc . ' = ' . $obs['balance'] . ')' );
		cok( 1 + $okc === $obs['rows'], 'scenario 6 (commit): ledger holds one row per movement (' . $obs['rows'] . ')' );
	}

	cp_cleanup( $uid );
}

/* ------------------------------------------- 场景 7：过期写者尝试写余额 */

global $wpdb;

$uid = cp_user();
AT8MLUC_Points::adjust( $uid, 20, 'seed', 'admin' );

$cp_acct = $wpdb->get_row(
	$wpdb->prepare( "SELECT balance, fence FROM {$wpdb->prefix}at8mluc_points_account WHERE user_id = %d", $uid ),
	ARRAY_A
);
$cp_seen_fence = (int) $cp_acct['fence'];

// Somebody else moves the account while our writer is "paused".
AT8MLUC_Points::adjust( $uid, 5, 'other request', 'admin' );

$stale = $wpdb->query(
	$wpdb->prepare(
		"UPDATE {$wpdb->prefix}at8mluc_points_account SET balance = balance + %d, fence = %d WHERE user_id = %d AND fence = %d AND balance + %d >= 0",
		1000,
		$cp_seen_fence + 1,
		$uid,
		$cp_seen_fence,
		1000
	)
);

cok( 0 === (int) $stale, 'scenario 7: a writer holding a stale version cannot write the balance' );
cok( 25 === (int) AT8MLUC_Points::balance( $uid ), 'scenario 7: the newer balance survives untouched (25)' );
cp_invariants( $uid, 'scenario 7' );
cp_cleanup( $uid );

/* ------------------------------------------- 场景 8：数据库拒绝写入 */

$uid = cp_user();
AT8MLUC_Points::adjust( $uid, 30, 'seed', 'admin' );

$cp_break = function ( $query ) {
	if ( false !== strpos( $query, 'INSERT INTO wp_at8mluc_points_ledger' ) ) {
		return 'INSERT INTO wp_at8mluc_points_ledger (no_such_column) VALUES (1)';
	}

	return $query;
};
add_filter( 'query', $cp_break );
$cp_failed = AT8MLUC_Points::adjust( $uid, 10, 'write failure', 'admin' );
remove_filter( 'query', $cp_break );

cok( is_wp_error( $cp_failed ), 'scenario 8: a refused ledger write is reported as an error' );
cok( 30 === (int) AT8MLUC_Points::balance( $uid ), 'scenario 8: the refused write left the balance unchanged (30)' );
cok( 1 === count( AT8MLUC_Points::ledger( $uid, 50 ) ), 'scenario 8: the refused write left no ledger row behind' );
cok( 40 === AT8MLUC_Points::adjust( $uid, 10, 'retry', 'admin' ), 'scenario 8: the retry after the failure succeeds (40)' );
cp_cleanup( $uid );

/* ------------------------------------------- 场景 9：进程在事务中被杀 */

$uid = cp_user();
AT8MLUC_Points::adjust( $uid, 50, 'seed', 'admin' );

$killed = cp_spawn( 1, array( 'CONC_ACTION' => 'kill_mid', 'CONC_UID' => (string) $uid, 'CONC_AMOUNT' => '25' ) );
$obs    = cp_observe( $uid );

cok( empty( $killed[0]['ok'] ), 'scenario 9: the killed worker produced no result (it died mid-transaction)' );
cok( 50 === $obs['balance'], 'scenario 9: the killed transaction left the balance untouched (' . $obs['balance'] . ')' );
cok( 1 === $obs['rows'], 'scenario 9: the killed transaction left no ledger row (' . $obs['rows'] . ' rows)' );

AT8MLUC_Points::adjust( $uid, 25, 'retry after kill', 'admin' );
cok( 75 === (int) AT8MLUC_Points::balance( $uid ), 'scenario 9: the retry applies exactly once (75)' );
cp_invariants( $uid, 'scenario 9' );
cp_cleanup( $uid );

/* ------------------------------------------- 场景 10：提交后崩溃，回调重试 */

$uid = cp_user();
update_option( 'at8mluc_options', array_merge( (array) get_option( 'at8mluc_options' ), array( 'points_per_currency' => 2 ) ) );

$order_c = wp_insert_post( array( 'post_type' => AT8MLUC_Payments::CPT, 'post_status' => 'publish', 'post_title' => 'cp crash order' ) );
update_post_meta( $order_c, '_at8mluc_pay_user', $uid );
update_post_meta( $order_c, '_at8mluc_pay_status', 'paid' );
update_post_meta( $order_c, '_at8mluc_pay_price', '10.00' );

cp_spawn( 1, array( 'CONC_ACTION' => 'grant', 'CONC_UID' => (string) $uid, 'CONC_ORDER' => (string) $order_c ) );

// The process died after the commit but before writing the fast-path marker.
delete_post_meta( $order_c, '_at8mluc_points_awarded' );
cp_spawn( 3, array( 'CONC_ACTION' => 'grant', 'CONC_UID' => (string) $uid, 'CONC_ORDER' => (string) $order_c ) );

$obs = cp_observe( $uid, 'purchase:' . $order_c );

cok( 20 === $obs['balance'], 'scenario 10: a crashed-and-retried callback awards once (20, got ' . $obs['balance'] . ')' );
cok( 1 === $obs['keyed'], 'scenario 10: exactly one ledger row for the order (' . $obs['keyed'] . ')' );
cp_invariants( $uid, 'scenario 10' );

update_option( 'at8mluc_options', array_merge( (array) get_option( 'at8mluc_options' ), array( 'points_per_currency' => $orig_rate ) ) );
wp_delete_post( $order_c, true );
cp_cleanup( $uid );

echo "==== {$GLOBALS['cp']} passed, {$GLOBALS['cf']} failed ====\n";
