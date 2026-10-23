<?php
/**
 * Shared helper functions.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the commercial add-on (AT8 Moonlight User Center Pro) is active.
 *
 * This is the single bridge between the free plugin and its paid add-on. The
 * free plugin ships no premium code at all: every Pro capability point is a
 * filter that defaults to "off" here, and the Pro plugin — hosted outside of
 * WordPress.org — flips its own filters once a valid license is confirmed.
 *
 * Free sites therefore never contain locked functionality, and the Pro plugin
 * can be installed, updated and removed independently of this one.
 *
 * @since 2.0.0
 *
 * @return bool True when a licensed Pro add-on is active.
 */
function at8_mluc_is_pro() {
	/**
	 * Filters whether the Pro add-on is active.
	 *
	 * @since 2.0.0
	 *
	 * @param bool $is_pro Whether Pro is active. Default false.
	 */
	return (bool) apply_filters( 'at8_mluc_is_pro', false );
}

/**
 * Read a single plugin setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Value returned when the key is absent.
 * @return mixed Setting value or the supplied default.
 */
function at8_mluc_get_option( $key, $default = '' ) {
	$options = get_option( 'at8_mluc_options', array() );

	if ( ! is_array( $options ) || ! isset( $options[ $key ] ) ) {
		return $default;
	}

	return $options[ $key ];
}

/**
 * Read a front-end interface label.
 *
 * Labels are translated strings and can be replaced by a filter, which keeps
 * the plugin fully localisable: no wording is stored in the database.
 *
 * @param string $key     Label key.
 * @param string $default Already-translated default text.
 * @return string
 */
function at8_mluc_ui_label( $key, $default = '' ) {
	/**
	 * Filters a front-end interface label.
	 *
	 * @since 1.0.0
	 *
	 * @param string $default Already-translated default text.
	 * @param string $key     Label key.
	 */
	return (string) apply_filters( 'at8_mluc_ui_label', $default, $key );
}

/**
 * Resolve a stored page ID to a permalink.
 *
 * @param string $option_key Option holding the page ID.
 * @param string $fallback   URL used when no page is configured.
 * @return string
 */
function at8_mluc_page_url( $option_key, $fallback = '' ) {
	$page_id = absint( at8_mluc_get_option( $option_key, 0 ) );

	if ( $page_id && 'page' === get_post_type( $page_id ) && 'publish' === get_post_status( $page_id ) ) {
		$permalink = get_permalink( $page_id );
		if ( $permalink ) {
			return $permalink;
		}
	}

	return $fallback;
}

/**
 * URL of the account dashboard.
 *
 * @return string
 */
function at8_mluc_get_account_url() {
	$url = at8_mluc_page_url( 'account_page_id', '' );

	if ( $url ) {
		return $url;
	}

	$fallback = home_url( '/' );

	/**
	 * Filters the fallback account URL used when no page is configured.
	 *
	 * @since 1.0.0
	 *
	 * @param string $fallback Default fallback URL.
	 */
	return apply_filters( 'at8_mluc_default_account_url', $fallback );
}

/**
 * URL of the login screen.
 *
 * @param string $redirect Optional same-site URL to return to after login.
 * @return string
 */
function at8_mluc_get_login_url( $redirect = '' ) {
	$redirect = $redirect ? $redirect : at8_mluc_get_account_url();
	$url      = at8_mluc_page_url( 'login_page_id', '' );

	if ( $url ) {
		return add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
	}

	return wp_login_url( $redirect );
}

/**
 * URL of the registration screen.
 *
 * @return string
 */
function at8_mluc_get_register_url() {
	$url = at8_mluc_page_url( 'register_page_id', '' );

	if ( $url ) {
		return $url;
	}

	return wp_registration_url();
}

/**
 * URL of the password recovery screen.
 *
 * @return string
 */
function at8_mluc_get_lostpassword_url() {
	$url = at8_mluc_page_url( 'lostpassword_page_id', '' );

	if ( $url ) {
		return $url;
	}

	$login_url = at8_mluc_page_url( 'login_page_id', '' );

	if ( $login_url ) {
		return add_query_arg( 'at8_mluc_view', 'lostpassword', $login_url );
	}

	return wp_lostpassword_url();
}

/**
 * Render a plugin template.
 *
 * Themes may override any template by creating
 * `wp-content/themes/<theme>/at8-moonlight-user-center/<slug>.php`.
 *
 * The template receives the arguments array as `$args` and must read its values
 * from there, for example `$args['user']`.
 *
 * @param string $slug Template slug without the `.php` extension.
 * @param array  $args Variables exposed to the template.
 * @return void
 */
