<?php
/**
 * Concurrency worker — one process out of many, driven by environment variables.
 *
 * CONC_ACTION  adjust | adjust_keyed | adjust_deduct | grant | observe | hold_txn | kill_mid
 * CONC_UID     target user id
 * CONC_AMOUNT  points amount for adjust
 * CONC_ORDER   order id for grant
 * CONC_KEY     idempotency key (adjust_keyed / observe)
 * CONC_HOLD    seconds a hold_txn worker keeps its transaction open
 * CONC_END     rollback | commit (hold_txn; default rollback)
 * CONC_DELAY   seconds to wait before acting (to force overlap)
 * CONC_OUT     path of the JSON result file
 */

$action = getenv( 'CONC_ACTION' );
$uid    = (int) getenv( 'CONC_UID' );
$amount = (int) getenv( 'CONC_AMOUNT' );
$order  = (int) getenv( 'CONC_ORDER' );
$delay  = (float) getenv( 'CONC_DELAY' );
$out    = getenv( 'CONC_OUT' );

if ( $delay > 0 ) {
	usleep( (int) ( $delay * 1000000 ) );
}

$result = array( 'pid' => getmypid(), 'ok' => false, 'error' => '', 'balance' => null );

try {
	if ( 'observe' === $action ) {
		$key   = (string) getenv( 'CONC_KEY' );
		$rows  = AT8MLUC_Points::ledger( $uid, 500 );
		$sum   = 0;
		$keyed = 0;
		$last  = '';

		foreach ( $rows as $e ) {
			$sum += (int) $e['amount'];

			if ( '' !== $key && $key === ( $e['key'] ?? '' ) ) {
				$keyed++;
			}
		}

		if ( $rows ) {
			$last = (string) ( $rows[0]['reason'] ?? '' );
		}

		$result['ok']      = true;
		$result['rows']    = count( $rows );
		$result['sum']     = $sum;
		$result['keyed']   = $keyed;
		$result['last']    = $last;
		$result['balance'] = AT8MLUC_Points::balance( $uid );
	} elseif ( 'grant' === $action ) {
		AT8MLUC_Points::get_instance()->grant_for_order( $order, $uid, 'monthly' );
		$result['ok']      = true;
		$result['balance'] = AT8MLUC_Points::balance( $uid );
	} elseif ( 'hold_txn' === $action ) {
		/*
		 * A writer that stalls in the middle of its transaction: the account
		 * update and the ledger row are written but not committed. This is
		 * the stand-in for "A holds the lock longer than the TTL".
		 */
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}at8mluc_points_account SET balance = balance + %d, fence = fence + 1 WHERE user_id = %d",
				$amount,
				$uid
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}at8mluc_points_ledger (user_id, amount, reason, source, by_user, idem_key, fence, created_at) VALUES (%d, %d, 'held writer', 'admin', 0, %s, 0, %d)",
				$uid,
				$amount,
				'hold:' . getmypid(),
				time()
			)
		);

		if ( $out ) {
			file_put_contents( $out . '.ready', '1' );
		}

		usleep( (int) ( (float) getenv( 'CONC_HOLD' ) * 1000000 ) );

		$wpdb->query( 'commit' === getenv( 'CONC_END' ) ? 'COMMIT' : 'ROLLBACK' );

		$result['ok']      = true;
		$result['balance'] = AT8MLUC_Points::balance( $uid );
	} elseif ( 'kill_mid' === $action ) {
		/*
		 * A request that dies inside its transaction: the process exits
		 * without COMMIT, exactly like a killed PHP-FPM worker.
		 */
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}at8mluc_points_account SET balance = balance + %d, fence = fence + 1 WHERE user_id = %d",
				$amount,
				$uid
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}at8mluc_points_ledger (user_id, amount, reason, source, by_user, idem_key, fence, created_at) VALUES (%d, %d, 'killed writer', 'admin', 0, %s, 0, %d)",
				$uid,
				$amount,
				'kill:' . getmypid(),
				time()
			)
		);

		exit( 0 );
	} else {
		$signed = 'adjust_deduct' === $action ? -abs( $amount ) : $amount;
		$key    = (string) getenv( 'CONC_KEY' );
		$r      = AT8MLUC_Points::adjust( $uid, $signed, 'concurrency ' . $signed, 'admin', $key );

		if ( is_wp_error( $r ) ) {
			$result['error'] = $r->get_error_code() . ': ' . $r->get_error_message();
		} else {
			$result['ok']      = true;
			$result['balance'] = (int) $r;
		}
	}
} catch ( Throwable $e ) {
	$result['error'] = 'throwable: ' . $e->getMessage();
}

if ( $out ) {
	file_put_contents( $out, wp_json_encode( $result ) );
} else {
	echo wp_json_encode( $result );
}
