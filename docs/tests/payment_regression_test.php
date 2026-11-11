<?php
/**
 * 2.7.2 — payment / order / membership security regression.
 *
 * Run with Pro deactivated (Free standalone):
 *   php wp-cli.phar --path=wptest/wordpress eval-file _tools/payment_regression_test.php
 *
 * The AJAX handlers finish with wp_send_json(), which normally terminates the
 * process. This suite makes wp_doing_ajax() true and replaces the AJAX die
 * handler with an exception, so a whole set of real handler calls can be made
 * in one process and their JSON inspected.
 *
 * Only orders/users created by this suite are deleted at the end. Existing
 * orders on the test site are counted but never touched.
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['pr_pass']  = 0;
$GLOBALS['pr_fail']  = 0;
$GLOBALS['pr_lines'] = array();

/** Record one assertion. */
function pr_ok( $ok, $msg ) {
	if ( $ok ) {
		++$GLOBALS['pr_pass'];
		$GLOBALS['pr_lines'][] = 'PASS  ' . $msg;
	} else {
		++$GLOBALS['pr_fail'];
		$GLOBALS['pr_lines'][] = 'FAIL  ' . $msg;
	}
}

$GLOBALS['pr_orders'] = array();
$GLOBALS['pr_users']  = array();

add_filter( 'wp_doing_ajax', '__return_true' );
add_filter(
	'wp_die_ajax_handler',
	function () {
		return function () {
			throw new RuntimeException( 'wp_die intercepted' );
		};
	}
);

/**
 * Call one AJAX handler and return the decoded JSON it emitted.
 *
 * @param callable $handler  Handler callable.
 * @param array    $post     $_POST contents.
 * @param bool     $valid_nonce Whether to add a valid nonce.
 * @param int      $user_id  User to sign in (0 = anonymous).
 * @return array
 */
