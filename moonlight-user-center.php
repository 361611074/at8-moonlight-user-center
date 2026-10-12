<?php
/**
 * Plugin Name:       Moonlight User Center
 * Description:       A lightweight, theme-agnostic front-end user center. Provides login, registration, password recovery, an account dashboard, profile editing and an admin-curated avatar library.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            moonlight
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       moonlight-user-center
 * Domain Path:       /languages
 *
 * @package Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

define( 'MLUC_VERSION', '1.0.0' );
define( 'MLUC_PLUGIN_FILE', __FILE__ );
define( 'MLUC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MLUC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MLUC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Global function prefix: mluc_
 * Text domain: moonlight-user-center
 */

require_once MLUC_PLUGIN_DIR . 'includes/functions.php';

/**
 * Register the plugin autoloader.
 *
 * Maps `MLUC_Foo_Bar` to `includes/class-foo-bar.php`.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function mluc_autoload( $class_name ) {
	if ( 0 !== strpos( $class_name, 'MLUC_' ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( 'MLUC_' ) );
	$file     = MLUC_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'mluc_autoload' );

/**
 * Load the plugin text domain.
 *
 * @return void
 */
function mluc_load_textdomain() {
	load_plugin_textdomain( 'moonlight-user-center', false, dirname( MLUC_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'init', 'mluc_load_textdomain' );

/**
 * Boot every plugin module.
 *
 * Modules are instantiated on `plugins_loaded` so that other plugins can
 * unhook or extend them. The `mluc_loaded` action fires once every module
 * is available.
 *
 * @return void
 */
function mluc_bootstrap() {
	MLUC_Assets::get_instance();

	$modules = array(
		'MLUC_Register',
		'MLUC_Login',
		'MLUC_Profile',
		'MLUC_Avatar',
		'MLUC_Shortcodes',
		'MLUC_Privacy',
		'MLUC_Settings',
	);

	foreach ( $modules as $module ) {
		if ( class_exists( $module ) && is_callable( array( $module, 'get_instance' ) ) ) {
			call_user_func( array( $module, 'get_instance' ) );
		}
	}

	/**
	 * Fires once Moonlight User Center and all of its modules have loaded.
	 *
	 * @since 1.0.0
	 */
	do_action( 'mluc_loaded' );
}
add_action( 'plugins_loaded', 'mluc_bootstrap' );

register_activation_hook( __FILE__, array( 'MLUC_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MLUC_Activator', 'deactivate' ) );
