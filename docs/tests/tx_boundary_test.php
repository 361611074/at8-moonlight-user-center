<?php
/**
 * Transaction-boundary hardening suite for the points storage (2.6.4).
 *
 * The theme of this file is: a transaction state that cannot be determined is
 * an error, never a guess. Every case below injects a specific driver failure
 * and asserts both what the plugin returns and what it did (or did not) write.
 *
 * Run: wp eval-file tx_boundary_test.php
 */

global $wpdb;

$GLOBALS['bp'] = 0;
$GLOBALS['bf'] = 0;

/**
 * Record one assertion.
 *
 * @param bool   $condition Assertion.
 * @param string $label     Description.
 * @return void
 */
function bok( $condition, $label ) {
	if ( $condition ) {
		$GLOBALS['bp']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['bf']++;
		echo "FAIL  $label\n";
	}
}

/**
 * Create a throwaway user.
 *
 * @return int
 */
function bx_user() {
	$name = 'bx_' . wp_generate_password( 6, false );

	return (int) wp_create_user( $name, 'x!*Bx12345', $name . '@example.test' );
}

/**
 * Remove a fixture user and its rows.
 *
 * @param int $uid User ID.
 * @return void
 */
function bx_cleanup( $uid ) {
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
function bx_rows( $uid ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}at8mluc_points_ledger WHERE user_id = %d", $uid )
	);
}

/**
 * Install a query rewriter for the duration of a body.
 *
 * @param callable $rewrite Receives the SQL, returns the replacement.
 * @param callable $body    Runs while the filter is active.
 * @return mixed
 */
function bx_with( $rewrite, $body ) {
	add_filter( 'query', $rewrite );
	$result = $body();
	remove_filter( 'query', $rewrite );

	return $result;
}

/**
 * Rewrite one exact statement into a broken one.
 *
 * @param string $needle  Fragment identifying the statement.
 * @param string $replace Broken replacement.
 * @param mixed  $body    Body to run.
 * @return mixed
 */
function bx_break( $needle, $replace, $body ) {
	return bx_with(
		function ( $query ) use ( $needle, $replace ) {
			return ( false === strpos( $query, $needle ) ) ? $query : $replace;
		},
		$body
	);
}

/**
 * Make the @@in_transaction probe fail.
 *
 * @param mixed $body Body to run.
 * @return mixed
 */
function bx_no_state_query( $body ) {
	return bx_break( 'SELECT @@in_transaction', 'SELECT @@in_transaction,', $body );
}

$errors = array();
$catch  = function ( $error ) use ( &$errors ) {
	$errors[] = $error->get_error_code() . ': ' . $error->get_error_message();
};
add_action( 'at8mluc_points_transaction_error', $catch );

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
 * 1. SELECT @@in_transaction answers 0 — a movement owns its transaction
 * ========================================================================== */

$uid     = bx_user();
$probes  = 0;
$counter = function ( $query ) use ( &$probes ) {
	if ( false !== strpos( $query, 'SAVEPOINT at8mluc_points_probe' ) ) {
		$probes++;
	}

	return $query;
};

$own = bx_with(
	function ( $query ) use ( $counter ) {
		$query = $counter( $query );

		return ( false !== strpos( $query, 'SELECT @@in_transaction' ) ) ? "SELECT '0' AS in_tx" : $query;
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 30, 'state 0', 'admin' );
	}
);

bok( 30 === $own, 'state 0: the movement applies (30)' );
bok( 0 === $probes, 'state 0: no savepoint probe is needed when the answer is known' );
bok( 1 === bx_rows( $uid ), 'state 0: one ledger row' );
bok( 30 === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'state 0: the mirror is written because this request owns the commit' );

/* ==========================================================================
 * 2. SELECT @@in_transaction answers 1 — the savepoint path
 * ========================================================================== */

$events = array();
$mirror = (int) get_user_meta( $uid, 'at8mluc_points', true );

$wpdb->query( 'START TRANSACTION' );
$inside = bx_break(
	'SELECT @@in_transaction',
	"SELECT '1' AS in_tx",
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 10, 'state 1', 'admin' );
	}
);
$wpdb->query( 'COMMIT' );

