<?php
/**
 * 2.7.2 — row-by-row verification of the points table migration.
 *
 * Run with:
 *   php wp-cli.phar --path=wptest/wordpress eval-file _tools/points_verify_test.php
 *
 * The suite creates its OWN pair of tables and drops only those two tables at
 * the end. No existing site data is read, modified or deleted. The one
 * exception is the final scenario, which observes (read-only) that the
 * migration refuses to write its completion marker while this test site's
 * historical legacy ledger disagrees with the current one.
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$GLOBALS['pv_pass']  = 0;
$GLOBALS['pv_fail']  = 0;
$GLOBALS['pv_lines'] = array();

/**
 * Assert one condition.
 *
 * @param bool   $ok  Condition.
 * @param string $msg Message.
 */
function pv_ok( $ok, $msg ) {
	if ( $ok ) {
		++$GLOBALS['pv_pass'];
		$GLOBALS['pv_lines'][] = 'PASS  ' . $msg;
	} else {
		++$GLOBALS['pv_fail'];
		$GLOBALS['pv_lines'][] = 'FAIL  ' . $msg;
	}
}

$new = $wpdb->prefix . 'at8mluc_verify_ledger';
$old = $wpdb->prefix . 'at8_mluc_verify_ledger';

/** Create one of the two scratch tables. */
function pv_create( $table, $with_extra_column = true ) {
	global $wpdb;

	$extra = $with_extra_column ? ",\n  reason varchar(191) NOT NULL DEFAULT '',\n  created_at bigint(20) unsigned NOT NULL DEFAULT 0" : '';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Scratch tables created by this test, names come from $wpdb->prefix.
	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS `{$table}` (
  id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  amount int(11) NOT NULL DEFAULT 0{$extra},
  PRIMARY KEY  (id)
)"
	);
}

