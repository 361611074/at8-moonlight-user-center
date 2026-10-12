<?php
/**
 * Front-end and admin asset loading.
 *
 * Assets are only enqueued on screens that actually render the plugin, so no
 * other page pays for them.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Conditional asset registration.
 */
class AT8_MLUC_Assets {

	/**
	 * Shortcodes that need front-end assets when they are present.
	 *
	 * @var string[]
	 */
	const SHORTCODES = array( 'at8_mluc_login', 'at8_mluc_register', 'at8_mluc_lostpassword', 'at8_mluc_account' );

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Assets|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Assets
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
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueue the front-end styles and scripts when they are needed.
	 *
	 * @return void
	 */
	public function maybe_enqueue() {
		if ( ! $this->should_enqueue() ) {
			return;
		}

		wp_enqueue_style(
			'at8-mluc-frontend',
			AT8_MLUC_PLUGIN_URL . 'assets/css/mluc.css',
			array( 'dashicons' ),
			AT8_MLUC_VERSION
		);

		wp_enqueue_script(
			'at8-mluc-frontend',
			AT8_MLUC_PLUGIN_URL . 'assets/js/mluc.js',
			array(),
			AT8_MLUC_VERSION,
			true
		);

		wp_localize_script(
			'at8-mluc-frontend',
			'at8MlucData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'login'          => wp_create_nonce( AT8_MLUC_Login::NONCE_ACTION ),
					'lostPassword'   => wp_create_nonce( AT8_MLUC_Login::LOST_NONCE_ACTION ),
					'changePassword' => wp_create_nonce( AT8_MLUC_Login::PASSWORD_NONCE_ACTION ),
					'register'       => wp_create_nonce( AT8_MLUC_Register::NONCE_ACTION ),
					'profile'        => wp_create_nonce( AT8_MLUC_Profile::NONCE_ACTION ),
					'avatar'         => wp_create_nonce( AT8_MLUC_Avatar::NONCE_ACTION ),
				),
				'i18n'    => array(
					'genericError' => __( 'Something went wrong. Please try again.', 'at8-moonlight-user-center' ),
					'sending'      => __( 'Please wait…', 'at8-moonlight-user-center' ),
				),
			)
		);
	}

	/**
	 * Decide whether this request renders the plugin.
	 *
	 * @return bool
	 */
	private function should_enqueue() {
		$needed = false;

		foreach ( self::SHORTCODES as $shortcode ) {
			if ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), $shortcode ) ) {
				$needed = true;
				break;
			}
		}

		if ( ! $needed && is_singular() ) {
			$content = (string) get_post_field( 'post_content', get_queried_object_id() );

			foreach ( self::SHORTCODES as $shortcode ) {
				// Shortcodes can also be placed inside a shortcode block.
				if ( false !== strpos( $content, '[' . $shortcode ) ) {
					$needed = true;
					break;
				}
			}
		}

		if ( ! $needed && is_page() ) {
			$page_id = get_queried_object_id();

			foreach ( array( 'account_page_id', 'login_page_id', 'register_page_id', 'lostpassword_page_id' ) as $option ) {
				if ( absint( at8_mluc_get_option( $option, 0 ) ) === (int) $page_id ) {
					$needed = true;
					break;
				}
			}
		}

		/**
		 * Filters whether the front-end assets should load on this request.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $needed Whether the plugin renders on this request.
		 */
		return (bool) apply_filters( 'at8_mluc_enqueue_assets', $needed );
	}
}
