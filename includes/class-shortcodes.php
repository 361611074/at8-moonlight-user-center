<?php
/**
 * Shortcodes and the account dashboard router.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the public shortcodes and renders the account dashboard.
 */
class AT8_MLUC_Shortcodes {

	/**
	 * Nonce action protecting the logout link.
	 *
	 * @var string
	 */
	const LOGOUT_NONCE_ACTION = 'at8_mluc_logout';

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Shortcodes|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Shortcodes
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
		add_shortcode( 'at8_mluc_login', array( $this, 'render_login' ) );
		add_shortcode( 'at8_mluc_register', array( $this, 'render_register' ) );
		add_shortcode( 'at8_mluc_lostpassword', array( $this, 'render_lostpassword' ) );
		add_shortcode( 'at8_mluc_account', array( $this, 'render_account' ) );
	}

	/**
	 * Build a nonce-protected front-end logout URL.
	 *
	 * `wp_logout_url()` appends WordPress' own logout nonce, so the link cannot
	 * be used to log a visitor out through cross-site requests.
	 *
	 * @return string
	 */
	public static function get_logout_url() {
		return wp_logout_url( at8_mluc_get_account_url() );
	}

	/**
	 * Render the login form.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_login( $atts ) {
		$atts = shortcode_atts(
			array(
				'redirect_to' => '',
				'title'       => '',
			),
			$atts,
			'at8_mluc_login'
		);

		if ( is_user_logged_in() ) {
			return $this->render_logged_in_notice();
		}

		$view          = $this->current_view( 'login' );
		$redirect_to   = '' !== $atts['redirect_to'] ? esc_url_raw( $atts['redirect_to'] ) : '';
		$allowed_views = array( 'login', 'register', 'lostpassword' );

		if ( 'login' !== $view && in_array( $view, $allowed_views, true ) ) {
			return $this->render_auth_view( $view, $redirect_to );
		}

		return $this->render_auth_view( 'login', $redirect_to, $atts['title'] );
	}

	/**
	 * Render the registration form.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_register( $atts ) {
		$atts = shortcode_atts(
			array(
				'title' => '',
			),
			$atts,
			'at8_mluc_register'
		);

		if ( is_user_logged_in() ) {
			return $this->render_logged_in_notice();
		}

		$view = $this->current_view( 'register' );

		if ( 'login' === $view ) {
			return $this->render_auth_view( 'login', '' );
		}

		if ( 'lostpassword' === $view ) {
			return $this->render_auth_view( 'lostpassword', '', $atts['title'] );
		}

		return $this->render_auth_view( 'register', '', $atts['title'] );
	}

	/**
	 * Render the password recovery form.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_lostpassword( $atts ) {
		$atts = shortcode_atts(
			array(
				'title' => '',
			),
			$atts,
			'at8_mluc_lostpassword'
		);

		if ( is_user_logged_in() ) {
			return $this->render_logged_in_notice();
		}

		$view = $this->current_view( 'lostpassword' );

		if ( 'login' === $view ) {
			return $this->render_auth_view( 'login', '' );
		}

		if ( 'register' === $view ) {
			return $this->render_auth_view( 'register', '' );
		}

		return $this->render_auth_view( 'lostpassword', '', $atts['title'] );
	}

	/**
	 * Render the account dashboard.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_account( $atts ) {
		$atts = shortcode_atts(
			array(
				'tab' => '',
			),
			$atts,
			'at8_mluc_account'
		);

		if ( ! is_user_logged_in() ) {
			return $this->render_login_required_notice();
		}

		$tabs   = $this->get_tabs();
		$active = $this->current_tab( $tabs, $atts['tab'] );

		ob_start();

		echo '<div class="at8-mluc-account">';
		$this->render_nav( $tabs, $active );
		echo '<div class="at8-mluc-account-content">';

		if ( isset( $tabs[ $active ]['callback'] ) && is_callable( $tabs[ $active ]['callback'] ) ) {
			call_user_func( $tabs[ $active ]['callback'] );
		}

		echo '</div></div>';

		return ob_get_clean();
	}

	/**
	 * Register the dashboard tabs.
	 *
	 * @return array<string, array{title:string, icon:string, callback:callable}>
	 */
	public function get_tabs() {
		$tabs = array(
			'overview' => array(
				'title'    => at8_mluc_ui_label( 'tab_overview', __( 'Overview', 'at8-moonlight-user-center' ) ),
				'icon'     => 'dashicons-dashboard',
				'callback' => array( $this, 'tab_overview' ),
			),
			'profile'  => array(
				'title'    => at8_mluc_ui_label( 'tab_profile', __( 'Profile', 'at8-moonlight-user-center' ) ),
				'icon'     => 'dashicons-edit',
				'callback' => array( $this, 'tab_profile' ),
			),
			'avatar'   => array(
				'title'    => at8_mluc_ui_label( 'tab_avatar', __( 'Avatar', 'at8-moonlight-user-center' ) ),
				'icon'     => 'dashicons-format-image',
				'callback' => array( $this, 'tab_avatar' ),
			),
			'security' => array(
				'title'    => at8_mluc_ui_label( 'tab_security', __( 'Password', 'at8-moonlight-user-center' ) ),
				'icon'     => 'dashicons-lock',
				'callback' => array( $this, 'tab_security' ),
			),
		);

		if ( ! AT8_MLUC_Avatar::is_enabled() ) {
			unset( $tabs['avatar'] );
		}

		/**
		 * Filters the account dashboard tabs.
		 *
		 * @since 1.0.0
		 *
		 * @param array $tabs Tab definitions keyed by slug.
		 */
		return apply_filters( 'at8_mluc_account_tabs', $tabs );
	}

	/**
	 * Render the dashboard navigation.
	 *
	 * @param array  $tabs   Tab definitions.
	 * @param string $active Active tab slug.
	 * @return void
	 */
	private function render_nav( $tabs, $active ) {
		$base = at8_mluc_get_account_url();

		echo '<nav class="at8-mluc-account-nav" aria-label="' . esc_attr__( 'Account sections', 'at8-moonlight-user-center' ) . '">';
		echo '<ul>';

		foreach ( $tabs as $slug => $tab ) {
			$url     = add_query_arg( 'tab', $slug, $base );
			$current = ( $slug === $active ) ? ' aria-current="page"' : '';

			printf(
				'<li class="%1$s"><a href="%2$s"%3$s><span class="dashicons %4$s" aria-hidden="true"></span>%5$s</a></li>',
				esc_attr( 'at8-mluc-tab at8-mluc-tab-' . sanitize_html_class( $slug ) . ( $slug === $active ? ' is-active' : '' ) ),
				esc_url( $url ),
				$current, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute fragment built above.
				esc_attr( isset( $tab['icon'] ) ? $tab['icon'] : 'dashicons-admin-generic' ),
				esc_html( $tab['title'] )
			);
		}

		printf(
			'<li class="at8-mluc-tab at8-mluc-tab-logout"><a href="%1$s"><span class="dashicons dashicons-migrate" aria-hidden="true"></span>%2$s</a></li>',
			esc_url( self::get_logout_url() ),
			esc_html( at8_mluc_ui_label( 'tab_logout', __( 'Log Out', 'at8-moonlight-user-center' ) ) )
		);

		echo '</ul></nav>';
	}

	/**
	 * Overview tab.
	 *
	 * @return void
	 */
	public function tab_overview() {
		at8_mluc_get_template(
			'account-overview',
			array(
				'user'           => wp_get_current_user(),
				'comment_count'  => at8_mluc_count_user_comments( get_current_user_id() ),
				'avatar_url'     => get_avatar_url( get_current_user_id(), array( 'size' => 96 ) ),
				'logout_url'     => self::get_logout_url(),
				'profile_url'    => add_query_arg( 'tab', 'profile', at8_mluc_get_account_url() ),
			)
		);
	}

	/**
	 * Profile tab.
	 *
	 * @return void
	 */
	public function tab_profile() {
		at8_mluc_get_template(
			'account-profile',
			array(
				'user' => wp_get_current_user(),
			)
		);
	}

	/**
	 * Avatar tab.
	 *
	 * @return void
	 */
	public function tab_avatar() {
		$user_id = get_current_user_id();

		at8_mluc_get_template(
			'account-avatar',
			array(
				'user'          => wp_get_current_user(),
				'library'       => AT8_MLUC_Avatar::get_library(),
				'current_id'    => absint( get_user_meta( $user_id, AT8_MLUC_Avatar::META_ATTACHMENT, true ) ),
				'avatar_url'    => get_avatar_url( $user_id, array( 'size' => 96 ) ),
			)
		);
	}

	/**
	 * Password tab.
	 *
	 * @return void
	 */
	public function tab_security() {
		at8_mluc_get_template(
			'account-security',
			array(
				'user' => wp_get_current_user(),
			)
		);
	}

	/**
	 * Read the requested view from the query string.
	 *
	 * @param string $default Fallback view.
	 * @return string One of login, register, lostpassword.
	 */
	private function current_view( $default ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switcher: the value is sanitised and whitelisted below.
		$view = isset( $_GET['at8_mluc_view'] ) && is_string( $_GET['at8_mluc_view'] ) ? sanitize_key( wp_unslash( $_GET['at8_mluc_view'] ) ) : '';

		return in_array( $view, array( 'login', 'register', 'lostpassword' ), true ) ? $view : $default;
	}

	/**
	 * Read the requested dashboard tab.
	 *
	 * @param array  $tabs    Registered tabs.
	 * @param string $default Fallback tab slug.
	 * @return string
	 */
	private function current_tab( $tabs, $default ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switcher: the requested tab is matched against registered tabs only.
		$requested = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( '' !== $requested && isset( $tabs[ $requested ] ) ) {
			return $requested;
		}

		if ( '' !== $default && isset( $tabs[ $default ] ) ) {
			return $default;
		}

		$keys = array_keys( $tabs );

		return $keys ? (string) $keys[0] : '';
	}

	/**
	 * Render one of the authentication views.
	 *
	 * @param string $view        View slug.
	 * @param string $redirect_to Optional same-site redirect target.
	 * @param string $title       Optional heading override.
	 * @return string
	 */
	private function render_auth_view( $view, $redirect_to = '', $title = '' ) {
		ob_start();
		at8_mluc_get_template(
			$view,
			array(
				'view'        => $view,
				'redirect_to' => $redirect_to,
				'title'       => $title,
			)
		);

		return ob_get_clean();
	}

	/**
	 * Notice shown to a logged-in visitor on an authentication page.
	 *
	 * @return string
	 */
	private function render_logged_in_notice() {
		return sprintf(
			'<p class="at8-mluc-message">%s</p>',
			sprintf(
				/* translators: 1: account dashboard link, 2: log out link. */
				esc_html__( 'You are already logged in. Go to %1$s or %2$s.', 'at8-moonlight-user-center' ),
				'<a href="' . esc_url( at8_mluc_get_account_url() ) . '">' . esc_html__( 'your account', 'at8-moonlight-user-center' ) . '</a>',
				'<a href="' . esc_url( self::get_logout_url() ) . '">' . esc_html__( 'log out', 'at8-moonlight-user-center' ) . '</a>'
			)
		);
	}

	/**
	 * Notice shown to a logged-out visitor on the dashboard.
	 *
	 * @return string
	 */
	private function render_login_required_notice() {
		return sprintf(
			'<p class="at8-mluc-message">%s</p>',
			sprintf(
				/* translators: %s: login link. */
				esc_html__( 'Please %s to view your account.', 'at8-moonlight-user-center' ),
				'<a href="' . esc_url( at8_mluc_get_login_url() ) . '">' . esc_html__( 'log in', 'at8-moonlight-user-center' ) . '</a>'
			)
		);
	}
}