bok( 40 === $inside, 'state 1: the movement applies inside the outer transaction (40)' );
bok( in_array( 'deferred', $events, true ) && ! in_array( 'confirmed', $events, true ), 'state 1: only the deferred action fires' );
bok( $mirror === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'state 1: the mirror is left alone while somebody else owns the commit' );
bok( 2 === bx_rows( $uid ) && 40 === (int) AT8MLUC_Points::balance( $uid ), 'state 1: the movement survives the outer commit (40, 2 rows)' );

/* ==========================================================================
 * 3. The state query fails — the probe answers, and only the probe
 * ========================================================================== */

$uid = bx_user();

$fallback = bx_no_state_query(
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 6, 'probe answer', 'admin' );
	}
);

bok( 6 === $fallback, 'state query failure: the savepoint probe answers and the movement applies (6)' );
bok( 1 === bx_rows( $uid ), 'state query failure: one ledger row' );

// Both probes broken: refuse, do not guess.
$blocked = bx_no_state_query(
	function () use ( $uid ) {
		return bx_break(
			'SAVEPOINT at8mluc_points_probe',
			'SAVEPOINT',
			function () use ( $uid ) {
				return AT8MLUC_Points::adjust( $uid, 6, 'no answer', 'admin' );
			}
		);
	}
);

bok( is_wp_error( $blocked ), 'state unknown: the movement is refused' );
bok( is_wp_error( $blocked ) && 'at8mluc_points_tx' === $blocked->get_error_code(), 'state unknown: the error is a transaction error' );
bok( is_wp_error( $blocked ) && false !== strpos( $blocked->get_error_message(), 'could not be determined' ), 'state unknown: the message says the state could not be determined' );
bok( 6 === (int) AT8MLUC_Points::balance( $uid ), 'state unknown: nothing was written (6)' );
bx_cleanup( $uid );

/* ==========================================================================
 * 4. The probe savepoint cannot be created
 * ========================================================================== */

$uid = bx_user();
AT8MLUC_Points::adjust( $uid, 10, 'seed', 'admin' );

$no_probe = bx_no_state_query(
	function () use ( $uid ) {
		return bx_break(
			'SAVEPOINT at8mluc_points_probe',
			'SAVEPOINT',
			function () use ( $uid ) {
				return AT8MLUC_Points::adjust( $uid, 5, 'probe refused', 'admin' );
			}
		);
	}
);

bok( is_wp_error( $no_probe ), 'probe create failure: the movement is refused' );
bok( is_wp_error( $no_probe ) && 'at8mluc_points_tx' === $no_probe->get_error_code(), 'probe create failure: the error is a transaction error' );
bok( 10 === (int) AT8MLUC_Points::balance( $uid ), 'probe create failure: the balance is unchanged (10)' );
bok( 1 === bx_rows( $uid ), 'probe create failure: no extra ledger row' );

/* ==========================================================================
 * 5. The probe is created but cannot be rewound — an unexplained error
 * ========================================================================== */

$unexplained = bx_no_state_query(
	function () use ( $uid ) {
		return bx_break(
			'ROLLBACK TO SAVEPOINT at8mluc_points_probe',
			'SELECT * FROM wp_table_that_does_not_exist',
			function () use ( $uid ) {
				return AT8MLUC_Points::adjust( $uid, 5, 'probe rewind refused', 'admin' );
			}
		);
	}
);

bok( is_wp_error( $unexplained ), 'probe rewind failure: the movement is refused instead of assuming "no transaction"' );
bok( is_wp_error( $unexplained ) && 'at8mluc_points_tx' === $unexplained->get_error_code(), 'probe rewind failure: the error is a transaction error' );
bok( is_wp_error( $unexplained ) && false !== strpos( $unexplained->get_error_message(), 'could not be determined' ), 'probe rewind failure: the message admits the state is unknown' );
bok( 10 === (int) AT8MLUC_Points::balance( $uid ), 'probe rewind failure: the balance is unchanged (10)' );
bok( 1 === bx_rows( $uid ), 'probe rewind failure: no extra ledger row' );

/* ==========================================================================
 * 6. The documented 1305 answer is the only one read as "no transaction"
 * ========================================================================== */

$reflect = new ReflectionMethod( 'AT8MLUC_Points', 'is_missing_savepoint_error' );
$reflect->setAccessible( true );

