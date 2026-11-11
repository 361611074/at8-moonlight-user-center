<?php
/**
 * Child process for the authentication-chain suite.
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
	'scenario'    => (string) getenv( 'PM_SCENARIO' ),
	'wp_login'    => 0,
	'login_name'  => '',
	'cookies'     => 0,
	'fail_code'   => '',
	'fail_msg'    => '',
	'oath_login'  => 0,
	'payload'     => '',
	'user_id'     => 0,
	'registered'  => 0,
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

add_action(
	'set_auth_cookie',
	function () {
		$GLOBALS['pm_log']['cookies']++;
	}
);

add_action(
	'at8mluc_oauth_login',
	function () {
		$GLOBALS['pm_log']['oath_login']++;
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
 * @param int $user_id User to sign in.
 * @return void
 */
function pm_child_oauth_login( $user_id ) {
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
		''
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

	$GLOBALS['pm_restore']['users_can_register']  = get_option( 'users_can_register' );
	$GLOBALS['pm_restore']['at8mluc_options']     = get_option( 'at8mluc_options' );

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
