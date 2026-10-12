<?php
/**
 * Shared request security: nonce checks, capability checks and rate limiting.
 *
 * Every AJAX handler in this plugin routes its nonce, capability and throttling
 * checks through this class so the checks cannot drift apart.
 *
 * @package Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Request hardening helpers.
 */
class MLUC_Security {

	/**
	 * Nonce action used by every front-end AJAX request.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'mluc_ajax';

	/**
	 * Request field carrying the nonce.
	 *
	 * @var string
	 */
	const NONCE_FIELD = 'nonce';

	/**
	 * Rate limit windows, in seconds, keyed by action.
	 *
	 * @var array<string, array{limit:int, window:int}>
	 */
	private static $limits = array(
		'login'         => array(
			'limit'  => 10,
			'window' => 600,
		),
		'register'      => array(
			'limit'  => 5,
			'window' => 3600,
		),
		'lost_password' => array(
			'limit'  => 3,
			'window' => 900,
		),
		'profile'       => array(
			'limit'  => 30,
			'window' => 600,
		),
		'password'      => array(
			'limit'  => 10,
			'window' => 600,
		),
		'avatar'        => array(
			'limit'  => 30,
			'window' => 600,
		),
	);

	/**
	 * Verify the AJAX nonce for a handler.
	 *
	 * Uses `check_ajax_referer()` with `$die = false` so the caller decides how
	 * the failure is reported.
	 *
	 * @param string $action Nonce action; defaults to the shared AJAX action.
	 * @return bool True when the nonce is present and valid.
	 */
	public static function verify_ajax_nonce( $action = self::NONCE_ACTION ) {
		return (bool) check_ajax_referer( $action, self::NONCE_FIELD, false );
	}

	/**
	 * Reject an AJAX request whose nonce is missing or invalid.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	public static function require_ajax_nonce( $action = self::NONCE_ACTION ) {
		if ( ! self::verify_ajax_nonce( $action ) ) {
			mluc_send_json( false, __( 'The security check failed. Please reload the page and try again.', 'moonlight-user-center' ) );
		}
	}

	/**
	 * Reject an AJAX request from a logged-out visitor.
	 *
	 * Nonces are not authorization. Every handler that acts on an existing
	 * account is gated on the `read` capability, which every logged-in user
	 * holds and no logged-out visitor can obtain.
	 *
	 * @return void
	 */
	public static function require_login() {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			mluc_send_json( false, __( 'Please log in first.', 'moonlight-user-center' ) );
		}
	}

	/**
	 * Reject an AJAX request from a user lacking a capability.
	 *
	 * @param string $capability Required capability.
	 * @return void
	 */
	public static function require_capability( $capability ) {
		if ( ! current_user_can( $capability ) ) {
			mluc_send_json( false, __( 'You are not allowed to perform this action.', 'moonlight-user-center' ) );
		}
	}

	/**
	 * The client IP address, normalised for use as a rate limit key.
	 *
	 * Only `REMOTE_ADDR` is trusted; proxy headers are attacker controlled and
	 * are deliberately ignored.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$raw = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		$ip = filter_var( $raw, FILTER_VALIDATE_IP );

		return $ip ? $ip : 'unknown';
	}

	/**
	 * Consume one unit of a rate limit bucket.
	 *
	 * @param string $action Bucket name; must exist in the limits table.
	 * @param string $extra  Optional extra discriminator, such as a login name.
	 * @return bool True when the request is within the limit.
	 */
	public static function allow( $action, $extra = '' ) {
		if ( ! isset( self::$limits[ $action ] ) ) {
			return true;
		}

		$limit  = (int) self::$limits[ $action ]['limit'];
		$window = (int) self::$limits[ $action ]['window'];

		/**
		 * Filters the request limit for an action.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $limit  Maximum requests inside the window.
		 * @param string $action Action name.
		 */
		$limit = (int) apply_filters( 'mluc_rate_limit', $limit, $action );

		if ( $limit < 1 ) {
			return true;
		}

		$key   = 'mluc_rl_' . md5( $action . '|' . self::client_ip() . '|' . strtolower( (string) $extra ) );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * Report a rejected request that exceeded a rate limit.
	 *
	 * @return void
	 */
	public static function reject_throttled() {
		mluc_send_json( false, __( 'Too many attempts. Please wait a moment and try again.', 'moonlight-user-center' ) );
	}

	/**
	 * Capability required to manage this plugin's settings.
	 *
	 * @return string
	 */
	public static function manage_capability() {
		/**
		 * Filters the capability required to manage the plugin.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'mluc_manage_capability', 'manage_options' );
	}
}
