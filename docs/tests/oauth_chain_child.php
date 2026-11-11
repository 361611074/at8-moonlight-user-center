<?php
/**
 * Child process for the authentication-chain suite (2.7.2).
 *
 * The handlers under test end in `exit` (a redirect on success, a JSON
 * response on failure), so they cannot be observed from the same process that
 * calls them. This script registers a shutdown writer, performs one scenario,
 * and lets the handler terminate the process normally; the parent reads the
 * JSON afterwards.
 *
 * Scenario name: PM_SCENARIO. Result path: PM_OUT.
 */

$GLOBALS['pm_log'] = array(
	'scenario'          => (string) getenv( 'PM_SCENARIO' ),
	'wp_login'          => 0,
	'login_name'        => '',
	'cookies'           => 0,
	'cookie_expiry'     => 0,
	'login_failed'      => 0,
	'auth_started'      => 0,
	'authenticate_seen' => '',
	'denied_reason'     => '',
	'fail_code'         => '',
	'fail_msg'          => '',
	'oath_login'        => 0,
	'payload'           => '',
	'user_id'           => 0,
	'registered'        => 0,
);

$pm_out = (string) getenv( 'PM_OUT' );

/**
 * Options this scenario changes and must put back before the process ends.
 *
 * The handlers terminate with `exit`, so the restore has to happen in a
 * shutdown callback rather than after the call.
 */
$GLOBALS['pm_restore'] = array();

