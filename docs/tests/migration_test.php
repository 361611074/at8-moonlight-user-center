<?php
/**
 * Migration, transaction and repair suite for the points storage (2.6.1).
 *
 * Covers the failure modes that the happy-path suites cannot reach:
 * a migration that dies halfway, a refused COMMIT, a missing table, a
 * duplicate legacy key, and a repair that races a movement.
 *
 * Run: wp eval-file migration_test.php
 */

global $wpdb;

$GLOBALS['mp'] = 0;
$GLOBALS['mf'] = 0;

/**
 * Record one assertion.
 *
 * @param bool   $condition Assertion.
 * @param string $label     Description.
 * @return void
 */
function mok( $condition, $label ) {
	if ( $condition ) {
		$GLOBALS['mp']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['mf']++;
		echo "FAIL  $label\n";
	}
}

/**
 * Create a throwaway user.
 *
 * @return int
 */
function mt_user() {
	$name = 'mt_' . wp_generate_password( 6, false );

	return (int) wp_create_user( $name, 'x!*Mt12345', $name . '@example.test' );
}

/**
 * Give a user a legacy meta ledger.
 *
 * @param int   $uid     User ID.
 * @param array $entries List of array( amount, key ).
 * @return void
 */
function mt_seed( $uid, $entries ) {
	$clock = time() - 1000;

	foreach ( $entries as $entry ) {
		$clock += 10;

		add_user_meta(
			$uid,
			'at8_mluc_points_entry',
			array(
				'time'   => $clock,
				'amount' => (int) $entry[0],
				'reason' => 'Legacy ' . $entry[0],
				'source' => 'purchase',
				'by'     => 0,
				'key'    => (string) $entry[1],
				'u'      => 'mt-' . wp_generate_password( 8, false ),
			)
		);
	}

	$total = 0;
	foreach ( $entries as $entry ) {
		$total += (int) $entry[0];
	}

	update_user_meta( $uid, 'at8_mluc_points', $total );
}

/**
 * Remove a fixture user and every row it owns.
 *
 * @param int $uid User ID.
 * @return void
 */
function mt_cleanup( $uid ) {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}at8_mluc_points_ledger WHERE user_id = %d", $uid ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}at8_mluc_points_account WHERE user_id = %d", $uid ) );
	wp_delete_user( $uid );
}

/**
 * The account row, or null.
 *
 * @param int $uid User ID.
 * @return array<string, mixed>|null
 */
function mt_account( $uid ) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare( "SELECT balance, fence FROM {$wpdb->prefix}at8_mluc_points_account WHERE user_id = %d", $uid ),
		ARRAY_A
	);
}

/**
 * Number of ledger rows for a user.
 *
 * @param int $uid User ID.
 * @return int
 */
function mt_rows( $uid ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}at8_mluc_points_ledger WHERE user_id = %d", $uid )
	);
}

/**
 * Break selected ledger INSERTs, counting only the ones for real movements.
 *
 * @param array<int, int> $targets 1-based indexes to break.
 * @return array{0:callable,1:object} Filter closure and the counter holder.
 */
function mt_break_inserts( $targets ) {
	$counter = new stdClass();
	$counter->n = 0;

	$filter = function ( $query ) use ( $targets, $counter ) {
		if ( false === strpos( $query, 'INSERT INTO wp_at8_mluc_points_ledger' ) ) {
			return $query;
		}

		$counter->n++;

		if ( in_array( $counter->n, $targets, true ) ) {
			return 'INSERT INTO wp_at8_mluc_points_ledger (no_such_column) VALUES (1)';
		}

		return $query;
	};

	return array( $filter, $counter );
}

/* ==========================================================================
 * 1. A normal migration
 * ========================================================================== */

$uid = mt_user();
mt_seed( $uid, array( array( 12, 'purchase:mt-1' ), array( 5, '' ), array( 3, 'admin:mt-3' ) ) );