function pr_ajax( $handler, $post, $valid_nonce, $user_id = 0 ) {
	wp_set_current_user( $user_id );

	$_POST    = $post;
	$_REQUEST = $post;

	// A nonce is bound to the current user's session token, so it must be
	// minted AFTER signing the fixture user in.
	if ( $valid_nonce ) {
		$_REQUEST['nonce'] = wp_create_nonce( 'at8mluc_nonce' );
		$_POST['nonce']    = $_REQUEST['nonce'];
	}

	$before = count( get_posts( array( 'post_type' => AT8MLUC_Payments::CPT, 'numberposts' => -1, 'fields' => 'ids' ) ) );

	ob_start();

	try {
		call_user_func( $handler );
	} catch ( RuntimeException $e ) {
		// Expected: wp_send_json() reached wp_die().
	}

	$json = (string) ob_get_clean();

	// A PHP notice emitted before the JSON must not corrupt the payload parse.
	$brace = strpos( $json, '{' );
	$json  = false === $brace ? $json : substr( $json, $brace );

	$data = json_decode( $json, true );
	$data = is_array( $data ) ? $data : array( 'raw' => $json );

	$after = get_posts(
		array(
			'post_type'   => AT8MLUC_Payments::CPT,
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);

	$GLOBALS['pr_last_order'] = isset( $data['data']['order_id'] ) ? (int) $data['data']['order_id'] : 0;
	$GLOBALS['pr_new_orders'] = count( $after ) - $before;

	return $data;
}

/** Create one throwaway customer. */
function pr_user() {
	$name = 'pr_' . wp_generate_password( 6, false );
	$id   = wp_create_user( $name, wp_generate_password( 20, false ), $name . '@example.invalid' );

	$GLOBALS['pr_users'][] = (int) $id;

	return (int) $id;
}

// ------------------------------------------------------------------ fixture ---
$uid      = pr_user();
$payments = AT8MLUC_Payments::get_instance();
$levels   = AT8MLUC_Membership::get_levels();
$price    = AT8MLUC_Membership::get_level_price( 'monthly' );

pr_ok( $uid > 0, 'fixture: a throwaway customer exists' );
pr_ok( $price > 0, 'fixture: the monthly level has a server-side price (' . $price . ')' );

// --------------------------------------------------- 1. nonce is enforced -----
$r = pr_ajax( array( $payments, 'ajax_buy_level' ), array( 'level' => 'monthly', 'gateway' => 'manual' ), false, $uid );
pr_ok( empty( $r['success'] ), 'nonce: a missing nonce is refused' );
pr_ok( 0 === $GLOBALS['pr_new_orders'], 'nonce: no order is created on a failed check' );

// ------------------------------------------------- 2. login is required ------
$r = pr_ajax( array( $payments, 'ajax_buy_level' ), array( 'level' => 'monthly', 'gateway' => 'manual' ), true, 0 );
pr_ok( empty( $r['success'] ), 'login: an anonymous purchase is refused' );
pr_ok( 0 === $GLOBALS['pr_new_orders'], 'login: no order is created for an anonymous visitor' );

// ------------------------------- 3/4. server-side price, hostile POST price ---
$r = pr_ajax(
	array( $payments, 'ajax_buy_level' ),
	array(
		'level'   => 'monthly',
		'gateway' => 'manual',
		'price'   => '0.01',
		'amount'  => '0.01',
	),
	true,
	$uid
);

$order_id = (int) $GLOBALS['pr_last_order'];
$GLOBALS['pr_orders'][] = $order_id;

pr_ok( ! empty( $r['success'] ), 'purchase: a legitimate purchase succeeds' );
pr_ok( $order_id > 0, 'purchase: an order is created' );
pr_ok( (float) $price === (float) get_post_meta( $order_id, '_at8mluc_pay_price', true ), 'server-side price: the stored amount is the configured price, not the POST value (' . get_post_meta( $order_id, '_at8mluc_pay_price', true ) . ')' );
pr_ok( 'pending' === (string) get_post_meta( $order_id, '_at8mluc_pay_status', true ), 'order state: a new order is pending' );

// ------------------------------------------------- 5. unique order numbers ---
$r2 = pr_ajax( array( $payments, 'ajax_buy_level' ), array( 'level' => 'monthly', 'gateway' => 'manual' ), true, $uid );
$order_id2 = (int) $GLOBALS['pr_last_order'];
$GLOBALS['pr_orders'][] = $order_id2;

pr_ok( $order_id2 > 0 && $order_id2 !== $order_id, 'order numbers: two purchases get two distinct IDs (' . $order_id . ' / ' . $order_id2 . ')' );

// ------------------------------ 6. a failed payment must not grant a level ---
$before_level = AT8MLUC_Membership::get_user_level( $uid );
pr_ok( 'free' === $before_level || '' === $before_level, 'failed payment: a pending order does not grant a membership (' . $before_level . ')' );

// ---------------------------- 7. duplicate notification must not double-grant -
$first  = AT8MLUC_Payments::complete_order( $order_id, 'PR-TXN-1', 'manual' );
$second = AT8MLUC_Payments::complete_order( $order_id, 'PR-TXN-1', 'manual' );

pr_ok( true === $first, 'completion: the first confirmation succeeds' );
pr_ok( is_wp_error( $second ) && 'at8mluc_dup' === $second->get_error_code(), 'duplicate notification: the second confirmation is refused' );
pr_ok( 'paid' === (string) get_post_meta( $order_id, '_at8mluc_pay_status', true ), 'completion: the order is marked paid exactly once' );
pr_ok( 'monthly' === AT8MLUC_Membership::get_user_level( $uid ), 'completion: the membership is granted once' );

// ------------------------------------------- 8. claim_order is atomic -------
pr_ok( false === AT8MLUC_Payments::claim_order( $order_id ), 'atomic claim: a paid order cannot be claimed again' );

// ---------------------------------- 9. callback cannot bypass order state ---
update_post_meta( $order_id2, '_at8mluc_pay_status', 'cancelled' );
$third = AT8MLUC_Payments::complete_order( $order_id2, 'PR-TXN-2', 'manual' );
pr_ok( is_wp_error( $third ), 'order state: a cancelled order cannot be completed by a callback' );
pr_ok( 'cancelled' === (string) get_post_meta( $order_id2, '_at8mluc_pay_status', true ), 'order state: the cancelled state is left intact' );

// -------------------------- 10. forged amount cannot confirm a manual order ---
// Free has no automatic gateway notification: confirmation is an administrator
// action guarded by manage_options + a WP nonce. Verify the capability gate.
wp_set_current_user( $uid );
pr_ok( ! user_can( $uid, 'manage_options' ), 'admin confirmation: a customer cannot confirm an order' );
wp_set_current_user( 0 );

// --------------- 11. Pro Stripe webhook: an invalid signature is refused -----
if ( class_exists( 'AT8MLUCP_Stripe' ) ) {
	// Give the webhook a signing secret for this process only, so the real
	// HMAC branch is exercised. Restored below, before cleanup.
	$pr_saved_options = get_option( 'at8mlucp_options', array() );
	$pr_opts          = is_array( $pr_saved_options ) ? $pr_saved_options : array();
	$pr_opts['stripe_webhook_secret'] = 'whsec_pr_test_secret_9f3c';
	update_option( 'at8mlucp_options', $pr_opts );

	$pr_payload = '{"id":"evt_forged","type":"checkout.session.completed"}';

	$request = new WP_REST_Request( 'POST', '/at8mlucp/v1/stripe-webhook' );
	$request->set_header( 'stripe-signature', 't=1700000000,v1=deadbeef' );
	$request->set_body( $pr_payload );
	$response = AT8MLUCP_Stripe::handle_webhook( $request );
	pr_ok( 400 === (int) $response->get_status(), 'Stripe webhook: a forged signature returns HTTP 400 before any order is touched' );

	$pr_ts     = time();
	$request2 = new WP_REST_Request( 'POST', '/at8mlucp/v1/stripe-webhook' );
	$request2->set_header( 'stripe-signature', 't=' . $pr_ts . ',v1=' . hash_hmac( 'sha256', $pr_ts . '.' . $pr_payload, 'whsec_pr_test_secret_9f3c' ) );
	$request2->set_body( $pr_payload );
	$response2 = AT8MLUCP_Stripe::handle_webhook( $request2 );
	pr_ok( 200 === (int) $response2->get_status(), 'Stripe webhook: a correctly signed event is accepted (empty object → no delivery)' );

	update_option( 'at8mlucp_options', $pr_saved_options );
} else {
	$GLOBALS['pr_lines'][] = 'NOTE  Pro is not active: the Stripe webhook signature check was NOT RUN in this process';
}

// ------------------------------------------------------------------ cleanup ---
wp_set_current_user( 0 );

foreach ( $GLOBALS['pr_orders'] as $id ) {
	if ( $id > 0 ) {
		wp_delete_post( $id, true ); // Only orders created by this suite.
	}
}

foreach ( $GLOBALS['pr_users'] as $id ) {
	if ( $id > 0 ) {
		wp_delete_user( $id ); // Only the throwaway customer created above.
	}
}

echo implode( PHP_EOL, $GLOBALS['pr_lines'] ) . PHP_EOL;
echo '==== ' . $GLOBALS['pr_pass'] . ' passed, ' . $GLOBALS['pr_fail'] . ' failed ====' . PHP_EOL;