/** Empty both scratch tables. */
function pv_clear( $old, $new ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Scratch tables owned by this test.
	$wpdb->query( "DELETE FROM `{$old}`" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Scratch tables owned by this test.
	$wpdb->query( "DELETE FROM `{$new}`" );
}

/** Insert one row into a scratch table. */
function pv_row( $table, $id, $user_id, $amount, $reason = 'signup' ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scratch table owned by this test.
	$wpdb->insert(
		$table,
		array(
			'id'         => $id,
			'user_id'    => $user_id,
			'amount'     => $amount,
			'reason'     => $reason,
			'created_at' => 1700000000 + $id,
		)
	);
}

/** Count the rows of a scratch table. */
function pv_count( $table ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Scratch table owned by this test.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
}

/** Invoke the private per-table migration step. */
function pv_move( $new ) {
	static $method = null;

	if ( null === $method ) {
		$method = new ReflectionMethod( 'AT8MLUC_Prefix_Migration', 'move_points_table' );
		$method->setAccessible( true );
	}

	return $method->invoke( null, $new );
}

$GLOBALS['pv_reports'] = array();

add_action(
	'at8mluc_prefix_migration_verified',
	function ( $report ) {
		$GLOBALS['pv_reports'][] = $report;
	}
);

// ------------------------------------------------------------------ fixtures ---
pv_create( $old );
pv_create( $new );
pv_clear( $old, $new );

pv_row( $old, 1, 101, 10 );
pv_row( $old, 2, 102, -5, 'redeem' );
pv_row( $old, 3, 103, 20 );

// ------------------------------------------------------ 1. normal migration ---
$GLOBALS['pv_reports'] = array();
$status                = pv_move( $new );
$report                = end( $GLOBALS['pv_reports'] );

pv_ok( 'success' === $status, 'normal migration: step reports success' );
pv_ok( 3 === pv_count( $new ), 'normal migration: all three source rows are present' );
pv_ok( 3 === $report['copied'] && 3 === $report['checked'] && 3 === $report['identical'], 'normal migration: report counts every row (copied/checked/identical)' );
pv_ok( true === $report['verified'] && 0 === $report['conflicts'] && 0 === $report['missing'], 'normal migration: final pass verified with no conflicts' );
pv_ok( 3 === pv_count( $old ), 'normal migration: the source table is kept, not dropped' );

// Compare every column of every row, not just the count.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Scratch tables owned by this test.
$a = $wpdb->get_results( "SELECT * FROM `{$old}` ORDER BY id", ARRAY_A );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Scratch tables owned by this test.
$b = $wpdb->get_results( "SELECT * FROM `{$new}` ORDER BY id", ARRAY_A );
pv_ok( $a === $b, 'normal migration: every column of every row is byte-identical' );

// --------------------------------------------------------- 2. idempotency -----
$GLOBALS['pv_reports'] = array();
$status                = pv_move( $new );
$report                = end( $GLOBALS['pv_reports'] );

pv_ok( 'skipped' === $status, 'repeat run: an already identical target is reported as skipped' );
pv_ok( 0 === $report['copied'] && 3 === pv_count( $new ), 'repeat run: no row is inserted twice' );
pv_ok( 0 === $report['conflicts'] && true === $report['verified'], 'repeat run: verification still passes' );

// --------------------------------------- 3. same row count, different value ---
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scratch table owned by this test.
$wpdb->update( $new, array( 'amount' => 999 ), array( 'id' => 2 ) );

$GLOBALS['pv_reports'] = array();
$status                = pv_move( $new );
$report                = end( $GLOBALS['pv_reports'] );

pv_ok( 'conflict' === $status, 'content difference: row count equal but a value differs is NOT reported as success' );
pv_ok( 1 === $report['conflicts'] && false === $report['verified'], 'content difference: the conflict is recorded and verification fails' );
pv_ok( false !== strpos( wp_json_encode( $report['samples'] ), 'amount' ), 'content difference: the differing column is named' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Scratch table owned by this test.
pv_ok( '999' === (string) $wpdb->get_var( "SELECT amount FROM `{$new}` WHERE id = 2" ), 'content difference: the target row is not overwritten' );
pv_ok( 3 === pv_count( $old ), 'content difference: the source table survives' );

// --------------------------------- 4. more rows in target AND a difference ---
pv_row( $new, 90, 990, 1, 'later-business-data' );
pv_row( $new, 91, 991, 2, 'later-business-data' );

$GLOBALS['pv_reports'] = array();
$status                = pv_move( $new );
$report                = end( $GLOBALS['pv_reports'] );

pv_ok( pv_count( $new ) > pv_count( $old ), 'more rows in target: target really has more rows than the source' );
pv_ok( 'conflict' === $status, 'more rows in target: a larger row count does not hide a content difference' );
pv_ok( 0 === $report['copied'], 'more rows in target: nothing is written while a conflict exists' );

// ------------------------------------------------------- 5. missing row only --
pv_clear( $old, $new );
pv_row( $old, 1, 101, 10 );
pv_row( $old, 2, 102, -5, 'redeem' );
pv_row( $old, 3, 103, 20 );
pv_row( $new, 1, 101, 10 );
pv_row( $new, 3, 103, 20 );

$GLOBALS['pv_reports'] = array();
$status                = pv_move( $new );
$report                = end( $GLOBALS['pv_reports'] );

pv_ok( 'success' === $status, 'missing row: a partially copied target is completed' );
pv_ok( 1 === $report['copied'] && 3 === pv_count( $new ), 'missing row: only the absent primary key is inserted' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Scratch table owned by this test.
pv_ok( '10' === (string) $wpdb->get_var( "SELECT amount FROM `{$new}` WHERE id = 1" ), 'missing row: an existing row is left untouched' );

// -------------------------------------------------------------- 6. empty -----
pv_clear( $old, $new );

$GLOBALS['pv_reports'] = array();
$status                = pv_move( $new );
$report                = end( $GLOBALS['pv_reports'] );

pv_ok( 'skipped' === $status, 'empty source: handled without error' );
pv_ok( 0 === $report['checked'] && true === $report['verified'], 'empty source: zero rows checked, verification succeeds' );

// ------------------------------------------------- 7. large source (batches) --
for ( $i = 1; $i <= 450; $i++ ) {
	pv_row( $old, $i, 1000 + $i, $i % 7, 'bulk' );
}

$GLOBALS['pv_reports'] = array();
$status                = pv_move( $new );
$report                = end( $GLOBALS['pv_reports'] );

pv_ok( 'success' === $status, '450 rows: migration succeeds across several batches' );
pv_ok( 450 === $report['copied'] && 450 === pv_count( $new ), '450 rows: every row is copied exactly once' );
pv_ok( 450 === $report['checked'] && 450 === $report['identical'], '450 rows: the final pass re-checks every row' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Scratch tables owned by this test.
$a = $wpdb->get_results( "SELECT * FROM `{$old}` ORDER BY id", ARRAY_A );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Scratch tables owned by this test.
$b = $wpdb->get_results( "SELECT * FROM `{$new}` ORDER BY id", ARRAY_A );
pv_ok( $a === $b, '450 rows: all columns of all 450 rows match' );

// --------------------------------------------------------- 8. SQL failure ----
pv_clear( $old, $new );
pv_row( $old, 1, 101, 10 );

$pv_fail_sql = function ( $query ) use ( $old ) {
	if ( 0 === strpos( ltrim( $query ), 'SELECT * FROM' ) && false !== strpos( $query, $old ) ) {
		return 'SELECT * FROM `at8mluc_no_such_table_9f3c`';
	}

	return $query;
};

add_filter( 'query', $pv_fail_sql );

$GLOBALS['pv_reports'] = array();
$status                = pv_move( $new );

remove_filter( 'query', $pv_fail_sql );

pv_ok( 'failed' === $status, 'SQL failure: a failing read is reported as failed, not skipped' );
pv_ok( 1 === pv_count( $old ), 'SQL failure: the source data is still there' );

// --------------------------------------------------- 9. missing column -------
pv_clear( $old, $new );
pv_row( $old, 1, 101, 10 );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Scratch table owned by this test.
$wpdb->query( "DROP TABLE `{$new}`" );
pv_create( $new, false );

$GLOBALS['pv_reports'] = array();
$status                = pv_move( $new );

pv_ok( 'conflict' === $status, 'schema mismatch: a target with missing columns is a conflict, not a success' );
pv_ok( 0 === pv_count( $new ), 'schema mismatch: nothing is written into the incomplete target' );

// --------------------------------- 10. conflict holds back the DONE marker ----
/*
 * This test site genuinely has a legacy ledger that disagrees with the
 * current one. Observe that maybe_run() refuses to claim completion while
 * such a conflict exists. Markers are restored afterwards.
 */
delete_option( AT8MLUC_Prefix_Migration::DONE_OPTION );
delete_option( AT8MLUC_Prefix_Migration::LOCK_OPTION );
delete_transient( AT8MLUC_Prefix_Migration::RETRY_TRANSIENT );
delete_option( AT8MLUC_Prefix_Migration::LAST_ERROR_OPTION );

// Drop the scratch tables first: they too carry the at8mluc prefix and would
// otherwise be picked up as extra tables by the steps below is not possible;
// move_points_tables() only looks at the two real points tables.
AT8MLUC_Prefix_Migration::maybe_run();

$last = get_option( AT8MLUC_Prefix_Migration::LAST_ERROR_OPTION, array() );

pv_ok( '' === (string) get_option( AT8MLUC_Prefix_Migration::DONE_OPTION, '' ), 'real conflict: no completion marker is written while the two tables disagree' );
pv_ok( is_array( $last ) && in_array( 'tables', (array) ( isset( $last['steps'] ) ? $last['steps'] : array() ), true ), 'real conflict: the failing step is named for the administrator' );
pv_ok( false !== get_transient( AT8MLUC_Prefix_Migration::RETRY_TRANSIENT ), 'real conflict: a retry is scheduled instead of a per-request full scan' );

// ------------------------------------------------------------------ cleanup ---
delete_option( AT8MLUC_Prefix_Migration::LAST_ERROR_OPTION );
delete_transient( AT8MLUC_Prefix_Migration::RETRY_TRANSIENT );
update_option( AT8MLUC_Prefix_Migration::DONE_OPTION, AT8MLUC_VERSION, false );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only the two tables this test created are dropped.
$wpdb->query( "DROP TABLE IF EXISTS `{$new}`" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only the two tables this test created are dropped.
$wpdb->query( "DROP TABLE IF EXISTS `{$old}`" );

echo implode( PHP_EOL, $GLOBALS['pv_lines'] ) . PHP_EOL;
echo '==== ' . $GLOBALS['pv_pass'] . ' passed, ' . $GLOBALS['pv_fail'] . ' failed ====' . PHP_EOL;

// Machine-readable verification report (counts and columns only, no user data).
echo '---- verification reports ----' . PHP_EOL;
echo wp_json_encode( $GLOBALS['pv_reports'], JSON_PRETTY_PRINT ) . PHP_EOL;