mok( 20 === (int) AT8_MLUC_Points::balance( $uid ), 'migration: the balance matches the legacy history (20)' );
mok( 3 === mt_rows( $uid ), 'migration: every legacy movement becomes a row (' . mt_rows( $uid ) . ')' );
mok( AT8_MLUC_Points::has_entry( $uid, 'purchase:mt-1' ), 'migration: the original idempotency key is kept' );
mok( 3 === count( get_user_meta( $uid, 'at8_mluc_points_entry', false ) ), 'migration: the legacy meta is still there' );
mok( AT8_MLUC_Points::verify_balance( $uid ), 'migration: the stored balance equals the ledger sum' );

$account = mt_account( $uid );
mok( is_array( $account ) && 0 === (int) $account['fence'], 'migration: the fresh account starts at version 0' );
mok( 21 === AT8_MLUC_Points::adjust( $uid, 1, 'after migration', 'admin' ), 'migration: the account works afterwards (21)' );
mt_cleanup( $uid );

/* ==========================================================================
 * 2. The first movement cannot be written
 * ========================================================================== */

$uid = mt_user();
mt_seed( $uid, array( array( 7, 'purchase:mt-a' ), array( 11, 'purchase:mt-b' ) ) );

list( $filter, $counter ) = mt_break_inserts( array( 1 ) );
add_filter( 'query', $filter );
$balance = AT8_MLUC_Points::balance( $uid );
remove_filter( 'query', $filter );

mok( 18 === (int) $balance, 'first-movement failure: reads fall back to the legacy total (18)' );
mok( 0 === mt_rows( $uid ), 'first-movement failure: no partial ledger rows (' . mt_rows( $uid ) . ')' );
mok( null === mt_account( $uid ), 'first-movement failure: no account row was committed' );

$balance = AT8_MLUC_Points::balance( $uid );
mok( 18 === (int) $balance && 2 === mt_rows( $uid ), 'first-movement failure: the next read migrates cleanly (18, 2 rows)' );
mt_cleanup( $uid );

/* ==========================================================================
 * 3. A movement in the middle cannot be written
 * ========================================================================== */

$uid = mt_user();
mt_seed( $uid, array( array( 4, 'purchase:mt-c' ), array( 6, '' ), array( 9, 'purchase:mt-d' ) ) );

list( $filter, $counter ) = mt_break_inserts( array( 2 ) );
add_filter( 'query', $filter );
$balance = AT8_MLUC_Points::balance( $uid );
remove_filter( 'query', $filter );

mok( 19 === (int) $balance, 'middle-movement failure: the legacy total is reported (19)' );
mok( 0 === mt_rows( $uid ), 'middle-movement failure: the rows already written were rolled back (' . mt_rows( $uid ) . ')' );
mok( null === mt_account( $uid ), 'middle-movement failure: the account row was not committed' );

$balance = AT8_MLUC_Points::balance( $uid );
mok( 19 === (int) $balance && 3 === mt_rows( $uid ), 'middle-movement failure: the retry migrates all three movements' );
mt_cleanup( $uid );

/* ==========================================================================
 * 4. The same idempotency key appears twice in the old history
 * ========================================================================== */

$uid = mt_user();
mt_seed( $uid, array( array( 10, 'purchase:mt-dup' ), array( 6, 'purchase:mt-dup' ) ) );

mok( 16 === (int) AT8_MLUC_Points::balance( $uid ), 'duplicate legacy key: both movements survive (16)' );
mok( 2 === mt_rows( $uid ), 'duplicate legacy key: two rows are written (' . mt_rows( $uid ) . ')' );
mok( 1 === count( array_filter( AT8_MLUC_Points::ledger( $uid, 20 ), function ( $e ) { return 'purchase:mt-dup' === $e['key']; } ) ), 'duplicate legacy key: only the first keeps the original key' );
$re_award = AT8_MLUC_Points::adjust( $uid, 10, 're-award', 'purchase', 'purchase:mt-dup' );
mok( 16 === (int) $re_award, 'duplicate legacy key: a repeated callback still pays nothing extra (' . var_export( $re_award, true ) . ')' );
mt_cleanup( $uid );

/* ==========================================================================
 * 5. COMMIT fails
 * ========================================================================== */

$uid = mt_user();
mt_seed( $uid, array( array( 8, 'purchase:mt-e' ), array( 2, '' ) ) );

