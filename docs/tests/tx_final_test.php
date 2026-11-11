<?php
/**
 * 2.6.5 transaction-failure suite.
 *
 * Covers the A-E checklists of the review brief: COMMIT failures, rollback
 * failures, migration failure paths, savepoint lifecycle, and the business
 * regressions that must not change.
 *
 * Migration is triggered through adjust() on purpose: balance() keeps a
 * documented display fallback to the legacy meta total, so it would hide the
 * failure this suite is about.
 *
 * Run: wp eval-file tx_final_test.php
 */

global $wpdb;

$GLOBALS['xp'] = 0;
$GLOBALS['xf'] = 0;

/**
 * Record one assertion.
 *
 * @param bool   $condition Assertion.
 * @param string $label     Description.
 * @return void
 */
function fok( $condition, $label ) {
	if ( $condition ) {
		$GLOBALS['xp']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['xf']++;
		echo "FAIL  $label\n";
	}
}

/**
 * Create a throwaway user.
 *
 * @return int
 */
function fx_user() {
	$name = 'fx_' . wp_generate_password( 6, false );

	return (int) wp_create_user( $name, 'x!*Fx12345', $name . '@example.test' );
}

/**
 * Remove a fixture user and its rows.
 *
 * @param int $uid User ID.
 * @return void
 */
function fx_cleanup( $uid ) {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}at8mluc_points_ledger WHERE user_id = %d", $uid ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}at8mluc_points_account WHERE user_id = %d", $uid ) );
	wp_delete_user( $uid );
}

/**
 * Ledger row count for a user.
 *
 * @param int $uid User ID.
 * @return int
 */
function fx_rows( $uid ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}at8mluc_points_ledger WHERE user_id = %d", $uid )
	);
}

/**
 * Account row existence.
 *
 * @param int $uid User ID.
 * @return bool
 */
function fx_has_account( $uid ) {
	global $wpdb;

	return (bool) $wpdb->get_var(
		$wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}at8mluc_points_account WHERE user_id = %d", $uid )
	);
}

/**
 * Run a body with a query rewriter installed.
 *
 * @param callable $rewrite Rewriter.
 * @param callable $body    Body.
 * @return mixed
 */
function fx_with( $rewrite, $body ) {
	add_filter( 'query', $rewrite );
	$result = $body();
	remove_filter( 'query', $rewrite );

	return $result;
}

/**
 * Break one exact statement.
 *
 * @param string $needle      Exact trimmed statement.
 * @param callable $body      Body.
 * @param string $replacement Invalid replacement, '' for "<needle>_AT8_BROKEN".
 * @param int    $skip        Let the first N matches through.
 * @return mixed
 */
function fx_break_exact( $needle, $body, $replacement = '', $skip = 0 ) {
	$seen = 0;

	return fx_with(
		function ( $query ) use ( $needle, &$seen, $skip, $replacement ) {
			if ( trim( $query ) !== $needle ) {
				return $query;
			}

			$seen++;

			if ( $seen <= $skip ) {
				return $query;
			}

			return ( '' !== $replacement ) ? $replacement : $needle . '_AT8_BROKEN';
		},
		$body
	);
}

/**
 * Break the Nth write into the ledger table.
 *
 * @param int      $nth  Which insert to break (1-based).
 * @param callable $body Body.
 * @return mixed
 */
function fx_break_ledger_nth( $nth, $body ) {
	global $wpdb;

	$insert = "INSERT INTO {$wpdb->prefix}at8mluc_points_ledger";
	$seen   = 0;

	return fx_with(
		function ( $query ) use ( $insert, $nth, &$seen ) {
			if ( 0 !== strpos( $query, $insert ) ) {
				return $query;
			}

			$seen++;

			return ( $seen === $nth ) ? $insert . ' (no_such_column) VALUES (1)' : $query;
		},
		$body
	);
}

/**
 * Break the account-row insert (wpdb::insert quotes the table name).
 *
 * @param callable $body Body.
 * @return mixed
 */
function fx_break_account_insert( $body ) {
	return fx_with(
		function ( $query ) {
			if ( false !== strpos( $query, 'INSERT INTO' ) && false !== strpos( $query, 'at8mluc_points_account' ) ) {
				return 'INSERT INTO wp_at8mluc_points_account (no_such_column) VALUES (1)';
			}

			return $query;
		},
		$body
	);
}

