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

// ---- 2.7.3: binding order ----
$g = a_load( 'bind_ok' );

if ( null === $g ) {
	a_ok( false, 'bind ok: the scenario produced a result' );
} else {
	a_ok( '' === $g['fail_code'], 'bind ok: a normal sign-in that links a provider succeeds (' . $g['fail_code'] . ')' );
	a_ok( 1 === $g['wp_login'] && 1 === $g['cookies'], 'bind ok: the session is created normally' );
	a_ok( $g['user_id'] === $g['probe_result'], 'bind ok: the binding is written to the signed-in account (' . $g['probe_result'] . ')' );
	a_ok( 'pm-bind-fixture1' === $g['caller_meta'], 'bind ok: the stored identity is the provider id (' . $g['caller_meta'] . ')' );
}

$t = a_load( 'bind_taken' );

if ( null === $t ) {
	a_ok( false, 'bind taken: the scenario produced a result' );
} else {
	a_ok( 'bind_taken' === $t['fail_code'], 'bind taken: a provider linked to another account is refused (' . $t['fail_code'] . ')' );
	a_ok( 0 === $t['cookies'] && 0 === $t['wp_login'], 'bind taken: no session and no wp_login on refusal' );
	a_ok( $t['probe_result'] === $t['user_id'] || $t['probe_result'] > 0, 'bind taken: the other binding still exists (' . $t['probe_result'] . ')' );
	a_ok( '' === $t['caller_meta'], 'bind taken: nothing was written to the current account' );
	a_ok( $t['probe_result'] > 0 && $t['probe_result'] !== $t['user_id'], 'bind taken: the third-party identity was not moved' );
}

$cf = a_load( 'bind_conflict' );

if ( null === $cf ) {
	a_ok( false, 'bind conflict: the scenario produced a result' );
} else {
	a_ok( 'bind_conflict' === $cf['fail_code'], 'bind conflict: a second identity of the same provider is refused (' . $cf['fail_code'] . ')' );
	a_ok( 0 === $cf['cookies'], 'bind conflict: no cookie is set on refusal' );
	a_ok( 'pm-old-identity' === $cf['caller_meta'], 'bind conflict: the existing binding is untouched (' . $cf['caller_meta'] . ')' );
	a_ok( 0 === $cf['probe_result'], 'bind conflict: the new identity was not linked to anyone' );
}

$av = a_load( 'bind_after_veto' );

if ( null === $av ) {
	a_ok( false, 'bind after veto: the scenario produced a result' );
} else {
	a_ok( 'blocked' === $av['fail_code'], 'bind after veto: the refused sign-in reports blocked (' . $av['fail_code'] . ')' );
	a_ok( 0 === $av['cookies'] && 0 === $av['wp_login'], 'bind after veto: no cookie and no wp_login' );
	a_ok( 0 === $av['probe_result'], 'bind after veto: the pending binding was never written' );
	a_ok( '' === $av['caller_meta'], 'bind after veto: the account stays unlinked' );
}

$r1 = a_load( 'bind_retry_register_step1' );
$r2 = a_load( 'bind_retry_register_step2' );

if ( null === $r1 || null === $r2 ) {
	a_ok( false, 'register retry: both steps produced results (' . ( null === $r1 ? 'step1 missing' : 'ok' ) . '/' . ( null === $r2 ? 'step2 missing' : 'ok' ) . ')' );
} else {
	a_ok( 'blocked' === $r1['fail_code'], 'register retry step 1: the refused sign-up reports blocked (' . $r1['fail_code'] . ')' );
	a_ok( $r1['registered'] > 0, 'register retry step 1: the account itself is created and kept (' . $r1['registered'] . ')' );
	a_ok( '' === $r1['registered_wechat_meta'], 'register retry step 1: the kept account stays unlinked' );
	a_ok( 0 === $r1['cookies'], 'register retry step 1: no session is left behind' );

	a_ok( '' === $r2['fail_code'], 'register retry step 2: the returning visitor signs in (' . $r2['fail_code'] . ')' );
	a_ok( 0 === $r2['registered'], 'register retry step 2: no duplicate account is created' );
	a_ok( $r2['probe_result'] === $r1['registered'], 'register retry step 2: the kept account is reused and linked (' . $r2['probe_result'] . ' vs ' . $r1['registered'] . ')' );
	a_ok( 1 === $r2['oath_login'], 'register retry step 2: the plugin login action fires' );
}

$uv = a_load( 'email_unverified_no_hijack' );

if ( null === $uv ) {
	a_ok( false, 'unverified e-mail: the scenario produced a result' );
} else {
	a_ok( 'register_fail' === $uv['fail_code'], 'unverified e-mail: an unverified address cannot claim the existing account (' . $uv['fail_code'] . ')' );
	a_ok( 0 === $uv['probe_result'], 'unverified e-mail: the provider identity is linked to nobody' );
	a_ok( '' === $uv['caller_meta'], 'unverified e-mail: the existing account stays unlinked' );
	a_ok( 0 === $uv['cookies'], 'unverified e-mail: no session is left behind' );
}

$sc = a_load( 'state_consume' );
$sr = a_load( 'state_replay' );

if ( null === $sc || null === $sr ) {
	a_ok( false, 'state replay: both callbacks produced results (' . ( null === $sc ? 'consume missing' : 'ok' ) . '/' . ( null === $sr ? 'replay missing' : 'ok' ) . ')' );
} else {
	a_ok( 'token' === $sc['fail_code'], 'state replay: the first callback consumes the state and fails at the token step (' . $sc['fail_code'] . ')' );
	a_ok( 'state' === $sr['fail_code'], 'state replay: the replayed callback is refused at the state check (' . $sr['fail_code'] . ')' );
	a_ok( 0 === $sr['cookies'], 'state replay: the replay leaves no session' );
}

echo "==== $pass passed, $fail failed ====\n";

exit( $fail > 0 ? 1 : 0 );