bok( true === $reflect->invoke( null, 'SQLSTATE[42000]: Syntax error or access violation: 1305 SAVEPOINT `x` does not exist' ), 'classification: 1305 is the "no transaction" answer' );
bok( true === $reflect->invoke( null, 'SQLSTATE[3B001]: SAVEPOINT x does not exist' ), 'classification: SQLSTATE 3B001 is the "no transaction" answer' );
bok( false === $reflect->invoke( null, 'SQLSTATE[42S02]: Base table or view not found: 1146 Table wp_x does not exist' ), 'classification: a missing table is not the "no transaction" answer' );
bok( false === $reflect->invoke( null, '' ), 'classification: an empty error is not the "no transaction" answer' );
bok( false === $reflect->invoke( null, 'Lock wait timeout exceeded' ), 'classification: a lock timeout is not the "no transaction" answer' );

/* ==========================================================================
 * 7. RELEASE SAVEPOINT fails, the rewind works
 * ========================================================================== */

$uid    = bx_user();
AT8MLUC_Points::adjust( $uid, 10, 'seed', 'admin' );
$errors = array();
$events = array();
$mirror = (int) get_user_meta( $uid, 'at8mluc_points', true );

$wpdb->query( 'START TRANSACTION' );
$release_failed = bx_break(
	'RELEASE SAVEPOINT at8mluc_points_tx',
	'RELEASE SAVEPOINT at8mluc_points_absent',
	function () use ( $uid ) {
		return AT8MLUC_Points::adjust( $uid, 5, 'release refused', 'admin' );
	}
);
$wpdb->query( 'COMMIT' );

bok( is_wp_error( $release_failed ), 'release failure: the movement is reported as not confirmed' );
bok( is_wp_error( $release_failed ) && 'at8mluc_points_tx' === $release_failed->get_error_code(), 'release failure: the error is a transaction error' );
bok( is_wp_error( $release_failed ) && false !== strpos( $release_failed->get_error_message(), 'undone inside the enclosing transaction' ), 'release failure: the message says the movement was undone' );
bok( 1 === count( $errors ), 'release failure: the transaction error was reported once (' . count( $errors ) . ')' );
bok( ! in_array( 'confirmed', $events, true ), 'release failure: no success action fired' );
bok( 10 === (int) AT8MLUC_Points::balance( $uid ), 'release failure: the outer commit leaves the balance at 10' );
bok( $mirror === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'release failure: the mirror was not written' );
bok( 1 === bx_rows( $uid ), 'release failure: no extra ledger row' );
bok( AT8MLUC_Points::verify_balance( $uid ), 'release failure: the account is still consistent' );

/* ==========================================================================
 * 8. RELEASE and the rewind both fail
 * ========================================================================== */

$uid    = bx_user();
AT8MLUC_Points::adjust( $uid, 10, 'seed', 'admin' );
$errors = array();

$wpdb->query( 'START TRANSACTION' );
$double_failed = bx_break(
	'RELEASE SAVEPOINT at8mluc_points_tx',
	'RELEASE SAVEPOINT at8mluc_points_absent',
	function () use ( $uid ) {
		return bx_break(
			'ROLLBACK TO SAVEPOINT at8mluc_points_tx',
			'ROLLBACK TO SAVEPOINT at8mluc_points_absent_too',
			function () use ( $uid ) {
				return AT8MLUC_Points::adjust( $uid, 5, 'release and rewind refused', 'admin' );
			}
		);
	}
);
$wpdb->query( 'ROLLBACK' );

bok( is_wp_error( $double_failed ), 'release + rewind failure: the movement fails loudly' );
bok( is_wp_error( $double_failed ) && 'at8mluc_points_tx' === $double_failed->get_error_code(), 'release + rewind failure: the error is a transaction error' );
bok( is_wp_error( $double_failed ) && false !== strpos( $double_failed->get_error_message(), 'may still carry the unconfirmed points movement' ), 'release + rewind failure: the message warns that the outer transaction may still hold the movement' );
bok( 1 === count( $errors ), 'release + rewind failure: the transaction error was reported once' );
bok( 10 === (int) AT8MLUC_Points::balance( $uid ), 'release + rewind failure: the outer rollback leaves the balance at 10' );

/* ==========================================================================
 * 9. An outer transaction that commits, and one that rolls back
 * ========================================================================== */

$events = array();
$mirror = (int) get_user_meta( $uid, 'at8mluc_points', true );

$wpdb->query( 'START TRANSACTION' );
AT8MLUC_Points::adjust( $uid, 10, 'outer commit', 'admin' );
$wpdb->query( 'COMMIT' );