/**
 * Structured data of a transaction failure.
 *
 * @param mixed  $error Failure.
 * @param string $key   Field.
 * @return string
 */
function fx_data( $error, $key ) {
	if ( ! is_wp_error( $error ) ) {
		return '';
	}

	$data = $error->get_error_data();

	return ( is_array( $data ) && isset( $data[ $key ] ) ) ? (string) $data[ $key ] : '';
}

/**
 * Build a user whose points only exist as legacy meta.
 *
 * @param int $amount Legacy amount.
 * @return int
 */
function fx_legacy_user( $amount = 30 ) {
	$uid = fx_user();

	add_user_meta( $uid, 'at8mluc_points_entry', array( 'time' => time() - 120, 'amount' => $amount, 'reason' => 'legacy seed', 'source' => 'admin', 'by' => 0, 'key' => 'legacy:seed:' . $uid, 'u' => 'seed' ) );
	update_user_meta( $uid, 'at8mluc_points', $amount );

	return $uid;
}

$errors = array();
$catch  = function ( $error, $data = array() ) use ( &$errors ) {
	$errors[] = $data;
};
add_action( 'at8mluc_points_transaction_error', $catch, 10, 2 );

$events  = array();
$confirm = function () use ( &$events ) {
	$events[] = 'confirmed';
};
$defer   = function () use ( &$events ) {
	$events[] = 'deferred';
};
add_action( 'at8mluc_points_adjusted', $confirm );
add_action( 'at8mluc_points_adjusted_deferred', $defer );

/* ==========================================================================
 * A. COMMIT
 * ========================================================================== */

// A1. A normal commit.
$uid = fx_user();
$a1  = AT8MLUC_Points::adjust( $uid, 20, 'commit ok', 'admin' );
fok( 20 === $a1, 'A1 commit success: the movement applies (20)' );
fok( 1 === fx_rows( $uid ), 'A1 commit success: one ledger row' );
fok( 20 === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'A1 commit success: the mirror is written' );

// A2. COMMIT refused; the rollback works.
$events  = array();
$errors  = array();
$commits = 0;

$a2 = fx_with(
	function ( $query ) use ( &$commits ) {
		if ( 'COMMIT' === trim( $query ) ) {
			$commits++;

			return 'COMMIT_AT8_BROKEN';
		}

		return $query;
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 10, 'commit refused', 'admin' );
	}
);

fok( is_wp_error( $a2 ), 'A2 commit refused: the caller gets an error' );
fok( is_wp_error( $a2 ) && 'at8mluc_points_tx' === $a2->get_error_code(), 'A2 commit refused: the error is a transaction error' );
fok( 1 === $commits, 'A2 commit refused: COMMIT is attempted exactly once (' . $commits . '), never retried' );
fok( 'commit' === fx_data( $a2, 'operation' ), 'A2 commit refused: the failure names the commit operation' );
fok( '' !== fx_data( $a2, 'original_error' ), 'A2 commit refused: the original driver error is kept' );
fok( in_array( fx_data( $a2, 'transaction_state' ), array( 'rolled_back', 'unknown' ), true ), 'A2 commit refused: the reported state is explicit (' . fx_data( $a2, 'transaction_state' ) . ')' );
fok( (string) $uid === fx_data( $a2, 'user_id' ), 'A2 commit refused: the failure names the user' );
fok( 'adjust' === fx_data( $a2, 'context' ), 'A2 commit refused: the failure names the context' );
fok( ! in_array( 'confirmed', $events, true ), 'A2 commit refused: the success action never fires' );
fok( 20 === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'A2 commit refused: the mirror is untouched (20)' );
fok( 20 === (int) AT8MLUC_Points::balance( $uid ), 'A2 commit refused: the balance did not move (20)' );
fok( 1 === fx_rows( $uid ), 'A2 commit refused: no extra ledger row, so nothing is retried (1)' );

// A3. COMMIT and the ROLLBACK both refused: the state is unknown.
$errors = array();
$a3     = fx_with(
	function ( $query ) {
		$q = trim( $query );

		if ( 'COMMIT' === $q || 'ROLLBACK' === $q ) {
			return $q . '_AT8_BROKEN';
		}

		return $query;
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 10, 'commit and rollback refused', 'admin' );
	}
);

