<?php
/**
 * Front-end login, logout and password recovery.
 *
 * Authentication always goes through the WordPress user APIs; this plugin
 * never inspects or stores password hashes of its own.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Login and credential handling.
 */
class AT8_MLUC_Login {

	/**
	 * Nonce action for the login form.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'at8_mluc_login';

	/**
	 * Nonce action for the password recovery form.
	 *
	 * @var string
	 */
	const LOST_NONCE_ACTION = 'at8_mluc_lost_password';

	/**
	 * Nonce action for the change password form.
	 *
	 * @var string
	 */
	const PASSWORD_NONCE_ACTION = 'at8_mluc_change_password';

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Login|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Login
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_action( 'wp_ajax_nopriv_at8_mluc_login', array( $this, 'handle_login' ) );
		add_action( 'wp_ajax_at8_mluc_login', array( $this, 'handle_login' ) );
		add_action( 'wp_ajax_nopriv_at8_mluc_lost_password', array( $this, 'handle_lost_password' ) );
		add_action( 'wp_ajax_at8_mluc_lost_password', array( $this, 'handle_lost_password' ) );
		add_action( 'wp_ajax_at8_mluc_change_password', array( $this, 'handle_change_password' ) );
	}

	/**
	 * Handle a front-end login request.
	 *
	 * @return void
	 */
	public function handle_login() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, AT8_MLUC_Security::NONCE_FIELD, false ) ) {
			at8_mluc_send_json( false, __( 'The security check failed. Please reload the page and try again.', 'at8-moonlight-user-center' ) );
		}

		if ( ! AT8_MLUC_Security::allow( 'login' ) ) {
			AT8_MLUC_Security::reject_throttled();
		}

		$user_login = isset( $_POST['user_login'] ) ? sanitize_user( wp_unslash( $_POST['user_login'] ), true ) : '';
		$password   = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered before verification.
		$password   = is_string( $password ) ? $password : '';
		$remember   = isset( $_POST['remember'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_POST['remember'] ) );

		if ( '' === $user_login || '' === $password ) {
			at8_mluc_send_json( false, __( 'Please enter your username and password.', 'at8-moonlight-user-center' ) );
		}

		/**
		 * Fires before the plugin attempts to sign a user in.
		 *
		 * @since 1.0.0
		 *
		 * @param string $user_login Submitted login name.
		 */
		do_action( 'at8_mluc_before_login', $user_login );

		$user = wp_signon(
			array(
				'user_login'    => $user_login,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			$code = $user->get_error_code();

			// Do not reveal whether the account exists: both cases read the same.
			if ( 'invalid_username' === $code || 'incorrect_password' === $code || 'invalid_email' === $code ) {
				at8_mluc_send_json( false, __( 'Incorrect username or password.', 'at8-moonlight-user-center' ) );
			}

			at8_mluc_send_json( false, at8_mluc_translate_wp_error( $user ) );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember );

		$requested_redirect = '';
		if ( isset( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ) {
			$requested_redirect = esc_url_raw( wp_unslash( $_POST['redirect_to'] ) );
		}

		at8_mluc_send_json(
			true,
			__( 'You are now logged in.', 'at8-moonlight-user-center' ),
			array( 'redirect' => self::resolve_redirect( $user->ID, $requested_redirect ) )
		);
	}

	/**
	 * Work out where to send a user after a successful login.
	 *
	 * The submitted `redirect_to` value is accepted only when it points back to
	 * this site, which prevents the form from being used as an open redirect.
	 * It is passed in by the caller, which has already verified the nonce.
	 *
	 * @param int    $user_id           Authenticated user ID.
	 * @param string $requested_redirect Sanitised redirect target submitted with the form.
	 * @return string Safe absolute URL.
	 */
	private static function resolve_redirect( $user_id, $requested_redirect = '' ) {
		$fallback = at8_mluc_get_account_url();
		$admin    = admin_url();

		if ( user_can( $user_id, 'edit_posts' ) && is_admin() ) {
			$fallback = $admin;
		}

		$configured = (string) at8_mluc_get_option( 'redirect_after_login', '' );
		if ( '' !== $configured && wp_http_validate_url( $configured ) ) {
			$fallback = $configured;
		}

		if ( '' !== $requested_redirect ) {
			$fallback = wp_validate_redirect( $requested_redirect, $fallback );
		}

		return $fallback;
	}

	/**
	 * Handle a front-end password recovery request.
	 *
	 * @return void
	 */
	public function handle_lost_password() {
		if ( ! check_ajax_referer( self::LOST_NONCE_ACTION, AT8_MLUC_Security::NONCE_FIELD, false ) ) {
			at8_mluc_send_json( false, __( 'The security check failed. Please reload the page and try again.', 'at8-moonlight-user-center' ) );
		}

		if ( ! AT8_MLUC_Security::allow( 'lost_password' ) ) {
			AT8_MLUC_Security::reject_throttled();
		}

		$user_login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';

		if ( '' === $user_login ) {
			at8_mluc_send_json( false, __( 'Please enter your username or email address.', 'at8-moonlight-user-center' ) );
		}

		$lost_url = at8_mluc_get_lostpassword_url();
		$errors   = retrieve_password( $user_login );

		/*
		 * The response never depends on whether the account exists, so the form
		 * cannot be used to enumerate registered users.
		 */
		if ( is_wp_error( $errors ) ) {
			$code = $errors->get_error_code();

			if ( 'invalidcombo' !== $code && 'invalid_email' !== $code && 'invalid_username' !== $code ) {
				at8_mluc_send_json( false, at8_mluc_translate_wp_error( $errors ) );
			}
		}

		at8_mluc_send_json(
			true,
			__( 'If that account exists, a password reset link has been sent to its email address.', 'at8-moonlight-user-center' ),
			array( 'redirect' => $lost_url )
		);
	}

	/**
	 * Handle a change password request from the account dashboard.
	 *
	 * @return void
	 */
	public function handle_change_password() {
		if ( ! check_ajax_referer( self::PASSWORD_NONCE_ACTION, AT8_MLUC_Security::NONCE_FIELD, false ) ) {
			at8_mluc_send_json( false, __( 'The security check failed. Please reload the page and try again.', 'at8-moonlight-user-center' ) );
		}
		AT8_MLUC_Security::require_login();

		if ( ! AT8_MLUC_Security::allow( 'password' ) ) {
			AT8_MLUC_Security::reject_throttled();
		}

		$current = isset( $_POST['current_password'] ) ? wp_unslash( $_POST['current_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered before verification.
		$new     = isset( $_POST['new_password'] ) ? wp_unslash( $_POST['new_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered before hashing.
		$confirm = isset( $_POST['confirm_password'] ) ? wp_unslash( $_POST['confirm_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered before comparison.
		$current = is_string( $current ) ? $current : '';
		$new     = is_string( $new ) ? $new : '';
		$confirm = is_string( $confirm ) ? $confirm : '';

		if ( '' === $current || '' === $new || '' === $confirm ) {
			at8_mluc_send_json( false, __( 'Please fill in every password field.', 'at8-moonlight-user-center' ) );
		}

		if ( $new !== $confirm ) {
			at8_mluc_send_json( false, __( 'The two new passwords do not match.', 'at8-moonlight-user-center' ) );
		}

		if ( strlen( $new ) < 8 ) {
			at8_mluc_send_json( false, __( 'Please choose a password of at least 8 characters.', 'at8-moonlight-user-center' ) );
		}

		$user = wp_get_current_user();

		if ( ! $user || ! $user->ID ) {
			at8_mluc_send_json( false, __( 'Please log in first.', 'at8-moonlight-user-center' ) );
		}

		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			at8_mluc_send_json( false, __( 'Your current password is incorrect.', 'at8-moonlight-user-center' ) );
		}

		/**
		 * Fires before the plugin changes a user's password.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id User ID.
		 */
		do_action( 'at8_mluc_before_password_change', $user->ID );

		wp_set_password( $new, $user->ID );

		/*
		 * `wp_set_password()` invalidates every session cookie, including this
		 * one. Signing the user back in keeps the current tab usable, then all
		 * other sessions are dropped.
		 */
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		if ( function_exists( 'wp_destroy_other_sessions' ) ) {
			wp_destroy_other_sessions();
		}

		/**
		 * Fires after a user's password was changed through the dashboard.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id User ID.
		 */
		do_action( 'at8_mluc_after_password_change', $user->ID );

		at8_mluc_send_json( true, __( 'Your password has been changed.', 'at8-moonlight-user-center' ) );
	}
}
