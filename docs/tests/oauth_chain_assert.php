<?php
/**
 * Check the JSON left behind by oauth_chain_child.php.
 *
 * Usage: php oauth_chain_assert.php <result-dir>
 */

$dir = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';

$pass = 0;
$fail = 0;

/**
 * Record one assertion.
 *
 * @param bool   $condition Assertion.
 * @param string $label     Description.
 * @return void
 */
function a_ok( $condition, $label ) {
	global $pass, $fail;

	if ( $condition ) {
		$pass++;
		echo "PASS  $label\n";
	} else {
		$fail++;
		echo "FAIL  $label\n";
	}
}

/**
 * Load one scenario result.
 *
 * @param string $scenario Scenario name.
 * @return array|null
 */
function a_load( $scenario ) {
	global $dir;

	$file = $dir . '/auth_' . $scenario . '.json';

	if ( ! is_readable( $file ) ) {
		return null;
	}

	$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Standalone test harness.

	return is_array( $data ) ? $data : null;
}

$a = a_load( 'oauth_login' );

if ( null === $a ) {
	a_ok( false, 'oauth login: the scenario produced a result' );
} else {
	a_ok( 1 === $a['wp_login'], 'oauth login: the standard wp_login action fires once (' . $a['wp_login'] . ')' );
	a_ok( 1 === $a['cookies'], 'oauth login: the auth cookie is set exactly once (' . $a['cookies'] . ')' );
	a_ok( 1 === $a['oath_login'], 'oauth login: the plugin action still fires (' . $a['oath_login'] . ')' );
	a_ok( 1 === $a['auth_started'], 'oauth login: the core wp_authenticate event fires (' . $a['auth_started'] . ')' );
	a_ok( 0 === $a['login_failed'], 'oauth login: no failure event is fired on success' );
	a_ok( $a['cookie_expiry'] > 0 && $a['cookie_expiry'] <= 172800, 'oauth login: the session is short-lived by default (' . $a['cookie_expiry'] . 's)' );
}

$b = a_load( 'oauth_veto' );

if ( null === $b ) {
	a_ok( false, 'oauth veto: the scenario produced a result' );
} else {
	a_ok( 'blocked' === $b['fail_code'], 'oauth veto: a refused identity is reported as blocked (' . $b['fail_code'] . ')' );
	a_ok( 0 === $b['cookies'], 'oauth veto: no auth cookie is set when a plugin refuses (' . $b['cookies'] . ')' );
	a_ok( 0 === $b['wp_login'], 'oauth veto: wp_login does not fire when a plugin refuses (' . $b['wp_login'] . ')' );
	a_ok( 0 === $b['oath_login'], 'oauth veto: the plugin action does not fire either (' . $b['oath_login'] . ')' );
}

$c = a_load( 'form_login' );

if ( null === $c ) {
	a_ok( false, 'form login: the scenario produced a result' );
} else {
	a_ok( 1 === $c['wp_login'], 'form login: wp_login fires once (' . $c['wp_login'] . ')' );
	a_ok( 1 === $c['cookies'], 'form login: the auth cookie is set once, not twice (' . $c['cookies'] . ')' );
}

$d = a_load( 'weak_login' );

if ( null === $d ) {
	a_ok( false, 'failed login: the scenario produced a result' );
} else {
	a_ok( 0 === $d['cookies'], 'failed login: a wrong password sets no cookie (' . $d['cookies'] . ')' );
	a_ok( 0 === $d['wp_login'], 'failed login: a wrong password fires no wp_login (' . $d['wp_login'] . ')' );
}

$e = a_load( 'register' );

if ( null === $e ) {
	a_ok( false, 'registration: the scenario produced a result' );
} else {
	a_ok( $e['registered'] > 0, 'registration: the account is created through wp_create_user (' . $e['registered'] . ')' );
	a_ok( 1 === $e['wp_login'], 'registration: the automatic sign-in goes through wp_signon and fires wp_login (' . $e['wp_login'] . ')' );
	a_ok( 1 === $e['cookies'], 'registration: the auth cookie is set once (' . $e['cookies'] . ')' );
}

// ---- 2.7.2: a real security plugin on native core filters must be able to refuse.
$refusals = array(
	'oauth_native_veto'      => 'authenticate',
	'oauth_user_policy_veto' => 'user_policy',
	'oauth_inactive'         => 'inactive',
	'oauth_identity_swap'    => 'identity_changed',
);

foreach ( $refusals as $name => $reason ) {
	$r = a_load( $name );

	if ( null === $r ) {
		a_ok( false, "$name: the scenario produced a result" );
		continue;
	}

	a_ok( 'blocked' === $r['fail_code'], "$name: the visitor is refused (" . $r['fail_code'] . ')' );
	a_ok( 0 === $r['cookies'], "$name: no auth cookie is left behind (" . $r['cookies'] . ')' );
	a_ok( 0 === $r['wp_login'], "$name: wp_login never fires" );
	a_ok( 1 === $r['login_failed'], "$name: the core wp_login_failed event fires so limiters can count it" );
	a_ok( $reason === $r['denied_reason'], "$name: the refusal reason is recorded (" . $r['denied_reason'] . ')' );
}

$n = a_load( 'oauth_native_sees_user' );

if ( null === $n ) {
	a_ok( false, 'native filter bridge: the scenario produced a result' );
} else {
	a_ok( 'WP_User' === $n['authenticate_seen'], 'native filter bridge: the core chain receives a resolved WP_User, not null (' . $n['authenticate_seen'] . ')' );
	a_ok( 1 === $n['wp_login'] && 1 === $n['cookies'], 'native filter bridge: a normal social login still succeeds' );
}

$s = a_load( 'oauth_already_signed_in' );

if ( null === $s ) {
	a_ok( false, 'already signed in: the scenario produced a result' );
} else {
	a_ok( 0 === $s['cookies'], 'already signed in: linking a provider sets no second cookie' );
	a_ok( 0 === $s['wp_login'], 'already signed in: wp_login does not fire twice' );
	a_ok( 1 === $s['oath_login'], 'already signed in: the plugin still reports the link' );
}

$m = a_load( 'oauth_remember' );

if ( null === $m ) {
	a_ok( false, 'remember: the scenario produced a result' );
} else {
	a_ok( $m['cookie_expiry'] > 172800, 'remember: an explicit long-lived session lasts longer than the default (' . $m['cookie_expiry'] . 's)' );
}

echo "==== $pass passed, $fail failed ====\n";

exit( $fail > 0 ? 1 : 0 );