fok( is_wp_error( $a3 ), 'A3 commit + rollback refused: the caller gets an error' );
fok( 'unknown' === fx_data( $a3, 'transaction_state' ), 'A3 commit + rollback refused: the state is reported unknown' );
fok( 'transaction_state_unknown' === fx_data( $a3, 'failure' ), 'A3 commit + rollback refused: the failure is classified as an unknown state' );
fok( '' !== fx_data( $a3, 'original_error' ) && '' !== fx_data( $a3, 'rollback_error' ), 'A3 commit + rollback refused: both errors are recorded' );
fok( 1 === count( $errors ), 'A3 commit + rollback refused: one transaction report (' . count( $errors ) . ')' );
fok( 'commit' === fx_data( $a3, 'operation' ), 'A3 commit + rollback refused: the operation is the commit' );
fok( ! in_array( 'confirmed', $events, true ), 'A3 commit + rollback refused: no success action' );
fok( 20 === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'A3 commit + rollback refused: the mirror is untouched' );

// A4. The connection still works after an unknown state.
fok( 20 === (int) AT8MLUC_Points::balance( $uid ), 'A4 after an unknown state: later reads still work (20)' );
fok( 25 === AT8MLUC_Points::adjust( $uid, 5, 'after unknown state', 'admin' ), 'A4 after an unknown state: later writes still work (25)' );

/* ==========================================================================
 * B. ROLLBACK
 * ========================================================================== */

// B1. A failed write that rolls back cleanly keeps the business error.
$b1 = fx_break_ledger_nth(
	1,
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 5, 'ledger refused', 'admin' );
	}
);
fok( is_wp_error( $b1 ), 'B1 rollback success: the failed write is reported' );
fok( 'at8mluc_points_save' === ( is_wp_error( $b1 ) ? $b1->get_error_code() : '' ), 'B1 rollback success: it stays a business failure, not a transaction failure (' . ( is_wp_error( $b1 ) ? $b1->get_error_code() : '-' ) . ')' );
fok( 25 === (int) AT8MLUC_Points::balance( $uid ), 'B1 rollback success: the balance is unchanged (25)' );
fok( 2 === fx_rows( $uid ), 'B1 rollback success: no partial movement survives' );

// B2. A failed write whose rollback also fails reports an unknown state.
$b2 = fx_with(
	function ( $query ) {
		$q = trim( $query );

		if ( 'ROLLBACK' === $q ) {
			return 'ROLLBACK_AT8_BROKEN';
		}

		if ( 0 === strpos( $q, 'INSERT INTO ' . $GLOBALS['wpdb']->prefix . 'at8mluc_points_ledger' ) ) {
			return 'INSERT INTO ' . $GLOBALS['wpdb']->prefix . 'at8mluc_points_ledger (no_such_column) VALUES (1)';
		}

		return $query;
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 5, 'ledger and rollback refused', 'admin' );
	}
);

fok( is_wp_error( $b2 ), 'B2 rollback failure: the caller gets an error' );
fok( is_wp_error( $b2 ) && 'at8mluc_points_tx' === $b2->get_error_code(), 'B2 rollback failure: it is a transaction error' );
fok( 'rollback' === fx_data( $b2, 'operation' ), 'B2 rollback failure: the operation names the rollback' );
fok( '' !== fx_data( $b2, 'rollback_error' ), 'B2 rollback failure: the rollback error is recorded' );
fok( 'unknown' === fx_data( $b2, 'transaction_state' ), 'B2 rollback failure: the state is unknown' );
fok( 'rollback_failed' === fx_data( $b2, 'failure' ), 'B2 rollback failure: the failure names the rollback (' . fx_data( $b2, 'failure' ) . ')' );

/* ==========================================================================
 * C. migrate_user()
 * ========================================================================== */

// C1. A normal migration.
$uid = fx_legacy_user( 30 );
$c1  = AT8MLUC_Points::balance( $uid );
fok( 30 === $c1, 'C1 normal migration: the legacy balance is imported (30)' );
fok( 1 === fx_rows( $uid ), 'C1 normal migration: one movement' );
fok( fx_has_account( $uid ), 'C1 normal migration: the account row exists' );
fok( AT8MLUC_Points::verify_balance( $uid ), 'C1 normal migration: balance equals the ledger sum' );

