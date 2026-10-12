<?php
/**
 * Front-end registration.
 *
 * Users are always created through the WordPress user API. This plugin never
 * reads, stores, displays or transmits a plain-text password.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registration shortcode and AJAX handler.
 */
class AT8_MLUC_Register {

	/**
	 * Nonce action for the registration form.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'at8_mluc_register';

	/**
	 * Transient prefix used by the render token.
	 *
	 * @var string
	 */
	const TOKEN_PREFIX = 'at8_mluc_reg_tk_';

	/**
	 * Minimum number of seconds between rendering and submitting the form.
	 *
	 * @var int
	 */
	const MIN_FILL_SECONDS = 3;

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Register|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Register
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
		add_action( 'wp_ajax_nopriv_at8_mluc_register', array( $this, 'handle_ajax' ) );
		add_action( 'wp_ajax_at8_mluc_register', array( $this, 'handle_ajax' ) );
	}

	/**
	 * Whether front-end registration is enabled.
	 *
	 * Registration requires both the WordPress "anyone can register" option and
	 * the plugin setting.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( 'users_can_register' ) && (bool) at8_mluc_get_option( 'enable_registration', 1 );
	}

	/**
	 * Issue a single-use render token for the registration form.
	 *
	 * The token proves that the form was rendered by this site, that at least
	 * `MIN_FILL_SECONDS` passed before submission, and that it was submitted
	 * only once. It is not a substitute for the nonce; both are required.
	 *
	 * @return string Token.
	 */
	public static function issue_token() {
		$token = wp_generate_password( 32, false, false );

		set_transient( self::TOKEN_PREFIX . md5( $token ), time(), HOUR_IN_SECONDS );

		return $token;
	}

	/**
	 * Consume a render token.
	 *
	 * @param string $token Submitted token.
	 * @return bool True when the token was valid.
	 */
	private static function consume_token( $token ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}

		$key   = self::TOKEN_PREFIX . md5( $token );
		$stamp = get_transient( $key );

		if ( false === $stamp ) {
			return false;
		}

		delete_transient( $key );

		return ( time() - (int) $stamp ) >= self::MIN_FILL_SECONDS;
	}

	/**
	 * Handle the AJAX registration request.
	 *
	 * @return void
	 */
	public function handle_ajax() {
		AT8_MLUC_Security::require_ajax_nonce( self::NONCE_ACTION );

		if ( ! self::is_enabled() ) {
			at8_mluc_send_json( false, __( 'Registration is currently disabled on this site.', 'at8-moonlight-user-center' ) );
		}

		if ( ! AT8_MLUC_Security::allow( 'register' ) ) {
			AT8_MLUC_Security::reject_throttled();
		}

		// Honeypot: hidden from people, routinely filled in by bots.
		$honeypot = isset( $_POST['at8_mluc_hp'] ) ? sanitize_text_field( wp_unslash( $_POST['at8_mluc_hp'] ) ) : '';
		if ( '' !== $honeypot ) {
			at8_mluc_send_json( false, __( 'Registration failed. Please try again.', 'at8-moonlight-user-center' ) );
		}

		$token = isset( $_POST['at8_mluc_tk'] ) ? sanitize_text_field( wp_unslash( $_POST['at8_mluc_tk'] ) ) : '';
		if ( ! self::consume_token( $token ) ) {
			at8_mluc_send_json( false, __( 'Registration failed. Please try again.', 'at8-moonlight-user-center' ) );
		}

		$user_login = isset( $_POST['user_login'] ) ? sanitize_user( wp_unslash( $_POST['user_login'] ), true ) : '';
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		/*
		 * The password is deliberately not sanitised or transformed: it is
		 * passed straight to the WordPress hashing API. Only null bytes, which
		 * can truncate a value in some database layers, are rejected.
		 */
		$password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered before hashing.
		$password = is_string( $password ) ? $password : '';

		if ( false !== strpos( $password, "\0" ) ) {
			at8_mluc_send_json( false, __( 'The password contains characters that are not allowed.', 'at8-moonlight-user-center' ) );
		}

		if ( '' === $user_login || '' === $email || '' === $password ) {
			at8_mluc_send_json( false, __( 'Please fill in a username, an email address and a password.', 'at8-moonlight-user-center' ) );
		}

		if ( ! validate_username( $user_login ) ) {
			at8_mluc_send_json( false, __( 'That username contains invalid characters.', 'at8-moonlight-user-center' ) );
		}

		if ( ! is_email( $email ) ) {
			at8_mluc_send_json( false, __( 'That email address is not valid.', 'at8-moonlight-user-center' ) );
		}

		if ( strlen( $password ) < 8 ) {
			at8_mluc_send_json( false, __( 'Please choose a password of at least 8 characters.', 'at8-moonlight-user-center' ) );
		}

		if ( username_exists( $user_login ) ) {
			at8_mluc_send_json( false, __( 'That username is already taken.', 'at8-moonlight-user-center' ) );
		}

		if ( email_exists( $email ) ) {
			at8_mluc_send_json( false, __( 'That email address is already registered.', 'at8-moonlight-user-center' ) );
		}

		/**
		 * Filters the arguments passed to `wp_create_user()`.
		 *
		 * @since 1.0.0
		 *
		 * @param array $args User login, password and email.
		 */
		$args = apply_filters(
			'at8_mluc_register_user_args',
			array(
				'user_login' => $user_login,
				'password'   => $password,
				'email'      => $email,
			)
		);

		$user_id = wp_create_user( $args['user_login'], $args['password'], $args['email'] );

		if ( is_wp_error( $user_id ) ) {
			at8_mluc_send_json( false, at8_mluc_translate_wp_error( $user_id ) );
		}

		$user_id = (int) $user_id;

		/**
		 * Fires after a user registered through the front-end form.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id New user ID.
		 */
		do_action( 'at8_mluc_after_register', $user_id );

		$payload = array();

		if ( at8_mluc_get_option( 'auto_login', 1 ) ) {
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, true );
			$payload['redirect'] = at8_mluc_get_account_url();
		} else {
			$payload['redirect'] = at8_mluc_get_login_url();
		}

		at8_mluc_send_json( true, __( 'Your account was created.', 'at8-moonlight-user-center' ), $payload );
	}
}