function at8_mluc_get_template( $slug, $args = array() ) {
	$slug = sanitize_file_name( $slug );

	$theme_file  = trailingslashit( get_stylesheet_directory() ) . 'at8-moonlight-user-center/' . $slug . '.php';
	$plugin_file = AT8_MLUC_PLUGIN_DIR . 'templates/' . $slug . '.php';

	$file = file_exists( $theme_file ) ? $theme_file : $plugin_file;

	if ( ! file_exists( $file ) ) {
		return;
	}

	/**
	 * Filters the template arguments before the template is rendered.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $args Template arguments.
	 * @param string $slug Template slug.
	 */
	$args = apply_filters( 'at8_mluc_template_args', $args, $slug );
	$args = is_array( $args ) ? $args : array();

	include $file;
}

/**
 * Send a JSON response and stop.
 *
 * @param bool   $success Whether the request succeeded.
 * @param string $message Human readable message.
 * @param array  $data    Optional extra payload.
 * @return void
 */
function at8_mluc_send_json( $success, $message, $data = array() ) {
	wp_send_json(
		array(
			'success' => (bool) $success,
			'message' => (string) $message,
			'data'    => is_array( $data ) ? $data : array(),
		)
	);
}

/**
 * Count a user's approved comments.
 *
 * @param int $user_id User ID.
 * @return int
 */
function at8_mluc_count_user_comments( $user_id ) {
	$count = get_comments(
		array(
			'user_id' => absint( $user_id ),
			'status'  => 'approve',
			'count'   => true,
		)
	);

	return $count ? (int) $count : 0;
}

/**
 * Translate a WP_Error raised by the WordPress user APIs.
 *
 * Unknown errors fall back to the original (already translated) message.
 *
 * @param WP_Error $error Error object.
 * @return string
 */
function at8_mluc_translate_wp_error( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return '';
	}

	$map = array(
		'invalid_username'   => __( 'That username does not exist.', 'at8-moonlight-user-center' ),
		'incorrect_password' => __( 'Incorrect username or password.', 'at8-moonlight-user-center' ),
		'empty_username'     => __( 'Please enter a username.', 'at8-moonlight-user-center' ),
		'empty_password'     => __( 'Please enter a password.', 'at8-moonlight-user-center' ),
		'existing_user_login' => __( 'That username is already taken.', 'at8-moonlight-user-center' ),
		'existing_user_email' => __( 'That email address is already registered.', 'at8-moonlight-user-center' ),
		'invalid_email'      => __( 'That email address is not valid.', 'at8-moonlight-user-center' ),
	);

	$code = $error->get_error_code();

	return isset( $map[ $code ] ) ? $map[ $code ] : $error->get_error_message();
}

/**
 * User meta keys owned by this plugin, with human-readable labels.
 *
 * The privacy exporter, the privacy eraser and uninstall.php all read from this
 * single list, so the three can never drift apart.
 *
 * @return array<string, string> Meta key => field label.
 */
function at8_mluc_meta_labels() {
	return array(
		'at8_mluc_phone'                => __( 'Phone number', 'at8-moonlight-user-center' ),
		'at8_mluc_avatar_url'           => __( 'Selected avatar URL', 'at8-moonlight-user-center' ),
		'at8_mluc_avatar_attachment_id' => __( 'Selected avatar media ID', 'at8-moonlight-user-center' ),
		'at8_mluc_avatar_type'          => __( 'Avatar source', 'at8-moonlight-user-center' ),
		'at8_mluc_last_login'           => __( 'Last login date', 'at8-moonlight-user-center' ),
		'at8_mluc_membership_level'     => __( 'Membership level', 'at8-moonlight-user-center' ),
		'at8_mluc_membership_expires'   => __( 'Membership expiry date', 'at8-moonlight-user-center' ),
		'at8_mluc_points'               => __( 'Point balance', 'at8-moonlight-user-center' ),
		'at8_mluc_points_log'           => __( 'Point ledger', 'at8-moonlight-user-center' ),
		'at8_mluc_points_entry'         => __( 'Point ledger entry', 'at8-moonlight-user-center' ),
		'at8_mluc_pay_unlocks'          => __( 'Paywall unlocks', 'at8-moonlight-user-center' ),
		// Legacy Pro 1.x keys: the membership layer reads them as a migration
		// fallback, so the plugin owns their lifecycle (export/erase/uninstall).
		'at8_mlucp_membership_level'    => __( 'Membership level (legacy Pro 1.x)', 'at8-moonlight-user-center' ),
		'at8_mlucp_membership_expires'  => __( 'Membership expiry date (legacy Pro 1.x)', 'at8-moonlight-user-center' ),
	);
}

/**
 * The user meta keys owned by this plugin.
 *
 * @return string[] Meta keys.
 */
function at8_mluc_user_meta_keys() {
	return array_keys( at8_mluc_meta_labels() );
}
