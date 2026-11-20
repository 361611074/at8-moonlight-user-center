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
	// 2.7.3 binding-order probes, filled in by the shutdown writer.
	'probe_key'         => '',
	'probe_val'         => '',
	'probe_result'      => 0,
	'caller_meta'       => '',
	'registered_wechat_meta' => '',
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

		// 2.7.3: record the binding state so the assertion process (which has
		// no database) can verify what was actually written.
		global $wpdb;

		$probe_key = (string) $GLOBALS['pm_log']['probe_key'];

		if ( '' !== $probe_key ) {
			$GLOBALS['pm_log']['probe_result'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
					$probe_key,
					(string) $GLOBALS['pm_log']['probe_val']
				)
			);
		}

		$caller = (int) $GLOBALS['pm_log']['user_id'];

		if ( ! $caller && (int) $GLOBALS['pm_log']['registered'] ) {
			$caller = (int) $GLOBALS['pm_log']['registered'];
		}

		if ( $caller ) {
			$GLOBALS['pm_log']['caller_meta'] = (string) get_user_meta( $caller, 'at8mluc_oauth_github_id', true );
		}

		if ( (int) $GLOBALS['pm_log']['registered'] ) {
			$GLOBALS['pm_log']['registered_wechat_meta'] = (string) get_user_meta( (int) $GLOBALS['pm_log']['registered'], 'at8mluc_oauth_wechat_id', true );
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

/*
 * Every scenario in this suite runs from one IP, so the per-IP buckets would
 * fill up across repeated runs and a later run would fail for a reason that
 * has nothing to do with the chain under test. No scenario asserts the
 * throttle itself, so it is switched off for the whole process.
 */
add_filter(
	'at8mluc_rate_limit',
	function () {
		return 0;
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
 * Remove any leftover binding for a fixture identity before a scenario runs.
 *
 * The scenarios that create an account through the OAuth register path leave
 * a user whose login is `ml<provider>_<hash>`, which the shell cleanup cannot
 * recognise by prefix. Without this scrub a previous run's account would be
 * found first, the scenario would take the "reuse an existing account" branch
 * and the assertions about account creation would silently invert.
 *
 * @param string $meta_key Provider binding meta key.
 * @param string $meta_val Fixture identity value.
 * @return void
 */
function pm_child_scrub_identity( $meta_key, $meta_val ) {
	global $wpdb;

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
			(string) $meta_key,
			(string) $meta_val
		)
	);

	foreach ( array_unique( array_map( 'intval', (array) $ids ) ) as $id ) {
		if ( $id > 0 && get_userdata( $id ) ) {
			wp_delete_user( $id );
		}
	}
}

/**
 * Call the private OAuth sign-in routine the way the callback would.
 *
 * @param int    $user_id  User to sign in.
 * @param bool   $remember Whether to ask for a long-lived session.
 * @param string $redirect Same-site return URL.
 * @return void
 */
function pm_child_oauth_login( $user_id, $remember = false, $redirect = '', $bind = '', $provider_id = 'pm-fixture' ) {
	$method = new ReflectionMethod( 'AT8MLUC_OAuth', 'do_login' );
	$method->setAccessible( true );

	$oauth = AT8MLUC_OAuth::get_instance();

	$method->invoke(
		$oauth,
		$user_id,
		pm_child_oauth_profile( 'github', $provider_id ),
		$redirect,
		$remember,
		$bind
	);
}

/**
 * A normalised provider profile for the fixtures.
 *
 * @param string $provider       Provider slug.
 * @param string $provider_id    Third-party identity.
 * @param string $email          Provider e-mail, empty when it has none.
 * @param bool   $email_verified Whether the provider verified the address.
 * @return array
 */
function pm_child_oauth_profile( $provider, $provider_id, $email = '', $email_verified = true ) {
	return array(
		'provider'       => $provider,
		'provider_id'    => $provider_id,
		'email'          => $email,
		'email_verified' => $email_verified,
		'name'           => 'PM fixture',
		'avatar'         => '',
	);
}

/**
 * Call the private login-or-register routine the way the callback would.
 *
 * @param string $provider Provider slug.
 * @param array  $profile  Normalised profile.
 * @return void
 */
function pm_child_oauth_register( $provider, $profile ) {
	$method = new ReflectionMethod( 'AT8MLUC_OAuth', 'login_or_register' );
	$method->setAccessible( true );

	$method->invoke( AT8MLUC_OAuth::get_instance(), $provider, $profile, '', false );
}

/**
 * Drive maybe_handle_callback() with a given state/code pair.
 *
 * @param string $state Pre-minted state token.
 * @param string $code  Authorization code to present.
 * @return void
 */
function pm_child_oauth_callback( $state, $code, $mint = true ) {
	if ( $mint ) {
		set_transient(
			'at8mluc_oauth_state_' . $state,
			array(
				'provider'  => 'github',
				'redirect'  => '',
				'remember'  => 0,
				'init_user' => 0,
			),
			600
		);
	}

	$_GET    = array(
		'at8mluc_oauth' => 'github',
		'code'          => $code,
		'state'         => $state,
	);
	$_COOKIE = array( 'at8mluc_oauth_state' => $state );

	AT8MLUC_OAuth::get_instance()->maybe_handle_callback();
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

// ---- 2.7.3: binding must happen after authentication passes, never before.

// A successful sign-in that also links the provider writes the binding.
if ( 'bind_ok' === $scenario ) {
	$uid = pm_child_user();
	$GLOBALS['pm_log']['user_id']  = $uid;
	$GLOBALS['pm_log']['probe_key'] = 'at8mluc_oauth_github_id';
	$GLOBALS['pm_log']['probe_val'] = 'pm-bind-fixture1';

	pm_child_oauth_login( $uid, false, '', 'at8mluc_oauth_github_id', 'pm-bind-fixture1' );
}

// The provider identity is already linked to another local account: refuse,
// keep the other binding, write nothing, set no cookie.
if ( 'bind_taken' === $scenario ) {
	$owner = pm_child_user();
	$uid   = pm_child_user();
	update_user_meta( $owner, 'at8mluc_oauth_github_id', 'pm-bind-fixture2' );
	$GLOBALS['pm_log']['user_id']   = $uid;
	$GLOBALS['pm_log']['probe_key'] = 'at8mluc_oauth_github_id';
	$GLOBALS['pm_log']['probe_val'] = 'pm-bind-fixture2';
	wp_set_current_user( $uid );

	pm_child_oauth_login( $uid, false, '', 'at8mluc_oauth_github_id', 'pm-bind-fixture2' );
}

// The local account already links a different identity of the same provider:
// refuse instead of silently overwriting it.
if ( 'bind_conflict' === $scenario ) {
	$uid = pm_child_user();
	update_user_meta( $uid, 'at8mluc_oauth_github_id', 'pm-old-identity' );
	$GLOBALS['pm_log']['user_id']   = $uid;
	$GLOBALS['pm_log']['probe_key'] = 'at8mluc_oauth_github_id';
	$GLOBALS['pm_log']['probe_val'] = 'pm-bind-fixture3';

	pm_child_oauth_login( $uid, false, '', 'at8mluc_oauth_github_id', 'pm-bind-fixture3' );
}

// A security plugin refuses the sign-in: the pending binding must not be
// written even though the account itself matched.
if ( 'bind_after_veto' === $scenario ) {
	$uid = pm_child_user();
	$GLOBALS['pm_log']['user_id']   = $uid;
	$GLOBALS['pm_log']['probe_key'] = 'at8mluc_oauth_github_id';
	$GLOBALS['pm_log']['probe_val'] = 'pm-bind-fixture4';

	add_filter(
		'at8mluc_oauth_authenticate_user',
		function () {
			return new WP_Error( 'pm_blocked', 'blocked by the fixture' );
		}
	);

	pm_child_oauth_login( $uid, false, '', 'at8mluc_oauth_github_id', 'pm-bind-fixture4' );
}

// Step 1 of the register-retry flow: a first-time sign-up is created but the
// login policy refuses it. The account stays, unlinked and sessionless.
if ( 'bind_retry_register_step1' === $scenario ) {
	pm_child_scrub_identity( 'at8mluc_oauth_wechat_id', 'pm-retry-fixture' );
	$GLOBALS['pm_restore']['users_can_register'] = get_option( 'users_can_register' );
	update_option( 'users_can_register', 1 );

	add_filter(
		'at8mluc_oauth_authenticate_user',
		function () {
			return new WP_Error( 'pm_blocked', 'blocked by the fixture' );
		}
	);

	$GLOBALS['pm_log']['probe_key'] = 'at8mluc_oauth_wechat_id';
	$GLOBALS['pm_log']['probe_val'] = 'pm-retry-fixture';

	pm_child_oauth_register( 'wechat', pm_child_oauth_profile( 'wechat', 'pm-retry-fixture' ) );
}

// Step 2: the same visitor comes back after the policy issue was fixed. The
// existing unlinked account must be reused, not duplicated, and the binding
// is written once authentication passes.
if ( 'bind_retry_register_step2' === $scenario ) {
	$GLOBALS['pm_restore']['users_can_register'] = get_option( 'users_can_register' );
	update_option( 'users_can_register', 1 );

	$GLOBALS['pm_log']['probe_key'] = 'at8mluc_oauth_wechat_id';
	$GLOBALS['pm_log']['probe_val'] = 'pm-retry-fixture';

	pm_child_oauth_register( 'wechat', pm_child_oauth_profile( 'wechat', 'pm-retry-fixture' ) );
}

// An unverified provider e-mail must never be used to claim an existing
// account, not even through the register-retry path.
if ( 'email_unverified_no_hijack' === $scenario ) {
	pm_child_scrub_identity( 'at8mluc_oauth_github_id', 'pm-unverified-id' );
	$owner = pm_child_user();
	$mail  = (string) get_userdata( $owner )->user_email;
	$GLOBALS['pm_restore']['users_can_register'] = get_option( 'users_can_register' );
	update_option( 'users_can_register', 1 );
	$GLOBALS['pm_log']['user_id']   = $owner;
	$GLOBALS['pm_log']['probe_key'] = 'at8mluc_oauth_github_id';
	$GLOBALS['pm_log']['probe_val'] = 'pm-unverified-id';

	pm_child_oauth_register( 'github', pm_child_oauth_profile( 'github', 'pm-unverified-id', $mail, false ) );
}

// A state token is consumed by the first callback even when the token
// exchange fails afterwards.
if ( 'state_consume' === $scenario ) {
	add_filter(
		'pre_http_request',
		function () {
			return new WP_Error( 'http_request_failed', 'offline fixture' );
		}
	);

	pm_child_oauth_callback( 'pmreplaystate00000000000000000001', 'pm-code-1' );
}

// The same state replayed afterwards is refused before anything else runs.
if ( 'state_replay' === $scenario ) {
	// The state was consumed (and deleted) by the first callback, so it is
	// not minted again here: this is exactly what a replay looks like.
	pm_child_oauth_callback( 'pmreplaystate00000000000000000001', 'pm-code-2', false );
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