register_shutdown_function(
	function () use ( $pm_out ) {
		foreach ( $GLOBALS['pm_restore'] as $name => $value ) {
			update_option( $name, $value );
		}

		file_put_contents( $pm_out, json_encode( $GLOBALS['pm_log'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone test harness, not plugin code.
	}
);

add_action(
	'user_register',
	function ( $user_id ) {
		$GLOBALS['pm_log']['registered'] = (int) $user_id;
	}
);

add_action(
	'wp_login',
	function ( $user_login ) {
		$GLOBALS['pm_log']['wp_login']++;
		$GLOBALS['pm_log']['login_name'] = (string) $user_login;
	},
	10,
	2
);

// Core action fired by wp_signon() before authentication; the adapter fires it too.
add_action(
	'wp_authenticate',
	function () {
		$GLOBALS['pm_log']['auth_started']++;
	}
);

// Core action fired on refusal, so rate-limit / audit plugins can count it.
add_action(
	'wp_login_failed',
	function () {
		$GLOBALS['pm_log']['login_failed']++;
	}
);

add_action(
	'set_auth_cookie',
	function ( $cookie, $expire, $expiration ) {
		$GLOBALS['pm_log']['cookies']++;
		// Core passes an absolute timestamp; store the lifetime in seconds.
		$GLOBALS['pm_log']['cookie_expiry'] = max( 0, (int) $expiration - time() );
	},
	10,
	3
);

add_action(
	'at8mluc_oauth_login',
	function () {
		$GLOBALS['pm_log']['oath_login']++;
	}
);

add_action(
	'at8mluc_oauth_login_denied',
	function ( $reason ) {
		$GLOBALS['pm_log']['denied_reason'] = (string) $reason;
	}
);

add_action(
	'at8mluc_oauth_fail',
	function ( $code, $message ) {
		$GLOBALS['pm_log']['fail_code'] = (string) $code;
		$GLOBALS['pm_log']['fail_msg']  = (string) $message;
	},
	10,
	2
);

/**
 * Create a throwaway user and return its ID.
 *
 * @return int
 */
function pm_child_user() {
	$name = 'pmc_' . wp_generate_password( 6, false );
	$pass = wp_generate_password( 20, false );
	$id   = wp_create_user( $name, $pass, $name . '@example.invalid' );

	if ( is_wp_error( $id ) ) {
		$GLOBALS['pm_log']['fail_code'] = 'setup';
		$GLOBALS['pm_log']['fail_msg']  = $id->get_error_message();
		exit( 1 );
	}

	$GLOBALS['pm_log']['login_name'] = $name;
	update_user_meta( (int) $id, 'pm_child_pass', $pass );

	return (int) $id;
}

/**
 * Call the private OAuth sign-in routine the way the callback would.
 *
 * @param int    $user_id  User to sign in.
 * @param bool   $remember Whether to ask for a long-lived session.
 * @param string $redirect Same-site return URL.
 * @return void
 */
function pm_child_oauth_login( $user_id, $remember = false, $redirect = '' ) {
	$method = new ReflectionMethod( 'AT8MLUC_OAuth', 'do_login' );
	$method->setAccessible( true );

	$oauth = AT8MLUC_OAuth::get_instance();

	$method->invoke(
		$oauth,
		$user_id,
		array(
			'provider'    => 'github',
			'provider_id' => 'pm-fixture',
			'email'       => '',
			'name'        => 'PM fixture',
			'avatar'      => '',
		),
		$redirect,
		$remember
	);
}

$scenario = (string) getenv( 'PM_SCENARIO' );

if ( 'oauth_login' === $scenario ) {
	$uid = pm_child_user();
	$GLOBALS['pm_log']['user_id'] = $uid;
	pm_child_oauth_login( $uid );
}

if ( 'oauth_veto' === $scenario ) {
	$uid = pm_child_user();
	$GLOBALS['pm_log']['user_id'] = $uid;

	add_filter(
		'at8mluc_oauth_authenticate_user',
		function () {
			return new WP_Error( 'pm_blocked', 'blocked by the fixture' );
		}
	);

	pm_child_oauth_login( $uid );
}

// A plain security plugin on the native `authenticate` filter refuses.
if ( 'oauth_native_veto' === $scenario ) {
	$uid = pm_child_user();
	$GLOBALS['pm_log']['user_id'] = $uid;

	add_filter(
		'authenticate',
		function ( $user ) {
			return new WP_Error( 'pm_native_blocked', 'native filter refused' );
		},
		900,
		3
	);

	pm_child_oauth_login( $uid );
}

// A ban / spam plugin on the account-level core filter refuses.
if ( 'oauth_user_policy_veto' === $scenario ) {
	$uid = pm_child_user();
	$GLOBALS['pm_log']['user_id'] = $uid;

	add_filter(
		'wp_authenticate_user',
		function () {
			return new WP_Error( 'pm_user_policy', 'account banned' );
		},
		900,
		2
	);

	pm_child_oauth_login( $uid );
}

// The site's own "disabled account" rule refuses.
if ( 'oauth_inactive' === $scenario ) {
	$uid = pm_child_user();
	$GLOBALS['pm_log']['user_id'] = $uid;

	add_filter(
		'at8mluc_oauth_user_is_active',
		function () {
			return false;
		}
	);

	pm_child_oauth_login( $uid );
}

// A filter must not silently swap the authenticated identity.
if ( 'oauth_identity_swap' === $scenario ) {
	$uid    = pm_child_user();
	$other  = pm_child_user();
	$GLOBALS['pm_log']['user_id'] = $uid;

	add_filter(
		'authenticate',
		function () use ( $other ) {
			return get_userdata( $other );
		},
		900,
		3
	);

	pm_child_oauth_login( $uid );
}

// Record what the native filter chain receives: it must be a resolved WP_User,
// never null (null would make core try to validate a password we do not have).
if ( 'oauth_native_sees_user' === $scenario ) {
	$uid = pm_child_user();
	$GLOBALS['pm_log']['user_id'] = $uid;

	add_filter(
		'authenticate',
		function ( $user ) {
			$GLOBALS['pm_log']['authenticate_seen'] = ( $user instanceof WP_User ) ? 'WP_User' : gettype( $user );

			return $user;
		},
		900,
		3
	);

	pm_child_oauth_login( $uid );
}

// Linking a provider while already signed in as the same user must not set a
// second cookie or fire a second wp_login.
if ( 'oauth_already_signed_in' === $scenario ) {
	$uid = pm_child_user();
	$GLOBALS['pm_log']['user_id'] = $uid;

	wp_set_current_user( $uid );

	pm_child_oauth_login( $uid );
}

// "Remember me" is opt-in: without the flag the session stays short.
if ( 'oauth_remember' === $scenario ) {
	$uid = pm_child_user();
	$GLOBALS['pm_log']['user_id'] = $uid;
	pm_child_oauth_login( $uid, true );
}

if ( 'form_login' === $scenario ) {
	$uid  = pm_child_user();
	$pass = (string) get_user_meta( $uid, 'pm_child_pass', true );
	$name = (string) $GLOBALS['pm_log']['login_name'];

	$GLOBALS['pm_log']['user_id'] = $uid;

	$_POST = array(
		'user_login' => $name,
		'password'   => $pass,
		'remember'   => '1',
	);
	$_REQUEST = array_merge(
		$_REQUEST,
		array(
			AT8MLUC_Security::NONCE_FIELD => wp_create_nonce( AT8MLUC_Login::NONCE_ACTION ),
		)
	);

	AT8MLUC_Login::get_instance()->handle_login();
}

if ( 'weak_login' === $scenario ) {
	// Wrong password: the handler must not set a cookie or fire wp_login.
	$uid = pm_child_user();

	$_POST = array(
		'user_login' => (string) $GLOBALS['pm_log']['login_name'],
		'password'   => 'definitely-not-the-password',
		'remember'   => '1',
	);
	$_REQUEST = array_merge(
		$_REQUEST,
		array(
			AT8MLUC_Security::NONCE_FIELD => wp_create_nonce( AT8MLUC_Login::NONCE_ACTION ),
		)
	);

	$GLOBALS['pm_log']['user_id'] = $uid;

	AT8MLUC_Login::get_instance()->handle_login();
}

if ( 'register' === $scenario ) {
	$name = 'pmr_' . wp_generate_password( 6, false );
	$pass = wp_generate_password( 20, false );
	$mail = $name . '@example.invalid';

	$GLOBALS['pm_restore']['users_can_register'] = get_option( 'users_can_register' );
	$GLOBALS['pm_restore']['at8mluc_options']    = get_option( 'at8mluc_options' );

	// Repeated runs of this suite share one IP; disable only the throttle in
	// this throwaway process so the assertion is about the auth chain.
	add_filter(
		'at8mluc_rate_limit',
		function () {
			return 0;
		}
	);

	update_option( 'users_can_register', 1 );
	update_option( 'at8mluc_options', array_merge( (array) get_option( 'at8mluc_options', array() ), array( 'enable_registration' => 1, 'auto_login' => 1 ) ) );

	// The form requires a token minted at page render time, and a minimum time
	// between render and submit.
	$token = wp_generate_password( 20, false );
	set_transient( AT8MLUC_Register::TOKEN_PREFIX . md5( $token ), time() - ( AT8MLUC_Register::MIN_FILL_SECONDS + 5 ), 600 );

	$_POST = array(
		'user_login' => $name,
		'email'      => $mail,
		'password'   => $pass,
		'at8mluc_tk' => $token,
	);

	$_REQUEST = array_merge(
		$_REQUEST,
		array(
			AT8MLUC_Security::NONCE_FIELD => wp_create_nonce( AT8MLUC_Register::NONCE_ACTION ),
		)
	);

	$created = get_user_by( 'login', $name );

	if ( $created ) {
		$GLOBALS['pm_log']['user_id'] = (int) $created->ID;
	}

	AT8MLUC_Register::get_instance()->handle_ajax();
}

echo "child finished without exiting\n";
