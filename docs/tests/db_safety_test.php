<?php
/**
 * Database-safety suite for the points storage (2.6.2).
 *
 * The point of this file is the failure half of the contract: a database
 * error must never be read as "zero", "missing" or "nothing to do", and a
 * schema that cannot be verified must never be treated as healthy.
 *
 * Run: wp eval-file db_safety_test.php
 */

global $wpdb;

$GLOBALS['sp'] = 0;
$GLOBALS['sf'] = 0;

/**
 * Record one assertion.
 *
 * @param bool   $condition Assertion.
 * @param string $label     Description.
 * @return void
 */
function sok( $condition, $label ) {
	if ( $condition ) {
		$GLOBALS['sp']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['sf']++;
		echo "FAIL  $label\n";
	}
}

/**
 * Create a throwaway user.
 *
 * @return int
 */
function st_user() {
	$name = 'st_' . wp_generate_password( 6, false );

	return (int) wp_create_user( $name, 'x!*St12345', $name . '@example.test' );
}

/**
 * Remove a fixture user and its rows.
 *
 * @param int $uid User ID.
 * @return void
 */
function st_cleanup( $uid ) {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}at8mluc_points_ledger WHERE user_id = %d", $uid ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}at8mluc_points_account WHERE user_id = %d", $uid ) );
	wp_delete_user( $uid );
}

/**
 * Force the schema check to run again inside this process.
 *
 * @return void
 */
function st_reset_schema_flag() {
	$flag = new ReflectionProperty( 'AT8MLUC_Points', 'schema_ok' );
	$flag->setAccessible( true );
	$flag->setValue( null, false );
}

/**
 * Count the ledger rows of a user.
 *
 * @param int $uid User ID.
 * @return int
 */
function st_rows( $uid ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}at8mluc_points_ledger WHERE user_id = %d", $uid )
	);
}

/**
 * Rewrite one statement while a filter is installed.
 *
 * @param string   $needle   Fragment that identifies the statement.
 * @param callable $rewrite  Receives the SQL, returns the replacement.
 * @param callable $body     Runs while the filter is active.
 * @return mixed Whatever $body returns.
 */
function st_with_query( $needle, $rewrite, $body ) {
	$filter = function ( $query ) use ( $needle, $rewrite ) {
		return ( false === strpos( $query, $needle ) ) ? $query : $rewrite( $query );
	};

	add_filter( 'query', $filter );
	$result = $body();
	remove_filter( 'query', $filter );

	return $result;
}

/* ==========================================================================
 * 1. reconcile() repairs a damaged balance
 * ========================================================================== */

$uid = st_user();
AT8MLUC_Points::adjust( $uid, 30, 'seed', 'admin' );
AT8MLUC_Points::adjust( $uid, 12, 'seed 2', 'admin' );

$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}at8mluc_points_account SET balance = 4 WHERE user_id = %d", $uid ) );

$repaired = AT8MLUC_Points::reconcile( $uid );

sok( 42 === $repaired, 'reconcile: the balance is rebuilt from the movements (42)' );
sok( 42 === (int) AT8MLUC_Points::balance( $uid ), 'reconcile: reads return the repaired value' );
sok( AT8MLUC_Points::verify_balance( $uid ), 'reconcile: balance and ledger agree afterwards' );
st_cleanup( $uid );

/* ==========================================================================
 * 2. The ledger sum cannot be read
 * ========================================================================== */

$uid = st_user();
AT8MLUC_Points::adjust( $uid, 25, 'seed', 'admin' );
$mirror_before = (int) get_user_meta( $uid, 'at8mluc_points', true );

$failed = st_with_query(
	'SELECT COALESCE( SUM( amount ), 0 ) FROM wp_at8mluc_points_ledger',
	function () {
		return 'SELECT COALESCE( SUM( amount ), 0 ) FROM wp_at8mluc_points_missing';
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::reconcile( $uid );
	}
);

sok( is_wp_error( $failed ), 'unreadable sum: reconcile() returns an error' );
sok( is_wp_error( $failed ) && 'at8mluc_points_read' === $failed->get_error_code(), 'unreadable sum: the error says the read failed' );
sok( 25 === (int) AT8MLUC_Points::balance( $uid ), 'unreadable sum: the balance was not zeroed (25)' );
sok( $mirror_before === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'unreadable sum: the meta mirror was not touched' );
sok( 1 === st_rows( $uid ), 'unreadable sum: no ledger row was added or removed' );

