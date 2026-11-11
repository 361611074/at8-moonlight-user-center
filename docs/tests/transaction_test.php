<?php
/**
 * Transaction-boundary suite for the points storage (2.6.3).
 *
 * Everything here is about the edges of a transaction: who owns the commit,
 * what happens when a savepoint cannot be created, released or rewound, and
 * what the plugin is allowed to call "done" while somebody else still owns
 * the transaction.
 *
 * Run: wp eval-file transaction_test.php
 */

global $wpdb;

$GLOBALS['tp'] = 0;
$GLOBALS['tf'] = 0;

/**
 * Record one assertion.
 *
 * @param bool   $condition Assertion.
 * @param string $label     Description.
 * @return void
 */
function tok( $condition, $label ) {
	if ( $condition ) {
		$GLOBALS['tp']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['tf']++;
		echo "FAIL  $label\n";
	}
}

/**
 * Create a throwaway user.
 *
 * @return int
 */
function tu_user() {
	$name = 'tu_' . wp_generate_password( 6, false );

	return (int) wp_create_user( $name, 'x!*Tu12345', $name . '@example.test' );
}

/**
 * Remove a fixture user and its rows.
 *
 * @param int $uid User ID.
 * @return void
 */
function tu_cleanup( $uid ) {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}at8mluc_points_ledger WHERE user_id = %d", $uid ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}at8mluc_points_account WHERE user_id = %d", $uid ) );
	wp_delete_user( $uid );
}

/**
 * Count ledger rows for a user.
 *
 * @param int $uid User ID.
 * @return int
 */
function tu_rows( $uid ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}at8mluc_points_ledger WHERE user_id = %d", $uid )
	);
}

/**
 * Run a body with one statement rewritten.
 *
 * @param string   $needle  Fragment that identifies the statement.
 * @param callable $rewrite Receives the SQL, returns the replacement.
 * @param callable $body    Runs while the filter is active.
 * @return mixed
 */
function tu_with_query( $needle, $rewrite, $body ) {
	$filter = function ( $query ) use ( $needle, $rewrite ) {
		return ( false === strpos( $query, $needle ) ) ? $query : $rewrite( $query );
	};

	add_filter( 'query', $filter );
	$result = $body();
	remove_filter( 'query', $filter );

	return $result;
}

/* ==========================================================================
 * 1-2. A movement that owns its transaction
 * ========================================================================== */

$uid = tu_user();

$add = AT8MLUC_Points::adjust( $uid, 40, 'own transaction add', 'admin' );
tok( 40 === $add, 'own transaction: points are added (40)' );
tok( 1 === tu_rows( $uid ), 'own transaction: the movement is in the ledger' );
tok( 40 === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'own transaction: the mirror is written when this request owns the commit' );

$deduct = AT8MLUC_Points::adjust( $uid, -15, 'own transaction deduct', 'admin' );
tok( 25 === $deduct, 'own transaction: points are deducted (25)' );
tok( 2 === tu_rows( $uid ), 'own transaction: both movements are recorded' );
tok( AT8MLUC_Points::verify_balance( $uid ), 'own transaction: balance equals the ledger sum' );

/* ==========================================================================
 * 3-4. Inside somebody else's transaction, which then commits
 * ========================================================================== */

$events          = array();
$listen_confirmed = function () use ( &$events ) {
	$events[] = 'confirmed';
};
$listen_deferred = function () use ( &$events ) {
	$events[] = 'deferred';
};
add_action( 'at8mluc_points_adjusted', $listen_confirmed );
add_action( 'at8mluc_points_adjusted_deferred', $listen_deferred );

$mirror_before = (int) get_user_meta( $uid, 'at8mluc_points', true );

$wpdb->query( 'START TRANSACTION' );
$inside = AT8MLUC_Points::adjust( $uid, 10, 'inside outer transaction', 'admin' );
tok( 35 === $inside, 'outer transaction: the movement applies inside it (35)' );
tok( in_array( 'deferred', $events, true ) && ! in_array( 'confirmed', $events, true ), 'outer transaction: only the deferred action fires' );
tok( $mirror_before === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'outer transaction: the mirror is not written while the commit belongs to somebody else' );
$wpdb->query( 'COMMIT' );

$events = array();
tok( 35 === (int) AT8MLUC_Points::balance( $uid ), 'outer transaction commit: the movement survives the outer commit (35)' );
tok( 3 === tu_rows( $uid ), 'outer transaction commit: the ledger row is there' );
tok( AT8MLUC_Points::verify_balance( $uid ), 'outer transaction commit: balance still equals the ledger sum' );

/* ==========================================================================
 * 5. Inside somebody else's transaction, which rolls back
 * ========================================================================== */

$mirror_before = (int) get_user_meta( $uid, 'at8mluc_points', true );
$events        = array();