// C2. Repeating the migration must not duplicate anything.
$c2 = AT8MLUC_Points::balance( $uid );
fok( 30 === (int) $c2, 'C2 repeat migration: the balance is unchanged (30)' );
fok( 1 === fx_rows( $uid ), 'C2 repeat migration: no duplicate movement' );

// C3. The account row cannot be created.
$uid = fx_legacy_user( 12 );
$c3  = fx_break_account_insert(
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 1, 'migrate trigger', 'admin' );
	}
);

fok( is_wp_error( $c3 ), 'C3 account init failure: the operation reports an error' );
fok( 0 === fx_rows( $uid ), 'C3 account init failure: no movement was imported (' . fx_rows( $uid ) . ')' );
fok( ! fx_has_account( $uid ), 'C3 account init failure: no account row was left behind' );
fok( 12 === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'C3 account init failure: the legacy meta is untouched (12)' );
fok( 12 === (int) AT8MLUC_Points::balance( $uid ), 'C3 account init failure: the display still falls back to the legacy total (12)' );
$c3b = AT8MLUC_Points::adjust( $uid, 1, 'migrate retry', 'admin' );
fok( 13 === $c3b, 'C3 account init failure: a later call migrates and applies (13)' );
fx_cleanup( $uid );

// C4-C5. The first and a middle movement fail.
foreach ( array( 1 => 'first', 2 => 'middle' ) as $nth => $label ) {
	$uid = fx_user();
	add_user_meta( $uid, 'at8mluc_points_entry', array( 'time' => time() - 200, 'amount' => 10, 'reason' => 'a', 'source' => 'admin', 'by' => 0, 'key' => '', 'u' => 'a' ) );
	add_user_meta( $uid, 'at8mluc_points_entry', array( 'time' => time() - 100, 'amount' => 20, 'reason' => 'b', 'source' => 'admin', 'by' => 0, 'key' => '', 'u' => 'b' ) );
	add_user_meta( $uid, 'at8mluc_points_entry', array( 'time' => time() - 50, 'amount' => 30, 'reason' => 'c', 'source' => 'admin', 'by' => 0, 'key' => '', 'u' => 'c' ) );
	update_user_meta( $uid, 'at8mluc_points', 60 );

	$before = fx_rows( $uid );
	$res    = fx_break_ledger_nth(
		$nth,
		function () use ( $uid ) {
			return AT8MLUC_Points::adjust( $uid, 1, 'migrate trigger', 'admin' );
		}
	);

	fok( is_wp_error( $res ), 'C4 ' . $label . ' movement failure: the operation reports an error' );
	fok( $before === fx_rows( $uid ), 'C4 ' . $label . ' movement failure: no movement was left behind (' . fx_rows( $uid ) . ')' );
	fok( ! fx_has_account( $uid ), 'C4 ' . $label . ' movement failure: no half-migrated account row' );

	$retry = AT8MLUC_Points::balance( $uid );
	fok( 60 === $retry, 'C4 ' . $label . ' movement failure: the retry imports everything (60)' );
	fok( 3 === fx_rows( $uid ), 'C4 ' . $label . ' movement failure: three movements after the retry' );
	fok( AT8MLUC_Points::verify_balance( $uid ), 'C4 ' . $label . ' movement failure: balance equals the ledger sum' );
	fx_cleanup( $uid );
}

// C6. The last movement fails.
$uid = fx_user();
add_user_meta( $uid, 'at8mluc_points_entry', array( 'time' => time() - 200, 'amount' => 10, 'reason' => 'a', 'source' => 'admin', 'by' => 0, 'key' => '', 'u' => 'a' ) );
add_user_meta( $uid, 'at8mluc_points_entry', array( 'time' => time() - 100, 'amount' => 20, 'reason' => 'b', 'source' => 'admin', 'by' => 0, 'key' => '', 'u' => 'b' ) );
update_user_meta( $uid, 'at8mluc_points', 30 );

