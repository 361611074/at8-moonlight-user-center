<?php
/**
 * 2.7.3 migration structure-verification tests.
 *
 * Every scenario runs against throwaway tables (at8mluctest_* /
 * at8_mluctest_*), never against the live points tables. Tables are built
 * with plain CREATE TABLE variants (no ALTER), so the suite is driver
 * agnostic. The migration report option is snapshotted and restored,
 * because move_points_table() publishes its results there.
 *
 * Run: wp eval-file migration_schema_test.php
 */

global $wpdb;

$GLOBALS['ms_pass']  = 0;
$GLOBALS['ms_fail']  = 0;
$GLOBALS['ms_lines'] = array();

/**
 * Record one assertion.
 *
 * @param bool   $ok  Condition.
 * @param string $msg Description.
 * @return void
 */
function ms_ok( $ok, $msg ) {
	if ( $ok ) {
		++$GLOBALS['ms_pass'];
		$GLOBALS['ms_lines'][] = 'PASS  ' . $msg;
	} else {
		++$GLOBALS['ms_fail'];
		$GLOBALS['ms_lines'][] = 'FAIL  ' . $msg;
	}
}

$old_table = $wpdb->prefix . 'at8_mluctest_ledger';
$new_table = $wpdb->prefix . 'at8mluctest_ledger';

// Snapshot the options move_points_table() writes to, so the real migration
// report survives this suite.
$GLOBALS['ms_backup_report'] = get_option( AT8MLUC_Prefix_Migration::REPORT_OPTION, array() );
$GLOBALS['ms_backup_error']  = get_option( AT8MLUC_Prefix_Migration::LAST_ERROR_OPTION, array() );

register_shutdown_function(
	function () {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'at8_mluctest_ledger`' ); // phpcs:ignore WordPress.DB -- Test-only throwaway table.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'at8mluctest_ledger`' ); // phpcs:ignore WordPress.DB -- Test-only throwaway table.
		update_option( AT8MLUC_Prefix_Migration::REPORT_OPTION, $GLOBALS['ms_backup_report'], false );
		update_option( AT8MLUC_Prefix_Migration::LAST_ERROR_OPTION, $GLOBALS['ms_backup_error'], false );

		echo implode( PHP_EOL, $GLOBALS['ms_lines'] ) . PHP_EOL;
		echo '==== ' . $GLOBALS['ms_pass'] . ' passed, ' . $GLOBALS['ms_fail'] . ' failed ====' . PHP_EOL;
	}
);

/**
 * Reflection helper for the private migration methods.
 *
 * @param string $method Method name.
 * @return ReflectionMethod
 */
function ms_method( $method ) {
	$r = new ReflectionMethod( 'AT8MLUC_Prefix_Migration', $method );
	$r->setAccessible( true );

	return $r;
}

/**
 * The canonical ledger column definitions, as a copyable array.
 *
 * @return array<string, string> name => full definition.
 */
function ms_columns() {
	return array(
		'id'         => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
		'user_id'    => 'user_id bigint(20) unsigned NOT NULL',
		'amount'     => 'amount int(11) NOT NULL DEFAULT 0',
		'reason'     => "reason varchar(191) NOT NULL DEFAULT ''",
		'source'     => "source varchar(32) NOT NULL DEFAULT ''",
		'by_user'    => 'by_user bigint(20) unsigned NOT NULL DEFAULT 0',
		'idem_key'   => 'idem_key varchar(150) DEFAULT NULL',
		'fence'      => 'fence bigint(20) unsigned NOT NULL DEFAULT 0',
		'created_at' => 'created_at bigint(20) unsigned NOT NULL DEFAULT 0',
	);
}

/**
 * Create one throwaway points-table clone.
 *
 * @param string     $table   Target table name.
 * @param array|null $columns Column definitions; null for the canonical set.
 * @param string     $keys    Index definitions after the primary key.
 * @param string     $pk      The primary key clause body.
 * @return void
 */
function ms_create( $table, $columns = null, $keys = "UNIQUE KEY idem_key (user_id,idem_key),\n  KEY user_id (user_id,id)", $pk = 'PRIMARY KEY  (id)' ) {
	global $wpdb;

	$columns = null === $columns ? ms_columns() : $columns;

	$charset = $wpdb->get_charset_collate();

	$defs = implode( ",\n  ", $columns );
	$sql  = "CREATE TABLE `{$table}` (\n  {$defs},\n  {$pk},\n  {$keys}\n) {$charset}";

	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB -- Test-only throwaway table.
	$wpdb->query( $sql ); // phpcs:ignore WordPress.DB -- Test-only throwaway table.
}

/**
 * Insert one row with explicit values.
 *
 * @param string $table  Table name.
 * @param int    $id     Primary key.
 * @param string $idem   Idempotency key.
 * @param int    $amount Amount.
 * @return void
 */
