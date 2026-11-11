<?php
/**
 * 2.7.2 — OAuth callback parameter type/length suite.
 *
 * Run with:
 *   php wp-cli.phar --path=wptest/wordpress eval-file _tools/oauth_param_test.php
 *
 * Every case installs a custom error handler so a PHP Warning / Notice is a
 * test failure rather than something that scrolls past unnoticed.
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['op_pass']     = 0;
$GLOBALS['op_fail']     = 0;
$GLOBALS['op_lines']    = array();
$GLOBALS['op_warnings'] = array();
$GLOBALS['op_rejected'] = array();

/**
 * Assert one condition.
 *
 * @param bool   $ok  Condition.
 * @param string $msg Message.
 */
function op_ok( $ok, $msg ) {
	if ( $ok ) {
		++$GLOBALS['op_pass'];
		$GLOBALS['op_lines'][] = 'PASS  ' . $msg;
	} else {
		++$GLOBALS['op_fail'];
		$GLOBALS['op_lines'][] = 'FAIL  ' . $msg;
	}
}

set_error_handler(
	function ( $errno, $errstr ) {
		$GLOBALS['op_warnings'][] = $errstr;

		return true;
	}
);

add_action(
	'at8mluc_oauth_malformed_input',
	function ( $name, $reason ) {
		$GLOBALS['op_rejected'][] = $name . ':' . $reason;
	},
	10,
	2
);

$param = new ReflectionMethod( 'AT8MLUC_OAuth', 'callback_param' );
$param->setAccessible( true );

$redirect = new ReflectionMethod( 'AT8MLUC_OAuth', 'requested_redirect' );
$redirect->setAccessible( true );

$names = new ReflectionMethod( 'AT8MLUC_OAuth', 'callback_user_names' );
$names->setAccessible( true );

$oauth = AT8MLUC_OAuth::get_instance();

/**
 * Feed one value through the callback parameter reader.
 *
 * @param mixed  $value Raw value.
 * @param string $name  Parameter name.
 * @return string
 */
function op_read( $param, $oauth, $value, $name ) {
	$_GET  = array( $name => $value );
	$_POST = array();

	return (string) $param->invoke( $oauth, $name );
}

// ------------------------------------------------------------ scalar strings --
op_ok( 'abc-123_XY' === op_read( $param, $oauth, 'abc-123_XY', 'code' ), 'normal string: accepted and sanitised' );
op_ok( '' === op_read( $param, $oauth, '', 'code' ), 'empty string: returns an empty string' );
op_ok( '42' === op_read( $param, $oauth, 42, 'code' ), 'integer scalar: accepted as a string' );
op_ok( '1.5' === op_read( $param, $oauth, 1.5, 'code' ), 'float scalar: accepted as a string' );
op_ok( 'spaced' === op_read( $param, $oauth, '  spaced  ', 'code' ), 'whitespace: sanitise_text_field trims and sanitises' );

// --------------------------------------------------------------- length caps --
op_ok( '' === op_read( $param, $oauth, str_repeat( 'a', 3000 ), 'code' ), 'over-long code (3000 bytes): rejected, not truncated' );
op_ok( '' === op_read( $param, $oauth, str_repeat( 'a', 65 ), 'state' ), 'over-long state (65 bytes): rejected at its own 64-byte cap' );
op_ok( str_repeat( 'a', 64 ) === op_read( $param, $oauth, str_repeat( 'a', 64 ), 'state' ), 'state at its exact cap: accepted' );
op_ok( '' === op_read( $param, $oauth, str_repeat( 'a', 2049 ), 'redirect_to' ), 'over-long redirect (2049 bytes): rejected' );
op_ok( '' === op_read( $param, $oauth, str_repeat( 'a', 129 ), 'error' ), 'over-long error (129 bytes): rejected at its own 128-byte cap' );
op_ok( in_array( 'code:length', $GLOBALS['op_rejected'], true ), 'length rejections are reported through the site action' );

// ------------------------------------------------------------- wrong types ----
op_ok( '' === op_read( $param, $oauth, array( 'x' ), 'code' ), 'array: rejected without a cast' );
op_ok( '' === op_read( $param, $oauth, array( 'a' => array( 'b' => 'c' ) ), 'code' ), 'nested array: rejected without a cast' );
op_ok( '' === op_read( $param, $oauth, new stdClass(), 'code' ), 'object: rejected without a cast' );
op_ok( '' === op_read( $param, $oauth, true, 'code' ), 'boolean: rejected (never becomes "1")' );
op_ok( '' === op_read( $param, $oauth, false, 'code' ), 'boolean false: rejected' );
op_ok( '' === op_read( $param, $oauth, null, 'code' ), 'null: treated as missing' );
op_ok( '' === op_read( $param, $oauth, INF, 'code' ), 'non-finite float: rejected' );
op_ok( in_array( 'code:type', $GLOBALS['op_rejected'], true ), 'type rejections are reported through the site action' );

// ----------------------------------------------- Apple form_post user object --
$_GET  = array();
$_POST = array(
	'user' => array(
		'firstName' => array( 'nested' ),
		'lastName'  => 'Smith',
	),
);

$got = $names->invoke( $oauth );
op_ok( array( '', 'Smith' ) === $got, 'Apple user object: a nested array in firstName is rejected while lastName survives' );

$_POST = array( 'user' => array( 'firstName' => str_repeat( 'x', 500 ), 'lastName' => new stdClass() ) );
$got   = $names->invoke( $oauth );
op_ok( array( '', '' ) === $got, 'Apple user object: over-long firstName and an object lastName are both rejected' );

// ------------------------------------------------------------ redirect_to -----
$_GET  = array( 'redirect_to' => array( 'https://evil.example/' ) );
$_POST = array();
op_ok( '' === (string) $redirect->invoke( $oauth ), 'redirect_to as an array: rejected, no warning' );

$_GET = array( 'redirect_to' => 'https://evil.example/steal' );
op_ok( '' === (string) $redirect->invoke( $oauth ), 'external redirect_to: rejected by wp_validate_redirect' );

$_GET = array( 'redirect_to' => home_url( '/account/' ) );
op_ok( home_url( '/account/' ) === (string) $redirect->invoke( $oauth ), 'same-site redirect_to: accepted' );

// ------------------------------------------------------------- GET / POST -----
$_GET  = array();
$_POST = array( 'code' => 'from-apple-form-post' );
op_ok( 'from-apple-form-post' === (string) $param->invoke( $oauth, 'code' ), 'Apple form_post: the value is read from POST when GET is empty' );

$_GET  = array( 'code' => 'from-get' );
$_POST = array( 'code' => array( 'unexpected' ) );
op_ok( 'from-get' === (string) $param->invoke( $oauth, 'code' ), 'GET wins over POST, matching the documented order' );

// ------------------------------------------------------------------ warnings --
restore_error_handler();

op_ok( array() === $GLOBALS['op_warnings'], 'no PHP Warning / Notice was raised by any input (' . count( $GLOBALS['op_warnings'] ) . ')' );

$_GET  = array();
$_POST = array();

echo implode( PHP_EOL, $GLOBALS['op_lines'] ) . PHP_EOL;
echo '==== ' . $GLOBALS['op_pass'] . ' passed, ' . $GLOBALS['op_fail'] . ' failed ====' . PHP_EOL;

if ( $GLOBALS['op_warnings'] ) {
	echo 'captured: ' . wp_json_encode( array_slice( $GLOBALS['op_warnings'], 0, 5 ) ) . PHP_EOL;
}