bok( 20 === (int) AT8MLUC_Points::balance( $uid ), 'outer commit: the movement is durable (20)' );
bok( $mirror === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'outer commit: the mirror was still not touched by this request' );

$events = array();
$wpdb->query( 'START TRANSACTION' );
AT8MLUC_Points::adjust( $uid, 10, 'outer rollback', 'admin' );
$wpdb->query( 'ROLLBACK' );

bok( 20 === (int) AT8MLUC_Points::balance( $uid ), 'outer rollback: the movement is gone (20)' );
bok( ! in_array( 'confirmed', $events, true ), 'outer rollback: no success action fired' );
bok( AT8MLUC_Points::verify_balance( $uid ), 'outer rollback: the account is still consistent' );
bx_cleanup( $uid );

/* ==========================================================================
 * 10-12. reconcile() tells a database error from a version conflict
 * ========================================================================== */

$uid = bx_user();
AT8MLUC_Points::adjust( $uid, 100, 'seed', 'admin' );

// 10. The repair UPDATE is refused by the driver.
$attempts = 0;
$broken   = bx_with(
	function ( $query ) use ( &$attempts ) {
		if ( preg_match( '/^UPDATE \S*at8mluc_points_account SET balance = -?\d+, fence =/', $query ) ) {
			$attempts++;

			return 'UPDATE ' . $GLOBALS['wpdb']->prefix . 'at8mluc_points_account SET no_such_column = 1 WHERE user_id = 0';
		}

		return $query;
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::reconcile( $uid );
	}
);

bok( is_wp_error( $broken ), 'repair write failure: reconcile() reports an error' );
bok( is_wp_error( $broken ) && 'at8mluc_points_busy' !== $broken->get_error_code(), 'repair write failure: it is not misreported as a busy account (' . ( is_wp_error( $broken ) ? $broken->get_error_code() : '-' ) . ')' );
bok( 1 === $attempts, 'repair write failure: the statement runs once, no pointless retry loop (' . $attempts . ')' );

// 11. The repair UPDATE matches nothing: a real version conflict, retried.
$reads   = 0;
$retried = bx_with(
	function ( $query ) use ( &$reads, $uid ) {
		if ( false !== strpos( $query, 'SELECT balance, fence FROM' ) && false !== strpos( $query, 'user_id = ' . $uid ) ) {
			$reads++;

			if ( 1 === $reads ) {
				return str_replace( 'SELECT balance, fence FROM', 'SELECT balance, fence + 999 AS fence FROM', $query );
			}
		}

		return $query;
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::reconcile( $uid );
	}
);

bok( 100 === $retried, 'version conflict: the repair retries and reports the ledger sum (100)' );
bok( $reads >= 2, 'version conflict: the account was read again after the conflict (' . $reads . ' reads)' );
bok( 100 === (int) AT8MLUC_Points::balance( $uid ), 'version conflict: the balance is intact (100)' );

// 12. The repair UPDATE matches exactly one row.
$attempts = 0;
$clean    = bx_with(
	function ( $query ) use ( &$attempts ) {
		if ( preg_match( '/^UPDATE \S*at8mluc_points_account SET balance = -?\d+, fence =/', $query ) ) {
			$attempts++;
		}

		return $query;
	},
	function () use ( $uid ) {
		return AT8MLUC_Points::reconcile( $uid );
	}
);

bok( 100 === $clean, 'healthy repair: reconcile() returns the ledger sum (100)' );
bok( 1 === $attempts, 'healthy repair: exactly one UPDATE, no conflict path (' . $attempts . ')' );
bok( AT8MLUC_Points::verify_balance( $uid ), 'healthy repair: balance equals the ledger sum' );
bok( 100 === (int) get_user_meta( $uid, 'at8mluc_points', true ), 'healthy repair: the mirror is refreshed when this request owns the commit' );

bx_cleanup( $uid );

/* ==========================================================================
 * 13. The misleading helper is gone
 * ========================================================================== */

$methods = get_class_methods( 'AT8MLUC_Points' );
bok( ! in_array( 'has_open_transaction', $methods, true ), 'cleanup: the obsolete has_open_transaction() is no longer present' );

echo "==== {$GLOBALS['bp']} passed, {$GLOBALS['bf']} failed ====\n";
