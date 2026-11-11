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
	a_ok( '' === $a['fail_code'], 'oauth login: no failure was reported' );
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

echo "==== $pass passed, $fail failed ====\n";

exit( $fail > 0 ? 1 : 0 );