$c6 = fx_break_ledger_nth(
	2,
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 1, 'migrate trigger', 'admin' );
	}
);
fok( is_wp_error( $c6 ), 'C6 last movement failure: the operation reports an error' );
fok( 0 === fx_rows( $uid ), 'C6 last movement failure: the earlier movements were rolled back too (' . fx_rows( $uid ) . ')' );
fok( ! fx_has_account( $uid ), 'C6 last movement failure: no account row' );
fok( 30 === (int) AT8MLUC_Points::balance( $uid ), 'C6 last movement failure: the retry imports everything (30)' );
fx_cleanup( $uid );

// C7. The opening balance movement fails.
$uid = fx_user();
update_user_meta( $uid, 'at8mluc_points', 25 );
$c7 = fx_break_ledger_nth(
	1,
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 1, 'migrate trigger', 'admin' );
	}
);
fok( is_wp_error( $c7 ), 'C7 opening balance failure: the operation reports an error' );
fok( 0 === fx_rows( $uid ), 'C7 opening balance failure: nothing was imported' );
fok( ! fx_has_account( $uid ), 'C7 opening balance failure: no account row' );
fok( 25 === (int) AT8MLUC_Points::balance( $uid ), 'C7 opening balance failure: the retry imports the opening balance (25)' );
fx_cleanup( $uid );

// C8. COMMIT fails during a migration.
$uid    = fx_legacy_user( 18 );
$errors = array();
$c8     = fx_break_exact(
	'COMMIT',
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 1, 'migrate trigger', 'admin' );
	}
);
fok( is_wp_error( $c8 ), 'C8 migration commit failure: the operation reports an error' );
fok( is_wp_error( $c8 ) && 'at8mluc_points_tx' === $c8->get_error_code(), 'C8 migration commit failure: it is a transaction error' );
fok( 'migrate' === fx_data( $c8, 'context' ), 'C8 migration commit failure: the context is the migration' );
fok( 'commit' === fx_data( $c8, 'operation' ), 'C8 migration commit failure: the operation is the commit' );
fok( '' !== fx_data( $c8, 'original_error' ), 'C8 migration commit failure: the driver error is recorded' );
fok( ! fx_has_account( $uid ) || 0 < fx_rows( $uid ), 'C8 migration commit failure: the migration is not reported as a success (' . fx_rows( $uid ) . ' rows)' );
fx_cleanup( $uid );

// C9. ROLLBACK fails during a migration.
$uid = fx_legacy_user( 21 );
$c9  = fx_with(
	function ( $query ) {
		$q = trim( $query );

		if ( 'ROLLBACK' === $q ) {
			return 'ROLLBACK_AT8_BROKEN';
		}

		if ( 0 === strpos( $q, 'INSERT INTO ' . $GLOBALS['wpdb']->prefix . 'at8mluc_points_ledger' ) ) {
			return 'INSERT INTO ' . $GLOBALS['wpdb']->prefix . 'at8mluc_points_ledger (no_such_column) VALUES (1)';
		}

		return $query;
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 1, 'migrate trigger', 'admin' );
	}
);
fok( is_wp_error( $c9 ), 'C9 migration rollback failure: the operation reports an error' );
fok( 'unknown' === fx_data( $c9, 'transaction_state' ), 'C9 migration rollback failure: the state is reported unknown (' . fx_data( $c9, 'transaction_state' ) . ')' );
fok( in_array( fx_data( $c9, 'operation' ), array( 'migrate', 'rollback' ), true ), 'C9 migration rollback failure: the operation is the migration or its rollback (' . fx_data( $c9, 'operation' ) . ')' );
fok( '' !== fx_data( $c9, 'rollback_error' ), 'C9 migration rollback failure: the rollback error is recorded' );
fx_cleanup( $uid );

// C10. Two reads migrating the same user at once.
$uid     = fx_legacy_user( 40 );
$results = array();

for ( $i = 0; $i < 2; $i++ ) {
	$results[] = AT8MLUC_Points::balance( $uid );
}
fok( 40 === (int) $results[0] && 40 === (int) $results[1], 'C10 concurrent migration: both reads agree (40)' );
fok( 1 === fx_rows( $uid ), 'C10 concurrent migration: exactly one movement (' . fx_rows( $uid ) . ')' );
fok( AT8MLUC_Points::verify_balance( $uid ), 'C10 concurrent migration: balance equals the ledger sum' );
fx_cleanup( $uid );