$wpdb->query( 'START TRANSACTION' );
$inside = AT8MLUC_Points::adjust( $uid, 20, 'outer rollback', 'admin' );
tok( 55 === $inside, 'outer rollback: the movement reports the in-transaction balance (55)' );
$wpdb->query( 'ROLLBACK' );

tok( 35 === (int) AT8MLUC_Points::balance( $uid ), 'outer rollback: the movement is gone (35)' );
tok( 3 === tu_rows( $uid ), 'outer rollback: no ledger row survived' );
tok( $mirror_before === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'outer rollback: the mirror was never poisoned' );
tok( ! in_array( 'confirmed', $events, true ), 'outer rollback: the success action never fired (only the deferred notice)' );
tok( AT8MLUC_Points::verify_balance( $uid ), 'outer rollback: the account is still consistent' );

/* ==========================================================================
 * 6. The transaction-state query fails
 * ========================================================================== */

$uid = tu_user();

// A failing @@in_transaction must not be read as "no transaction": the plugin
// falls back to the savepoint probe and still gets it right.
$blind = tu_with_query(
	'SELECT @@in_transaction',
	function () {
		return 'SELECT @@in_transaction,';
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 6, 'probe fallback', 'admin' );
	}
);

tok( 6 === $blind, 'state query failure: the movement still applies through the fallback probe (6)' );
tok( 1 === tu_rows( $uid ), 'state query failure: exactly one ledger row' );

// Both probes broken: the plugin refuses instead of guessing.
$blocked = tu_with_query(
	'SELECT @@in_transaction',
	function () {
		return 'SELECT @@in_transaction,';
	},
	function () use ( $uid ) {
		return tu_with_query(
			'SAVEPOINT at8mluc_points_probe',
			function () {
				return 'SAVEPOINT';
			},
			function () use ( $uid ) {
				return AT8MLUC_Points::adjust( $uid, 6, 'unknown state', 'admin' );
			}
		);
	}
);

tok( is_wp_error( $blocked ), 'unknown state: the movement is refused' );
tok( is_wp_error( $blocked ) && 'at8mluc_points_tx' === $blocked->get_error_code(), 'unknown state: the error is a transaction error' );
tok( 6 === (int) AT8MLUC_Points::balance( $uid ), 'unknown state: nothing was written (6)' );
tok( 1 === tu_rows( $uid ), 'unknown state: no extra ledger row' );
tu_cleanup( $uid );

/* ==========================================================================
 * 7. The savepoint cannot be created
 * ========================================================================== */

$uid = tu_user();
AT8MLUC_Points::adjust( $uid, 10, 'seed', 'admin' );

$wpdb->query( 'START TRANSACTION' );
$no_savepoint = tu_with_query(
	'SAVEPOINT at8mluc_points_tx',
	function () {
		return 'SAVEPOINT';
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 5, 'savepoint refused', 'admin' );
	}
);
$wpdb->query( 'ROLLBACK' );

tok( is_wp_error( $no_savepoint ), 'savepoint failure: the movement is refused' );
tok( is_wp_error( $no_savepoint ) && 'at8mluc_points_tx' === $no_savepoint->get_error_code(), 'savepoint failure: the error names the transaction' );
tok( 10 === (int) AT8MLUC_Points::balance( $uid ), 'savepoint failure: the balance is unchanged (10)' );

/* ==========================================================================
 * 8. RELEASE SAVEPOINT fails
 * ========================================================================== */

$errors = array();
$catch  = function ( $error ) use ( &$errors ) {
	$errors[] = $error->get_error_code();
};
add_action( 'at8mluc_points_transaction_error', $catch );

$wpdb->query( 'START TRANSACTION' );
$release_failed = tu_with_query(
	'RELEASE SAVEPOINT at8mluc_points_tx',
	function () {
		return 'RELEASE SAVEPOINT at8mluc_points_other';
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 5, 'release refused', 'admin' );
	}
);
$wpdb->query( 'ROLLBACK' );
remove_action( 'at8mluc_points_transaction_error', $catch );

tok( is_wp_error( $release_failed ), 'release failure: the movement is reported as not confirmed' );
tok( in_array( 'at8mluc_points_tx', $errors, true ), 'release failure: the transaction error was reported' );
tok( 10 === (int) AT8MLUC_Points::balance( $uid ), 'release failure: the movement was undone inside the outer transaction (10)' );
tok( 1 === tu_rows( $uid ), 'release failure: no extra ledger row' );

/* ==========================================================================
 * 9. ROLLBACK TO SAVEPOINT fails
 * ========================================================================== */

$uid    = tu_user();
AT8MLUC_Points::adjust( $uid, 10, 'seed', 'admin' );
$errors = array();
add_action( 'at8mluc_points_transaction_error', $catch );

