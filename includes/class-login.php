<?php
/**
 * Front-end login, logout and password recovery.
 *
 * Authentication always goes through the WordPress user APIs; this plugin
 * never inspects or stores password hashes of its own.
 *
 * @package Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Login and credential handling.
 */
class MLUC_Login {

	/**
	 * Nonce action for the login form.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'mluc_login';

	/**
	 * Nonce action for the password recovery form.
	 *
	 * @var string
	 */
	const LOST_NONCE_ACTION = 'mluc_lost_password';

	/**
	 * Nonce action for the change password form.
	 *
	 * @var string
	 */
	const PASSWORD_NONCE_ACTION = 'mluc_change_password';

	/**
	 * Singleton instance.
	 *
	 * @var MLUC_Login|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return MLUC_Login
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
		add_action( 'wp_ajax_nopriv_mluc_login', array( $this, 'handle_login' ) );
		add_action( 'wp_ajax_mluc_login', array( $this, 'handle_login' ) );
		add_action( 'wp_ajax_nopriv_mluc_lost_password', array( $this, 'handle_lost_password' ) );
		add_action( 'wp_ajax_mluc_lost_password', array( $this, 'handle_lost_password' ) );
		add_action( 'wp_ajax_mluc_change_password', array( $this, 'handle_change_password' ) );
	}

	/**
	 * Handle a front-end login request.
	 *
	 * @return void
	 */
	public function handle_login() {
		MLUC_Security::require_ajax_nonce( self::NONCE_ACTION );

		if ( ! MLUC_Security::allow( 'login' ) ) {
			MLUC_Security::reject_throttled();
		}

		$user_login = isset( $_POST['user_login'] ) ? sanitize_user( wp_unslash( $_POST['user_login'] ), true ) : '';
		$password   = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered before verification.
		$password   = is_string( $password ) ? $password : '';
		$remember   = isset( $_POST['remember'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_POST['remember'] ) );

		if ( '' === $user_login || '' === $password ) {
			mluc_send_json( false, __( 'Please enter your username and password.', 'moonlight-user-center' ) );
		}

		/**
		 * Fires before the plugin attempts to sign a user in.
		 *
		 * @since 1.0.0
		 *
		 * @param string $user_login Submitted login name.
		 */
		do_action( 'mluc_before_login', $user_login );

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
				mluc_send_json( false, __( 'Incorrect username or password.', 'moonlight-user-center' ) );
			}

			mluc_send_json( false, mluc_translate_wp_error( $user ) );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember );

		mluc_send_json(
			true,
			__( 'You are now logged in.', 'moonlight-user-center' ),
			array( 'redirect' => self::resolve_redirect( $user->ID ) )
		);
	}

	/**
	 * Work out where to send a user after a successful login.
	 *
	 * The submitted `redirect_to` value is accepted only when it points back to
	 * this site, which prevents the form from being used as an open redirect.
	 *
	 * @param int $user_id Authenticated user ID.
	 * @return string Safe absolute URL.
	 */
	private static function resolve_redirect( $user_id ) {
		$fallback = mluc_get_account_url();
		$admin    = admin_url();

		if ( user_can( $user_id, 'edit_posts' ) && is_admin() ) {
			$fallback = $admin;
		}

		$configured = (string) mluc_get_option( 'redirect_after_login', '' );
		if ( '' !== $configured && wp_http_validate_url( $configured ) ) {
			$fallback = $configured;
		}

		if ( isset( $_POST['redirect_to'] ) ) {
			$requested = esc_url_raw( wp_unslash( $_POST['redirect_to'] ) );
			$fallback  = wp_validate_redirect( $requested, $fallback );
		}

		return $fallback;
	}

	/**
	 * Handle a front-end password recovery request.
	 *
	 * @return void
	 */
	public function handle_lost_password() {
		MLUC_Security::require_ajax_nonce( self::LOST_NONCE_ACTION );

		if ( ! MLUC_Security::allow( 'lost_password' ) ) {
			MLUC_Security::reject_throttled();
		}

		$user_login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';

		if ( '' === $user_login ) {
			mluc_send_json( false, __( 'Please enter your username or email address.', 'moonlight-user-center' ) );
		}

		$lost_url = mluc_get_lostpassword_url();
		$errors   = retrieve_password( $user_login );

		/*
		 * The response never depends on whether the account exists, so the form
		 * cannot be used to enumerate registered users.
		 */
		if ( is_wp_error( $errors ) ) {
			$code = $errors->get_error_code();

			if ( 'invalidcombo' !== $code && 'invalid_email' !== $code && 'invalid_username' !== $code ) {
				mluc_send_json( false, mluc_translate_wp_error( $errors ) );
			}
		}

		mluc_send_json(
			true,
			__( 'If that account exists, a password reset link has been sent to its email address.', 'moonlight-user-center' ),
			array( 'redirect' => $lost_url )
		);
	}

	/**
	 * Handle a change password request from the account dashboard.
	 *
	 * @return void
	 */
	public function handle_change_password() {
		MLUC_Security::require_ajax_nonce( self::PASSWORD_NONCE_ACTION );
		MLUC_Security::require_login();

		if ( ! MLUC_Security::allow( 'password' ) ) {
			MLUC_Security::reject_throttled();
		}

		$current = isset( $_POST['current_password'] ) ? wp_unslash( $_POST['current_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered before verification.
		$new     = isset( $_POST['new_password'] ) ? wp_unslash( $_POST['new_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered before hashing.
		$confirm = isset( $_POST['confirm_password'] ) ? wp_unslash( $_POST['confirm_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered before comparison.
		$current = is_string( $current ) ? $current : '';
		$new     = is_string( $new ) ? $new : '';
		$confirm = is_string( $confirm ) ? $confirm : '';

		if ( '' === $current || '' === $new || '' === $confirm ) {
			mluc_send_json( false, __( 'Please fill in every password field.', 'moonlight-user-center' ) );
		}

		if ( $new !== $confirm ) {
			mluc_send_json( false, __( 'The two new passwords do not match.', 'moonlight-user-center' ) );
		}

		if ( strlen( $new ) < 8 ) {
			mluc_send_json( false, __( 'Please choose a password of at least 8 characters.', 'moonlight-user-center' ) );
		}

		$user = wp_get_current_user();

		if ( ! $user || ! $user->ID ) {
			mluc_send_json( false, __( 'Please log in first.', 'moonlight-user-center' ) );
		}

		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			mluc_send_json( false, __( 'Your current password is incorrect.', 'moonlight-user-center' ) );
		}

		/**
		 * Fires before the plugin changes a user's password.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id User ID.
		 */
		do_action( 'mluc_before_password_change', $user->ID );

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
		do_action( 'mluc_after_password_change', $user->ID );

		mluc_send_json( true, __( 'Your password has been changed.', 'moonlight-user-center' ) );
	}
}