/* ==========================================================================
 * D. SAVEPOINT
 * ========================================================================== */

// D1. Inside an outer transaction the movement uses a savepoint.
$uid    = fx_user();
$events = array();
$mirror = (int) get_user_meta( $uid, 'at8mluc_points', true );

$wpdb->query( 'START TRANSACTION' );
$d1 = AT8MLUC_Points::adjust( $uid, 10, 'outer savepoint', 'admin' );
$wpdb->query( 'COMMIT' );

fok( 10 === $d1, 'D1 outer transaction: the movement applies inside it (10)' );
fok( in_array( 'deferred', $events, true ) && ! in_array( 'confirmed', $events, true ), 'D1 outer transaction: only the deferred action fires before the outer commit' );
fok( $mirror === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'D1 outer transaction: the mirror waits for the outer commit' );
fok( 10 === (int) AT8MLUC_Points::balance( $uid ), 'D1 outer transaction: the movement survives the outer commit' );

// D2. The plugin never commits or rolls back somebody else's transaction.
$boundary = array(
	'commit'   => 0,
	'rollback' => 0,
	'savepoint' => 0,
);

$watch = function ( $query ) use ( &$boundary ) {
	$q = trim( $query );

	if ( 'COMMIT' === $q ) {
		$boundary['commit']++;
	} elseif ( 'ROLLBACK' === $q ) {
		$boundary['rollback']++;
	} elseif ( 0 === strpos( $q, 'SAVEPOINT at8mluc_points_tx' ) ) {
		$boundary['savepoint']++;
	}

	return $query;
};

$wpdb->query( 'START TRANSACTION' );
$d2 = fx_with(
	$watch,
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 5, 'boundary check', 'admin' );
	}
);
$wpdb->query( 'COMMIT' );

fok( 15 === $d2, 'D2 outer boundary: the movement applies inside the outer transaction (15)' );
fok( 0 === $boundary['commit'], 'D2 outer boundary: the plugin never issues COMMIT (' . $boundary['commit'] . ')' );
fok( 0 === $boundary['rollback'], 'D2 outer boundary: the plugin never issues ROLLBACK (' . $boundary['rollback'] . ')' );
fok( $boundary['savepoint'] >= 1, 'D2 outer boundary: it isolates itself with its own savepoint (' . $boundary['savepoint'] . ')' );

// D3. savepoint creation refused, inside somebody else's transaction.
$boundary = array( 'commit' => 0, 'rollback' => 0 );

$wpdb->query( 'START TRANSACTION' );
$d3 = fx_with(
	$watch,
	function () use ( $uid ) {
		return fx_break_exact(
			'SAVEPOINT at8mluc_points_tx',
			function () use ( $uid ) {
				return AT8MLUC_Points::adjust( $uid, 5, 'savepoint refused', 'admin' );
			},
			'SAVEPOINT'
		);
	}
);

$wpdb->query( 'ROLLBACK' );

fok( is_wp_error( $d3 ), 'D3 savepoint creation failure: the movement is refused' );
fok( 'begin' === fx_data( $d3, 'operation' ), 'D3 savepoint creation failure: the operation is the begin (' . fx_data( $d3, 'operation' ) . ')' );
fok( 'unknown' === fx_data( $d3, 'transaction_state' ), 'D3 savepoint creation failure: the state is unknown' );
fok( 0 === $boundary['commit'] && 0 === $boundary['rollback'], 'D3 savepoint creation failure: the outer transaction is left alone' );
fok( 15 === (int) AT8MLUC_Points::balance( $uid ), 'D3 savepoint creation failure: nothing was written (15)' );

// D4. RELEASE fails, the rewind works.
$events = array();
$errors = array();

$wpdb->query( 'START TRANSACTION' );
$d4 = fx_break_exact(
	'RELEASE SAVEPOINT at8mluc_points_tx',
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 5, 'release refused', 'admin' );
	}
);
$wpdb->query( 'COMMIT' );