$wpdb->query( 'START TRANSACTION' );
$rollback_failed = tu_with_query(
	'ROLLBACK TO SAVEPOINT at8mluc_points_tx',
	function () {
		return 'ROLLBACK TO SAVEPOINT at8mluc_points_other';
	},
	function () use ( $uid ) {
		return tu_with_query(
			'INSERT INTO wp_at8mluc_points_ledger',
			function () {
				return 'INSERT INTO wp_at8mluc_points_ledger (no_such_column) VALUES (1)';
			},
			function () use ( $uid ) {
				return AT8MLUC_Points::adjust( $uid, 5, 'rollback refused', 'admin' );
			}
		);
	}
);
$wpdb->query( 'ROLLBACK' );
remove_action( 'at8mluc_points_transaction_error', $catch );

tok( is_wp_error( $rollback_failed ), 'rollback failure: the movement fails loudly' );
tok( in_array( 'at8mluc_points_tx', $errors, true ), 'rollback failure: the transaction error was reported (' . implode( ',', $errors ) . ')' );
tok( 10 === (int) AT8MLUC_Points::balance( $uid ), 'rollback failure: the balance is unchanged after the outer rollback (10)' );

/* ==========================================================================
 * 10. The legacy history cannot be read
 * ========================================================================== */

$uid = tu_user();
add_user_meta( $uid, 'at8mluc_points_entry', array( 'time' => time() - 60, 'amount' => 7, 'reason' => 'legacy', 'source' => 'admin', 'by' => 0, 'key' => '', 'u' => 'legacy-1' ) );

$broken = tu_with_query(
	'FROM wp_usermeta WHERE user_id',
	function () {
		return 'FROM wp_usermeta WHERE no_such_column';
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::balance( $uid );
	}
);

tok( is_wp_error( $broken ), 'legacy read failure: balance() admits it cannot read instead of returning 0' );
tok( is_wp_error( $broken ) && 'at8mluc_points_read' === $broken->get_error_code(), 'legacy read failure: the error says the read failed' );
tok( 0 === tu_rows( $uid ), 'legacy read failure: nothing was migrated' );

$recovered = AT8MLUC_Points::balance( $uid );
tok( 7 === $recovered, 'legacy read failure: a later read migrates normally (7)' );
tok( 1 === tu_rows( $uid ), 'legacy read failure: exactly one movement imported' );
tu_cleanup( $uid );

/* ==========================================================================
 * 11. reconcile() while movements land
 * ========================================================================== */

$uid = tu_user();
AT8MLUC_Points::adjust( $uid, 100, 'seed', 'admin' );

$failures = 0;
for ( $i = 0; $i < 5; $i++ ) {
	$repaired = AT8MLUC_Points::reconcile( $uid );
	$failures += is_wp_error( $repaired ) ? 1 : 0;
	AT8MLUC_Points::adjust( $uid, 10, 'movement ' . $i, 'admin' );
}

tok( 0 === $failures, 'repair next to movements: every reconcile() succeeded' );
tok( 150 === (int) AT8MLUC_Points::balance( $uid ), 'repair next to movements: nothing was lost (150)' );
tok( AT8MLUC_Points::verify_balance( $uid ), 'repair next to movements: balance still equals the ledger' );

/* ==========================================================================
 * 12. Ten callbacks for the same order
 * ========================================================================== */

$rate = at8mluc_get_option( 'points_per_currency', 1 );
update_option( 'at8mluc_options', array_merge( (array) get_option( 'at8mluc_options' ), array( 'points_per_currency' => 2 ) ) );

$order = wp_insert_post( array( 'post_type' => AT8MLUC_Payments::CPT, 'post_status' => 'publish', 'post_title' => 'tu order' ) );
update_post_meta( $order, '_at8mluc_pay_user', $uid );
update_post_meta( $order, '_at8mluc_pay_status', 'paid' );
update_post_meta( $order, '_at8mluc_pay_price', '10.00' );

for ( $i = 0; $i < 10; $i++ ) {
	AT8MLUC_Points::get_instance()->grant_for_order( $order, $uid, 'monthly' );
}

tok( 170 === (int) AT8MLUC_Points::balance( $uid ), 'repeated callbacks: the order pays once (150 + 20 = 170, got ' . AT8MLUC_Points::balance( $uid ) . ')' );
tok( 1 === count( array_filter( AT8MLUC_Points::ledger( $uid, 50 ), function ( $e ) use ( $order ) { return 'purchase:' . $order === $e['key']; } ) ), 'repeated callbacks: exactly one ledger row carries the order key' );
tok( AT8MLUC_Points::verify_balance( $uid ), 'repeated callbacks: balance still equals the ledger sum' );

update_option( 'at8mluc_options', array_merge( (array) get_option( 'at8mluc_options' ), array( 'points_per_currency' => $rate ) ) );
wp_delete_post( $order, true );
tu_cleanup( $uid );

echo "==== {$GLOBALS['tp']} passed, {$GLOBALS['tf']} failed ====\n";
