<?php
/**
 * Real multi-process concurrency test for the points ledger.
 *
 * The parent process only schedules work: every write and every observation
 * happens in its own short-lived process with a fresh WordPress bootstrap.
 * That mirrors production (one PHP process per request) and avoids asserting
 * against this process's object cache.
 *
 * Run: wp eval-file concurrency_test.php
 */

$GLOBALS['cp'] = 0;
$GLOBALS['cf'] = 0;
function cok( $c, $l ) {
	if ( $c ) {
		$GLOBALS['cp']++;
		echo "PASS  $l\n";
	} else {
		$GLOBALS['cf']++;
		echo "FAIL  $l\n";
	}
}

const CP_PHP    = 'C:\\wptest-php\\php.exe';
const CP_CLI    = 'C:\\Users\\漫步白月光\\WorkBuddy\\2026-10-12-15-36-28\\_tools\\wp-cli.phar';
const CP_ROOT   = 'C:\\Users\\漫步白月光\\WorkBuddy\\2026-10-12-15-36-28\\wptest\\wordpress';
const CP_WORKER = 'C:\\Users\\漫步白月光\\WorkBuddy\\2026-10-12-15-36-28\\_tools\\conc_worker.php';

/**
 * Spawn N workers with the given env overrides, wait, return decoded results.
 *
 * @param int   $n         Number of processes.
 * @param array $env_extra CONC_* environment overrides.
 * @param array $per_index Per-index overrides.
 * @return array<int, array>
 */
function cp_spawn( $n, $env_extra, $per_index = array() ) {
	$tmp     = sys_get_temp_dir();
	$handles = array();
	$files   = array();

	for ( $i = 0; $i < $n; $i++ ) {
		$out = $tmp . DIRECTORY_SEPARATOR . 'cpx_' . getmypid() . '_' . wp_rand( 100000, 999999 ) . '_' . $i . '.json';
		@unlink( $out );

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

		$p = proc_open( $cmd, $desc, $pipes, null, $env );

		if ( is_resource( $p ) ) {
			$handles[] = $p;
			$files[]   = $out;
		}
	}

	foreach ( $handles as $p ) {
		proc_close( $p );
	}

	$results = array();
	foreach ( $files as $f ) {
		$raw = is_readable( $f ) ? file_get_contents( $f ) : '';
		@unlink( $f );
		$results[] = $raw ? json_decode( $raw, true ) : array( 'ok' => false, 'error' => 'no result file' );
	}

	return $results;
}

function cp_user() {
	$name = 'cp_' . wp_generate_password( 6, false );

	return (int) wp_create_user( $name, 'x!*Cp12345', $name . '@example.test' );
}

/**
 * Observe a user's ledger from a fresh process.
 *
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

function cp_okcount( $results ) {
	return count( array_filter( $results, function ( $r ) {
		return ! empty( $r['ok'] );
	} ) );
}

function cp_errors( $results ) {
	$out = array();
	foreach ( $results as $r ) {
		if ( empty( $r['ok'] ) ) {
			$out[] = isset( $r['error'] ) ? $r['error'] : 'unknown';
		}
	}

	return implode( ' | ', array_unique( $out ) );
}

/* ------------------------------------------------ 场景 1：8 进程同时 +10 */

$uid = cp_user();
$res = cp_spawn( 8, array( 'CONC_ACTION' => 'adjust', 'CONC_UID' => (string) $uid, 'CONC_AMOUNT' => '10' ) );
$obs = cp_observe( $uid );
$okc = cp_okcount( $res );

cok( 8 === $okc, 'scenario 1: all 8 concurrent +10 report success (' . $okc . '/8) ' . cp_errors( $res ) );
cok( 8 === $obs['rows'], 'scenario 1: exactly 8 ledger rows (' . $obs['rows'] . ')' );
cok( 80 === $obs['sum'], 'scenario 1: rows sum to 80, no lost update (' . $obs['sum'] . ')' );
cok( 80 === $obs['balance'], 'scenario 1: replayed balance is 80 (' . $obs['balance'] . ')' );
wp_delete_user( $uid );

/* ------------------------------------------------ 场景 2：8 进程同时 -5（初始 100） */

$uid = cp_user();
AT8_MLUC_Points::adjust( $uid, 100, 'seed', 'admin' );
$res = cp_spawn( 8, array( 'CONC_ACTION' => 'adjust_deduct', 'CONC_UID' => (string) $uid, 'CONC_AMOUNT' => '5' ) );
$obs = cp_observe( $uid );
$okc = cp_okcount( $res );