$unverified = st_with_query(
	'SELECT COALESCE( SUM( amount ), 0 ) FROM wp_at8mluc_points_ledger',
	function () {
		return 'SELECT COALESCE( SUM( amount ), 0 ) FROM wp_at8mluc_points_missing';
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::verify_balance( $uid );
	}
);
sok( false === $unverified, 'unreadable sum: verify_balance() reports "cannot verify" instead of "consistent"' );
st_cleanup( $uid );

/* ==========================================================================
 * 3. reconcile() runs next to movements
 * ========================================================================== */

$uid = st_user();
AT8MLUC_Points::adjust( $uid, 100, 'seed', 'admin' );

$errors = 0;
for ( $i = 0; $i < 5; $i++ ) {
	$result = AT8MLUC_Points::reconcile( $uid );
	$errors += is_wp_error( $result ) ? 1 : 0;
	AT8MLUC_Points::adjust( $uid, 10, 'movement ' . $i, 'admin' );
}

sok( 0 === $errors, 'repair next to movements: every reconcile() succeeded (' . $errors . ' errors)' );
sok( 150 === (int) AT8MLUC_Points::balance( $uid ), 'repair next to movements: nothing was lost (150)' );
sok( AT8MLUC_Points::verify_balance( $uid ), 'repair next to movements: balance still equals the ledger sum' );
st_cleanup( $uid );

/* ==========================================================================
 * 4-7. The schema cannot be trusted
 * ========================================================================== */

$ledger_table = $wpdb->prefix . 'at8mluc_points_ledger';

$cases = array(
	'SHOW TABLE STATUS cannot be read' => array(
		'needle'  => 'SHOW TABLE STATUS LIKE',
		'rewrite' => function () {
			return 'SHOW TABLE STATUS LIKE CONCAT(1';
		},
	),
	'SHOW TABLE STATUS returns nothing' => array(
		'needle'  => 'SHOW TABLE STATUS LIKE',
		'rewrite' => function () {
			return 'SELECT 1 AS Name WHERE 1 = 0';
		},
	),
	'the Engine field is missing'       => array(
		'needle'  => 'SHOW TABLE STATUS LIKE',
		'rewrite' => function () {
			return 'SELECT 1 AS Name';
		},
	),
	'the table is not InnoDB'           => array(
		'needle'  => 'SHOW TABLE STATUS LIKE',
		'rewrite' => function () {
			return "SELECT 1 AS Name, 'MyISAM' AS Engine";
		},
	),
	'the UNIQUE index is gone'          => array(
		'needle'  => "SHOW INDEX FROM {$ledger_table}",
		'rewrite' => function () use ( $wpdb ) {
			return "SHOW INDEX FROM {$wpdb->prefix}at8mluc_points_account";
		},
	),
);

foreach ( $cases as $label => $case ) {
	$uid = st_user();
	AT8MLUC_Points::adjust( $uid, 10, 'seed', 'admin' );
	$before = (int) AT8MLUC_Points::balance( $uid );

	st_reset_schema_flag();

	$result = st_with_query(
		$case['needle'],
		$case['rewrite'],
		function () use ( $uid ) {
			return AT8MLUC_Points::adjust( $uid, 5, 'blocked write', 'admin' );
		}
	);

	st_reset_schema_flag();

	sok( is_wp_error( $result ), $label . ': the write is refused' );
	sok( is_wp_error( $result ) && 'at8mluc_points_schema' === $result->get_error_code(), $label . ': the error names the schema' );
	sok( $before === (int) AT8MLUC_Points::balance( $uid ), $label . ': the balance is untouched (' . $before . ')' );
	sok( 1 === st_rows( $uid ), $label . ': no ledger row was written' );

	st_cleanup( $uid );
}

// With the filter gone the same write goes through, so the checks above are
// not simply refusing everything.
$uid = st_user();
sok( 5 === AT8MLUC_Points::adjust( $uid, 5, 'healthy write', 'admin' ), 'schema: a healthy install still writes (5)' );
st_cleanup( $uid );

/* ==========================================================================
 * 8. A movement in the migration cannot be written
 * ========================================================================== */

$uid = st_user();
add_user_meta( $uid, 'at8mluc_points_entry', array( 'time' => time() - 30, 'amount' => 9, 'reason' => 'old', 'source' => 'admin', 'by' => 0, 'key' => 'st:a', 'u' => 'a' ) );
add_user_meta( $uid, 'at8mluc_points_entry', array( 'time' => time() - 20, 'amount' => 4, 'reason' => 'old 2', 'source' => 'admin', 'by' => 0, 'key' => 'st:b', 'u' => 'b' ) );

$inserts = 0;
$broken  = st_with_query(
	'INSERT INTO wp_at8mluc_points_ledger',
	function ( $query ) use ( &$inserts ) {
		$inserts++;

		return 2 === $inserts ? 'INSERT INTO wp_at8mluc_points_ledger (no_such_column) VALUES (1)' : $query;
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::balance( $uid );
	}
);

