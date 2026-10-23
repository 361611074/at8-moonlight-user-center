<?php
/**
 * Plugin Name:       AT8 Moonlight User Center
 * Description:       A lightweight, theme-agnostic front-end user center. Provides login, registration, password recovery, an account dashboard, profile editing, an admin-curated avatar library, social login, hidden content and membership sales with offline payments.
 * Version:           2.5.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            漫步白月光
 * Author URI:        https://www.at8.fun/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       at8-moonlight-user-center
 * Domain Path:       /languages
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/*
 * Every constant below is namespaced with the AT8_MLUC_ prefix, which no other
 * plugin on this site defines. In particular AT8_MLUC_PLUGIN_DIR must never be
 * borrowed from a different plugin: the autoloader below resolves classes
 * relative to __DIR__ instead, so a constant defined elsewhere can never
 * redirect this plugin's class loading.
 */
define( 'AT8_MLUC_VERSION', '2.5.1' );
define( 'AT8_MLUC_PLUGIN_FILE', __FILE__ );
define( 'AT8_MLUC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AT8_MLUC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AT8_MLUC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Global function prefix: at8_mluc_
 * Text domain: at8-moonlight-user-center
 */

require_once AT8_MLUC_PLUGIN_DIR . 'includes/functions.php';

/**
 * Register the plugin autoloader.
 *
 * Maps `AT8_MLUC_Foo_Bar` to `includes/class-foo-bar.php`.
 *
 * The path is built from `__DIR__`, never from a shared constant, so class
 * loading cannot be redirected by another plugin that happens to define a
 * constant with the same name.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function at8_mluc_autoload( $class_name ) {
	if ( 0 !== strpos( $class_name, 'AT8_MLUC_' ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( 'AT8_MLUC_' ) );
	$file     = __DIR__ . '/includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'at8_mluc_autoload' );

/*
 * Translations are loaded by WordPress itself: since 4.6 core fetches the
 * catalog for wordpress.org plugins from the translate server, so an explicit
 * load_plugin_textdomain() call is unnecessary (and discouraged) here.
 */

/**
 * Boot every plugin module.
 *
 * Modules are instantiated on `plugins_loaded` so that other plugins can
 * unhook or extend them. The `at8_mluc_loaded` action fires once every module
 * is available.
 *
 * @return void
 */
function at8_mluc_bootstrap() {
	AT8_MLUC_Assets::get_instance();

	$modules = array(
		'AT8_MLUC_Register',
		'AT8_MLUC_Login',
		'AT8_MLUC_Profile',
		'AT8_MLUC_Avatar',
		'AT8_MLUC_OAuth',
		'AT8_MLUC_Hidecontent',
		'AT8_MLUC_Membership',
		'AT8_MLUC_Payments',
		'AT8_MLUC_Membership_Tab',
		'AT8_MLUC_Upgrade',
		'AT8_MLUC_Editor_Button',
		// Elementor integration self-guards on class_exists, so it is safe
		// to instantiate even when Elementor is not installed.
		'AT8_MLUC_Elementor',
		'AT8_MLUC_Shortcodes',
		'AT8_MLUC_Menu',
		'AT8_MLUC_Privacy',
		'AT8_MLUC_Settings',
		'AT8_MLUC_Fields',
		'AT8_MLUC_Terms',
		'AT8_MLUC_Points',
		'AT8_MLUC_Upgrade_Paths',
		'AT8_MLUC_Paywall',
	);

	foreach ( $modules as $module ) {
		if ( class_exists( $module ) && is_callable( array( $module, 'get_instance' ) ) ) {
			call_user_func( array( $module, 'get_instance' ) );
		}
	}

	/**
	 * Fires once AT8 Moonlight User Center and all of its modules have loaded.
	 *
	 * @since 1.0.0
	 */
	do_action( 'at8_mluc_loaded' );
}
add_action( 'plugins_loaded', 'at8_mluc_bootstrap' );

register_activation_hook( __FILE__, array( 'AT8_MLUC_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AT8_MLUC_Activator', 'deactivate' ) );
