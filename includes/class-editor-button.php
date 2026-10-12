<?php
/**
 * Classic editor "Hidden Content" menu button (TinyMCE plugin + toolbar registration).
 *
 * The script is only enqueued on post / page edit screens, so the front end
 * never pays for it.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Editor button for the hidden-content shortcode.
 */
class AT8_MLUC_Editor_Button {

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Editor_Button
	 */
	public static function get_instance() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		// Only register on post / page edit screens.
		add_action( 'admin_head-post.php', array( $this, 'register_button' ) );
		add_action( 'admin_head-post-new.php', array( $this, 'register_button' ) );
	}

	/**
	 * Register the TinyMCE plugin and toolbar button (post / page only).
	 *
	 * @return void
	 */
	public function register_button() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! isset( $screen->post_type ) ) {
			return;
		}

		if ( ! in_array( $screen->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		add_filter( 'mce_external_plugins', array( $this, 'add_tinymce_plugin' ) );
		add_filter( 'mce_buttons_2', array( $this, 'register_toolbar_button' ) );
	}

	/**
	 * Inject at8-mluc-tinymce.js as a TinyMCE plugin.
	 *
	 * @param array $plugins Plugin map.
	 * @return array
	 */
	public function add_tinymce_plugin( $plugins ) {
		$plugins['at8_mluc_hidecontent'] = AT8_MLUC_PLUGIN_URL . 'assets/js/at8-mluc-tinymce.js?ver=' . AT8_MLUC_VERSION;

		return $plugins;
	}

	/**
	 * Add the button to the second toolbar row.
	 *
	 * @param array $buttons Button names.
	 * @return array
	 */
	public function register_toolbar_button( $buttons ) {
		$buttons[] = 'at8_mluc_hidecontent';

		return $buttons;
	}
}