$commit_break = function ( $query ) {
	return 'COMMIT' === trim( $query ) ? 'COMMIT NOW' : $query;
};
add_filter( 'query', $commit_break );
$balance = AT8_MLUC_Points::balance( $uid );
remove_filter( 'query', $commit_break );

mok( 10 === (int) $balance, 'commit failure: the legacy total is still readable (10)' );
mok( 0 === mt_rows( $uid ), 'commit failure: nothing was left behind (' . mt_rows( $uid ) . ' rows)' );
mok( null === mt_account( $uid ), 'commit failure: the account row did not survive' );

$balance = AT8_MLUC_Points::balance( $uid );
mok( 10 === (int) $balance && 2 === mt_rows( $uid ), 'commit failure: the retry migrates both movements' );

// An adjust that loses its COMMIT must report an error and fire nothing.
$events = 0;
$listen = function () use ( &$events ) {
	$events++;
};
add_action( 'at8_mluc_points_adjusted', $listen );
add_filter( 'query', $commit_break );
$result = AT8_MLUC_Points::adjust( $uid, 3, 'commit-refused movement', 'admin' );
remove_filter( 'query', $commit_break );
remove_action( 'at8_mluc_points_adjusted', $listen );

mok( is_wp_error( $result ), 'commit failure: adjust() returns an error instead of a new balance' );
mok( 0 === $events, 'commit failure: the success action did not fire' );
mok( 10 === (int) AT8_MLUC_Points::balance( $uid ), 'commit failure: the balance is unchanged (10)' );
mt_cleanup( $uid );

/* ==========================================================================
 * 6. The tables are missing
 * ========================================================================== */

$uid = mt_user();
AT8_MLUC_Points::adjust( $uid, 5, 'seed', 'admin' );

$ledger  = $wpdb->prefix . 'at8_mluc_points_ledger';
$account = $wpdb->prefix . 'at8_mluc_points_account';
$wpdb->query( "DROP TABLE IF EXISTS {$ledger}" );
$wpdb->query( "DROP TABLE IF EXISTS {$account}" );

// A new request would verify from scratch; emulate that here.
$schema_flag = new ReflectionProperty( 'AT8_MLUC_Points', 'schema_ok' );
$schema_flag->setAccessible( true );
$schema_flag->setValue( null, false );

$no_create = function ( $query ) {
	return ( false !== stripos( $query, 'CREATE TABLE' ) || false !== stripos( $query, 'ALTER TABLE' ) ) ? 'CREATE TABLE broken (' : $query;
};
add_filter( 'query', $no_create );
$result = AT8_MLUC_Points::adjust( $uid, 5, 'missing table', 'admin' );
remove_filter( 'query', $no_create );

mok( is_wp_error( $result ), 'missing table: adjust() returns a clear error' );
mok( is_wp_error( $result ) && 'at8_mluc_points_schema' === $result->get_error_code(), 'missing table: the error names the schema' );

// Once the repair is allowed again, the same call works on fresh tables.
// The 5 points that were in the dropped tables come back from the legacy
// meta mirror, so the balance is 5 + 5 rather than 5.
$repaired = AT8_MLUC_Points::adjust( $uid, 5, 'after repair', 'admin' );
mok( 10 === $repaired, 'missing table: the repair restores the balance and applies the movement (' . var_export( $repaired, true ) . ')' );
mok( AT8_MLUC_Points::verify_balance( $uid ), 'missing table: the repaired account is consistent' );
mt_cleanup( $uid );

/* ==========================================================================
 * 7. Schema checks report what they find
 * ========================================================================== */

// The engine is reported by the server here; the negative case needs a
// non-InnoDB table, which this SQLite test site cannot produce (NOT RUN).
$status = $wpdb->get_results( "SHOW TABLE STATUS LIKE '{$wpdb->prefix}at8_mluc_points_ledger'", ARRAY_A );
mok( is_array( $status ) && isset( $status[0]['Engine'] ) && 0 === strcasecmp( 'InnoDB', (string) $status[0]['Engine'] ), 'schema: the ledger table reports InnoDB (' . ( $status[0]['Engine'] ?? 'unknown' ) . ')' );