function ms_row( $table, $id, $idem, $amount = 10 ) {
	global $wpdb;

	$wpdb->query( // phpcs:ignore WordPress.DB -- Test-only throwaway table.
		$wpdb->prepare(
			"INSERT INTO `{$table}` (id, user_id, amount, reason, source, by_user, idem_key, fence, created_at) VALUES (%d, %d, %d, %s, %s, %d, %s, %d, %d)",
			$id,
			7,
			$amount,
			'ms-test',
			'ms-test',
			0,
			$idem,
			1,
			1700000000
		)
	);
}

/**
 * A sorted column-combination list, so comparisons ignore order.
 *
 * @param array $combinations Unique index column lists.
 * @return array
 */
function ms_sorted( $combinations ) {
	$out = array();

	foreach ( (array) $combinations as $combination ) {
		$combination = array_map( 'strtolower', (array) $combination );
		sort( $combination );
		$out[] = $combination;
	}

	return $out;
}

$migrate  = ms_method( 'move_points_table' );
$schema   = ms_method( 'table_schema' );
$problems = ms_method( 'structure_problems' );
$missing  = ms_method( 'missing_required_unique' );
$norm     = ms_method( 'normalize_type' );

$required = array( array( 'user_id', 'idem_key' ) );

// ------------------------------------------------------------ real schema ---
// The live ledger table is only read, never written, in this suite.
$live = $schema->invoke( null, $wpdb->prefix . 'at8mluc_points_ledger' );

ms_ok( ! is_wp_error( $live ), 'real table: SHOW FULL COLUMNS + SHOW INDEX both readable' );
ms_ok( ! is_wp_error( $live ) && array( 'id' ) === $live['primary_columns'], 'real table: the primary key is (id)' );
ms_ok( ! is_wp_error( $live ) && in_array( array( 'idem_key', 'user_id' ), ms_sorted( $live['unique'] ), true ), 'real table: the idempotency uniqueness is visible' );
ms_ok( ! is_wp_error( $live ) && 9 === count( $live['columns'] ), 'real table: all nine ledger columns are seen (' . ( is_wp_error( $live ) ? 'err' : count( $live['columns'] ) ) . ')' );

// ------------------------------------------------------------- primitives ---
ms_ok( 'bigintunsigned' === $norm->invoke( null, 'BIGINT(20) UNSIGNED' ), 'normalize_type: display width and case fold away' );
ms_ok( 'int' === $norm->invoke( null, ' INT(11) ' ), 'normalize_type: whitespace folds away' );

if ( ! is_wp_error( $live ) ) {
	ms_ok( array() === $problems->invoke( null, $live, $live, $required ), 'structure_problems: a table is self-consistent with itself' );
} else {
	ms_ok( false, 'structure_problems: a table is self-consistent with itself (no live schema)' );
}

ms_ok( array( array( 'idem_key', 'user_id' ) ) === $missing->invoke( null, array( 'unique' => array( array( 'user_id', 'id' ) ) ), $required ), 'missing_required_unique: a different unique combination does not count' );
ms_ok( array() === $missing->invoke( null, array( 'unique' => array( array( 'idem_key', 'user_id' ) ) ), $required ), 'missing_required_unique: column order does not matter' );

// -------------------------------------------------- end-to-end: identical ---
ms_create( $old_table );
ms_create( $new_table );

for ( $i = 1; $i <= 5; $i++ ) {
	ms_row( $old_table, $i, 'ms-idem-' . $i, 10 * $i );
}

$status = $migrate->invoke( null, $new_table, $required );

ms_ok( AT8MLUC_Prefix_Migration::OK === $status || AT8MLUC_Prefix_Migration::SKIPPED === $status, 'end-to-end: two identical structures allow the migration (' . $status . ')' );

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$new_table}`" ); // phpcs:ignore WordPress.DB -- Test-only throwaway table.
ms_ok( 5 === $left, 'end-to-end: all five rows arrived (' . $left . ')' );

$peak = memory_get_peak_usage( true ) / 1048576;
ms_ok( $peak < 128, 'end-to-end: the peak memory stays bounded (' . round( $peak, 1 ) . ' MB)' );

// A second run must not duplicate anything.
$status2 = $migrate->invoke( null, $new_table, $required );
$left2   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$new_table}`" ); // phpcs:ignore WordPress.DB -- Test-only throwaway table.
ms_ok( AT8MLUC_Prefix_Migration::OK !== $status2 && 5 === $left2, 'end-to-end: a repeat run adds nothing (' . $status2 . ', ' . $left2 . ' rows)' );

// ------------------------------------------------- end-to-end: 100 rows ---
ms_create( $old_table );
ms_create( $new_table );

for ( $i = 1; $i <= 100; $i++ ) {
	ms_row( $old_table, $i, 'ms-bulk-' . $i, $i );
}

