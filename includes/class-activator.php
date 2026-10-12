<?php
/**
 * Activation and deactivation routines.
 *
 * The plugin stores no custom database tables. All persistent state lives in
 * the `at8_mluc_options` option and in user meta owned by the plugin.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle hooks.
 */
class AT8_MLUC_Activator {

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'account_page_id'           => 0,
			'login_page_id'             => 0,
			'register_page_id'          => 0,
			'lostpassword_page_id'      => 0,
			'redirect_after_login'      => '',
			'enable_registration'       => 1,
			'enable_avatar'             => 1,
			'auto_login'                => 1,
			'delete_data'               => 0,
			'free_level_enabled'        => 1,
			'manual_enabled'            => 1,
			'pay_currency_symbol'       => '$',
			'pay_currency_code'         => 'USD',
			'pay_manual_instructions'   => '',
			'order_auto_close_hours'    => 72,
			'membership_levels'         => array(),
			'nav_icons'                 => array(),
			'ui_labels'                 => array(),
			'oauth'                     => array(),
		);
	}

	/**
	 * Run on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		$existing = get_option( 'at8_mluc_options', array() );

		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		update_option( 'at8_mluc_options', array_merge( self::defaults(), $existing ), false );

		// Record the plugin version so upgrades can run migrations later.
		update_option( 'at8_mluc_version', AT8_MLUC_VERSION, false );
	}

	/**
	 * Run on deactivation.
	 *
	 * No user data is removed here; removal only happens on uninstall and only
	 * when the site owner asked for it.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Intentionally empty: nothing to undo on deactivation.
	}
}
