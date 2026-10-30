<?php
/**
 * Concurrency worker — one process out of many, driven by environment variables.
 *
 * CONC_ACTION  adjust | adjust_deduct | grant
 * CONC_UID     target user id
 * CONC_AMOUNT  points amount for adjust
 * CONC_ORDER   order id for grant
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
		$rows  = AT8_MLUC_Points::ledger( $uid, 500 );
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
		$result['balance'] = AT8_MLUC_Points::balance( $uid );
	} elseif ( 'grant' === $action ) {
		AT8_MLUC_Points::get_instance()->grant_for_order( $order, $uid, 'monthly' );
		$result['ok']      = true;
		$result['balance'] = AT8_MLUC_Points::balance( $uid );
	} else {
		$signed = 'adjust_deduct' === $action ? -abs( $amount ) : $amount;
		$r      = AT8_MLUC_Points::adjust( $uid, $signed, 'concurrency ' . $signed, 'admin' );

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