$status = $migrate->invoke( null, $new_table, $required );
$left   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$new_table}`" ); // phpcs:ignore WordPress.DB -- Test-only throwaway table.

ms_ok( AT8MLUC_Prefix_Migration::OK === $status, 'bulk 100: the migration completes (' . $status . ')' );
ms_ok( 100 === $left, 'bulk 100: every row arrived (' . $left . ')' );

// One differing row must be caught even when the counts agree.
$wpdb->query( "UPDATE `{$new_table}` SET amount = 999 WHERE id = 50" ); // phpcs:ignore WordPress.DB -- Test-only throwaway table.
$status = $migrate->invoke( null, $new_table, $required );
ms_ok( AT8MLUC_Prefix_Migration::CONFLICT === $status || AT8MLUC_Prefix_Migration::FAILED === $status, 'bulk 100: equal counts but one different row is never success (' . $status . ')' );

// ------------------------------------------------- structure rejections ---
// 1. Target is missing a column.
$columns            = ms_columns();
$columns['extra']   = 'extra_note varchar(50)'; // An extra target column is fine.
unset( $columns['reason'] );                    // ...a missing one is not.

ms_create( $old_table );
ms_create( $new_table, $columns );

$status = $migrate->invoke( null, $new_table, $required );
ms_ok( AT8MLUC_Prefix_Migration::CONFLICT === $status || AT8MLUC_Prefix_Migration::FAILED === $status, 'structure: a missing target column refuses the migration (' . $status . ')' );

$report = get_option( AT8MLUC_Prefix_Migration::REPORT_OPTION, array() );
$last   = is_array( $report ) && $report ? end( $report ) : array();
ms_ok( ! empty( $last['samples'] ), 'structure: the refusal names the problem for the admin' );

// 2. Incompatible type on a shared column.
$columns           = ms_columns();
$columns['amount'] = 'amount varchar(32) NOT NULL';

ms_create( $old_table );
ms_create( $new_table, $columns );

$status = $migrate->invoke( null, $new_table, $required );
ms_ok( AT8MLUC_Prefix_Migration::CONFLICT === $status || AT8MLUC_Prefix_Migration::FAILED === $status, 'structure: an incompatible column type refuses the migration (' . $status . ')' );

// 3. NULL attribute changed.
$columns           = ms_columns();
$columns['reason'] = 'reason varchar(191) NULL DEFAULT NULL';

ms_create( $old_table );
ms_create( $new_table, $columns );

$status = $migrate->invoke( null, $new_table, $required );
ms_ok( AT8MLUC_Prefix_Migration::CONFLICT === $status || AT8MLUC_Prefix_Migration::FAILED === $status, 'structure: a changed NULL attribute refuses the migration (' . $status . ')' );

// 4. Primary key no longer auto-increment.
$columns       = ms_columns();
$columns['id'] = 'id bigint(20) unsigned NOT NULL';

ms_create( $old_table );
ms_create( $new_table, $columns );

$status = $migrate->invoke( null, $new_table, $required );
ms_ok( AT8MLUC_Prefix_Migration::CONFLICT === $status || AT8MLUC_Prefix_Migration::FAILED === $status, 'structure: losing auto-increment on the primary key refuses the migration (' . $status . ')' );

// 5. Primary key shape changed.
$columns       = ms_columns();
$columns['id'] = 'id bigint(20) unsigned NOT NULL DEFAULT 0';

ms_create( $old_table );
ms_create( $new_table, $columns, "UNIQUE KEY idem_key (user_id,idem_key),\n  KEY user_id (user_id,id)", 'PRIMARY KEY  (user_id)' );

$status = $migrate->invoke( null, $new_table, $required );
ms_ok( AT8MLUC_Prefix_Migration::CONFLICT === $status || AT8MLUC_Prefix_Migration::FAILED === $status, 'structure: a different primary key shape refuses the migration (' . $status . ')' );

// 6. The idempotency unique index is gone.
ms_create( $old_table );
ms_create( $new_table, null, "KEY user_id (user_id,id)" );

$status = $migrate->invoke( null, $new_table, $required );
ms_ok( AT8MLUC_Prefix_Migration::CONFLICT === $status || AT8MLUC_Prefix_Migration::FAILED === $status, 'structure: a missing idempotency unique index refuses the migration (' . $status . ')' );

// 7. The source itself is not self-consistent.
ms_create( $old_table, null, "KEY user_id (user_id,id)" );
ms_create( $new_table );

$status = $migrate->invoke( null, $new_table, $required );
ms_ok( AT8MLUC_Prefix_Migration::CONFLICT === $status, 'structure: a source without its own idempotency uniqueness refuses before any write (' . $status . ')' );

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$new_table}`" ); // phpcs:ignore WordPress.DB -- Test-only throwaway table.
ms_ok( 0 === $left, 'structure: the refused run wrote nothing (' . $left . ' rows in target)' );