sok( 13 === (int) $broken, 'migration failure: the legacy total is still reported (13)' );
sok( 0 === st_rows( $uid ), 'migration failure: the first movement was rolled back too (' . st_rows( $uid ) . ' rows)' );
sok( null === $wpdb->get_row( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}at8mluc_points_account WHERE user_id = %d", $uid ) ), 'migration failure: no account row was committed' );
sok( 13 === (int) AT8MLUC_Points::balance( $uid ) && 2 === st_rows( $uid ), 'migration failure: the retry migrates both movements' );
st_cleanup( $uid );

/* ==========================================================================
 * 9. COMMIT fails
 * ========================================================================== */

$uid = st_user();
AT8MLUC_Points::adjust( $uid, 20, 'seed', 'admin' );

$events = 0;
$listen = function () use ( &$events ) {
	$events++;
};
add_action( 'at8mluc_points_adjusted', $listen );

$commit_broken = st_with_query(
	'COMMIT',
	function () {
		return 'COMMIT NOW';
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 5, 'commit refused', 'admin' );
	}
);

remove_action( 'at8mluc_points_adjusted', $listen );

sok( is_wp_error( $commit_broken ), 'commit failure: adjust() reports an error' );
sok( 0 === $events, 'commit failure: no success action fired' );
sok( 20 === (int) AT8MLUC_Points::balance( $uid ), 'commit failure: the balance is unchanged (20)' );
st_cleanup( $uid );

/* ==========================================================================
 * 10. The same order is triggered repeatedly
 * ========================================================================== */

$uid = st_user();
$rate = at8mluc_get_option( 'points_per_currency', 1 );
update_option( 'at8mluc_options', array_merge( (array) get_option( 'at8mluc_options' ), array( 'points_per_currency' => 2 ) ) );

$order = wp_insert_post( array( 'post_type' => AT8MLUC_Payments::CPT, 'post_status' => 'publish', 'post_title' => 'st order' ) );
update_post_meta( $order, '_at8mluc_pay_user', $uid );
update_post_meta( $order, '_at8mluc_pay_status', 'paid' );
update_post_meta( $order, '_at8mluc_pay_price', '10.00' );

for ( $i = 0; $i < 3; $i++ ) {
	AT8MLUC_Points::get_instance()->grant_for_order( $order, $uid, 'monthly' );
	delete_post_meta( $order, '_at8mluc_points_awarded' );
	AT8MLUC_Points::get_instance()->grant_for_order( $order, $uid, 'monthly' );
}

sok( 20 === (int) AT8MLUC_Points::balance( $uid ), 'repeated callback: the order pays once (20, got ' . AT8MLUC_Points::balance( $uid ) . ')' );
sok( 1 === st_rows( $uid ), 'repeated callback: exactly one ledger row (' . st_rows( $uid ) . ')' );
sok( AT8MLUC_Points::verify_balance( $uid ), 'repeated callback: balance still equals the ledger sum' );

update_option( 'at8mluc_options', array_merge( (array) get_option( 'at8mluc_options' ), array( 'points_per_currency' => $rate ) ) );
wp_delete_post( $order, true );
st_cleanup( $uid );

/* ==========================================================================
 * 11. A failed lookup is not "no such movement"
 * ========================================================================== */

$uid = st_user();
AT8MLUC_Points::adjust( $uid, 8, 'seed', 'admin' );

$lookup = st_with_query(
	'SELECT id FROM wp_at8mluc_points_ledger WHERE user_id',
	function () {
		return 'SELECT id FROM wp_at8mluc_points_ledger WHERE no_such_column = 1';
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::has_entry( $uid, 'purchase:st-x' );
	}
);

sok( is_wp_error( $lookup ), 'failed lookup: has_entry() reports the error instead of "absent"' );

$adjust = st_with_query(
	'SELECT id FROM wp_at8mluc_points_ledger WHERE user_id',
	function () {
		return 'SELECT id FROM wp_at8mluc_points_ledger WHERE no_such_column = 1';
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 5, 'blocked by lookup', 'purchase', 'purchase:st-x' );
	}
);

sok( is_wp_error( $adjust ), 'failed lookup: the keyed movement is refused rather than risked' );
sok( 8 === (int) AT8MLUC_Points::balance( $uid ), 'failed lookup: the balance is unchanged (8)' );
sok( 1 === st_rows( $uid ), 'failed lookup: no row was written' );
st_cleanup( $uid );

echo "==== {$GLOBALS['sp']} passed, {$GLOBALS['sf']} failed ====\n";