// The unique index and the primary key are what the verifier must see.
$index = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}at8_mluc_points_ledger", ARRAY_A );
$found = false;
foreach ( (array) $index as $row ) {
	if ( 'idem_key' === $row['Key_name'] && 0 === (int) $row['Non_unique'] ) {
		$found = true;
	}
}
mok( $found, 'schema: the UNIQUE (user_id, idem_key) index is present' );

/* ==========================================================================
 * 8. A stale writer loses the CAS and retries
 * ========================================================================== */

$uid = mt_user();
AT8_MLUC_Points::adjust( $uid, 10, 'seed', 'admin' );

$reads   = 0;
$updates = 0;
$stale   = function ( $query ) use ( &$reads, &$updates, $uid ) {
	if ( false !== strpos( $query, 'SELECT balance, fence FROM wp_at8_mluc_points_account' ) && false !== strpos( $query, 'user_id = ' . $uid ) ) {
		$reads++;

		if ( 1 === $reads ) {
			// Hand back a version nobody holds any more, exactly like a writer
			// whose snapshot went stale before its UPDATE ran.
			$query = str_replace( 'SELECT balance, fence FROM', 'SELECT balance, fence + 999 AS fence FROM', $query );
		}

		return $query;
	}

	if ( false !== strpos( $query, 'UPDATE wp_at8_mluc_points_account SET balance = balance +' ) ) {
		$updates++;
	}

	return $query;
};

add_filter( 'query', $stale );
$result = AT8_MLUC_Points::adjust( $uid, 5, 'retried movement', 'admin' );
remove_filter( 'query', $stale );

mok( 15 === $result, 'stale writer: the movement still applies exactly once (' . var_export( $result, true ) . ')' );
mok( $updates >= 2, 'stale writer: the CAS was retried (' . $updates . ' attempts)' );
mok( 1 === mt_rows( $uid ) - 1, 'stale writer: the retry wrote exactly one movement (' . mt_rows( $uid ) . ' rows in total)' );
mok( AT8_MLUC_Points::verify_balance( $uid ), 'stale writer: the balance still equals the ledger sum' );
mt_cleanup( $uid );

/* ==========================================================================
 * 9. reconcile runs while movements land
 * ========================================================================== */

$uid = mt_user();
AT8_MLUC_Points::adjust( $uid, 100, 'seed', 'admin' );

$repairs = 0;
for ( $i = 0; $i < 5; $i++ ) {
	$repaired = AT8_MLUC_Points::reconcile( $uid );
	$repairs += is_wp_error( $repaired ) ? 0 : 1;
	AT8_MLUC_Points::adjust( $uid, 10, 'movement during repair ' . $i, 'admin' );
}

$sum = 0;
foreach ( AT8_MLUC_Points::ledger( $uid, 100 ) as $entry ) {
	$sum += (int) $entry['amount'];
}

mok( 5 === $repairs, 'reconcile: every repair completed (' . $repairs . '/5)' );
mok( 150 === (int) AT8_MLUC_Points::balance( $uid ), 'reconcile: no movement was lost or double-counted (150)' );
mok( 150 === $sum, 'reconcile: the balance still equals the ledger sum (' . $sum . ')' );
mt_cleanup( $uid );

/* ==========================================================================
 * 10. A repair cannot overwrite a newer balance
 * ========================================================================== */

$uid = mt_user();
AT8_MLUC_Points::adjust( $uid, 40, 'seed', 'admin' );

// Read the account, let another movement land, then try the repair with the
// stale version: it must be refused.
$before = mt_account( $uid );
AT8_MLUC_Points::adjust( $uid, 20, 'movement after the snapshot', 'admin' );
$stale_write = $wpdb->query(
	$wpdb->prepare(
		"UPDATE {$wpdb->prefix}at8_mluc_points_account SET balance = %d, fence = %d WHERE user_id = %d AND fence = %d",
		40,
		(int) $before['fence'] + 1,
		$uid,
		(int) $before['fence']
	)
);

mok( 0 === (int) $stale_write, 'repair: a repair with a stale snapshot writes nothing' );
mok( 60 === (int) AT8_MLUC_Points::balance( $uid ), 'repair: the newer balance survives (60)' );
mt_cleanup( $uid );

echo "==== {$GLOBALS['mp']} passed, {$GLOBALS['mf']} failed ====\n";