fok( is_wp_error( $d4 ), 'D4 release failure: the movement is not confirmed' );
fok( 'rolled_back' === fx_data( $d4, 'transaction_state' ), 'D4 release failure: the state is reported as rolled back' );
fok( 'release' === fx_data( $d4, 'operation' ), 'D4 release failure: the operation is the release' );
fok( 15 === (int) AT8MLUC_Points::balance( $uid ), 'D4 release failure: the movement was undone (15)' );
fok( 2 === fx_rows( $uid ), 'D4 release failure: no extra ledger row' );
fok( ! in_array( 'confirmed', $events, true ), 'D4 release failure: no success action' );

// D5. RELEASE and the rewind both fail.
$wpdb->query( 'START TRANSACTION' );
$d5 = fx_with(
	function ( $query ) {
		$q = trim( $query );

		if ( 'RELEASE SAVEPOINT at8mluc_points_tx' === $q ) {
			return 'RELEASE SAVEPOINT at8mluc_points_absent';
		}

		if ( 'ROLLBACK TO SAVEPOINT at8mluc_points_tx' === $q ) {
			return 'ROLLBACK TO SAVEPOINT at8mluc_points_absent';
		}

		return $query;
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 5, 'release and rewind refused', 'admin' );
	}
);
$wpdb->query( 'ROLLBACK' );

fok( is_wp_error( $d5 ), 'D5 release + rewind failure: the movement fails loudly' );
fok( 'unknown' === fx_data( $d5, 'transaction_state' ), 'D5 release + rewind failure: the state is unknown' );
fok( '' !== fx_data( $d5, 'original_error' ) && '' !== fx_data( $d5, 'rollback_error' ), 'D5 release + rewind failure: both errors are recorded' );
fok( 15 === (int) AT8MLUC_Points::balance( $uid ), 'D5 release + rewind failure: the outer rollback leaves 15' );

fx_cleanup( $uid );

/* ==========================================================================
 * E. Business regressions
 * ========================================================================== */

$uid = fx_user();

fok( 100 === AT8MLUC_Points::adjust( $uid, 100, 'add', 'admin' ), 'E1 add: 100' );
fok( 60 === AT8MLUC_Points::adjust( $uid, -40, 'deduct', 'admin' ), 'E2 deduct: 60' );

$over = AT8MLUC_Points::adjust( $uid, -61, 'too much', 'admin' );
fok( is_wp_error( $over ) && 'at8mluc_points_balance' === $over->get_error_code(), 'E3 overdraft: refused with a balance error' );
fok( 60 === (int) AT8MLUC_Points::balance( $uid ), 'E3 overdraft: the balance is unchanged (60)' );

$keyed = AT8MLUC_Points::adjust( $uid, 10, 'keyed', 'purchase', 'order:' . $uid );
$again = AT8MLUC_Points::adjust( $uid, 10, 'keyed', 'purchase', 'order:' . $uid );
fok( 70 === $keyed && 70 === $again, 'E4 same idempotency key: the second call pays nothing extra (70)' );
fok( 1 === count( array_filter( AT8MLUC_Points::ledger( $uid, 50 ), function ( $e ) use ( $uid ) { return 'order:' . $uid === $e['key']; } ) ), 'E4 same idempotency key: exactly one row carries it' );

fok( AT8MLUC_Points::verify_balance( $uid ), 'E5 balance equals the ledger sum after the business cases' );

$repair = AT8MLUC_Points::reconcile( $uid );
fok( 70 === $repair, 'E6 reconcile: returns the ledger sum (70)' );
fok( AT8MLUC_Points::verify_balance( $uid ), 'E6 reconcile: the account is consistent afterwards' );

$broken = fx_with(
	function ( $query ) {
		return ( false !== strpos( $query, 'SELECT COALESCE( SUM( amount ), 0 ) FROM' ) ) ? 'SELECT no_such_function( 1 )' : $query;
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::reconcile( $uid );
	}
);
fok( is_wp_error( $broken ) && 'at8mluc_points_read' === $broken->get_error_code(), 'E7 reconcile read failure: reported as a read error' );
fok( 70 === (int) AT8MLUC_Points::balance( $uid ), 'E7 reconcile read failure: the balance is not zeroed (70)' );

fx_cleanup( $uid );

echo "==== {$GLOBALS['xp']} passed, {$GLOBALS['xf']} failed ====\n";