cok( 8 === $okc, 'scenario 2: all 8 concurrent -5 report success (' . $okc . '/8) ' . cp_errors( $res ) );
cok( 9 === $obs['rows'], 'scenario 2: one seed row plus 8 deduction rows (' . $obs['rows'] . ')' );
cok( 60 === $obs['sum'], 'scenario 2: rows sum to 100 - 40 = 60 (' . $obs['sum'] . ')' );
cok( 60 === $obs['balance'], 'scenario 2: balance is 60 (' . $obs['balance'] . ')' );
wp_delete_user( $uid );

/* ------------------------------------------------ 场景 2b：并发扣款不能突破零 */

$uid = cp_user();
AT8_MLUC_Points::adjust( $uid, 7, 'seed', 'admin' );
$res = cp_spawn( 8, array( 'CONC_ACTION' => 'adjust_deduct', 'CONC_UID' => (string) $uid, 'CONC_AMOUNT' => '5' ) );
$obs = cp_observe( $uid );
$okc = cp_okcount( $res );

cok( 1 === $okc, 'scenario 2b: only one of eight deductions fits into 7 (' . $okc . '/8)' );
cok( 2 === $obs['balance'], 'scenario 2b: balance never goes negative (7 - 5 = 2, got ' . $obs['balance'] . ')' );
cok( 2 === $obs['sum'], 'scenario 2b: rows sum to 2 (' . $obs['sum'] . ')' );
wp_delete_user( $uid );

/* ------------------------------------------------ 场景 3：8 个不同订单并发发放 */

$uid    = cp_user();
$orders = array();
for ( $i = 0; $i < 8; $i++ ) {
	$oid = wp_insert_post( array( 'post_type' => AT8_MLUC_Payments::CPT, 'post_status' => 'publish', 'post_title' => 'cp order ' . $i ) );
	update_post_meta( $oid, '_at8_mluc_pay_user', $uid );
	update_post_meta( $oid, '_at8_mluc_pay_status', 'paid' );
	update_post_meta( $oid, '_at8_mluc_pay_price', '10.00' );
	$orders[] = $oid;
}

$orig_rate = at8_mluc_get_option( 'points_per_currency', 1 );
update_option( 'at8_mluc_options', array_merge( (array) get_option( 'at8_mluc_options' ), array( 'points_per_currency' => 2 ) ) );

$per = array();
foreach ( $orders as $i => $oid ) {
	$per[ $i ] = array( 'CONC_ORDER' => (string) $oid );
}
$res = cp_spawn( 8, array( 'CONC_ACTION' => 'grant', 'CONC_UID' => (string) $uid ), $per );
$obs = cp_observe( $uid );
$okc = cp_okcount( $res );

cok( 8 === $okc, 'scenario 3: all 8 different orders award successfully (' . $okc . '/8) ' . cp_errors( $res ) );
cok( 8 === $obs['rows'], 'scenario 3: exactly 8 ledger rows (' . $obs['rows'] . ')' );
cok( 160 === $obs['sum'], 'scenario 3: rows sum to 8 x 20 = 160 (' . $obs['sum'] . ')' );
cok( 160 === $obs['balance'], 'scenario 3: balance is 160 (' . $obs['balance'] . ')' );

/* ------------------------------------------------ 场景 4：8 进程同时触发同一订单 */

// A brand-new order: the claim row of the scenario-3 orders is still live
// (60 s TTL), and reusing one of them would measure the claim lock, not the
// award path.
$one = wp_insert_post( array( 'post_type' => AT8_MLUC_Payments::CPT, 'post_status' => 'publish', 'post_title' => 'cp same order' ) );
update_post_meta( $one, '_at8_mluc_pay_user', $uid );
update_post_meta( $one, '_at8_mluc_pay_status', 'paid' );
update_post_meta( $one, '_at8_mluc_pay_price', '10.00' );

delete_user_meta( $uid, 'at8_mluc_points_entry' );
update_user_meta( $uid, 'at8_mluc_points', 0 );
delete_post_meta( $one, '_at8_mluc_points_awarded' );

$res = cp_spawn( 8, array( 'CONC_ACTION' => 'grant', 'CONC_UID' => (string) $uid, 'CONC_ORDER' => (string) $one ) );
$obs = cp_observe( $uid, 'purchase:' . $one );

cok( 1 === $obs['keyed'], 'scenario 4: eight simultaneous callbacks write exactly one row (' . $obs['keyed'] . ')' );
cok( 20 === $obs['sum'], 'scenario 4: the award happened once, 20 points (' . $obs['sum'] . ')' );

update_option( 'at8_mluc_options', array_merge( (array) get_option( 'at8_mluc_options' ), array( 'points_per_currency' => $orig_rate ) ) );
$orders[] = $one;
foreach ( $orders as $oid ) {
	wp_delete_post( $oid, true );
}
wp_delete_user( $uid );

echo "==== {$GLOBALS['cp']} passed, {$GLOBALS['cf']} failed ====\n";
