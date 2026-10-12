<?php
/**
 * Menu login-state support.
 *
 * Two independent features, both opt-in per menu item:
 *
 * 1. Visibility: every menu item can be shown always, to logged-in users only,
 *    or to logged-out visitors only. This makes "Log in / Register" links
 *    disappear for members and "Account / Log out" links appear.
 * 2. Magic links: add a custom link whose URL is one of the placeholders below
 *    and the front end swaps in the real address:
 *    - #at8-mluc-login         login screen (returns to the current page)
 *    - #at8-mluc-register      registration screen
 *    - #at8-mluc-account       account dashboard
 *    - #at8-mluc-lostpassword  password recovery screen
 *    - #at8-mluc-logout        log out (returns to the current page)
 *
 * Works with every menu rendered through `wp_nav_menu_objects` (theme menus,
 * header builders, page-builder nav widgets, …).
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu item visibility and magic link replacement.
 */
class AT8_MLUC_Menu {

	/**
	 * Post meta key storing a menu item's visibility rule.
	 *
	 * @var string
	 */
	const META_KEY = '_at8_mluc_visibility';

	/**
	 * Nonce action protecting the visibility field on the menu editor screen.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'at8_mluc_menu_meta_save';

	/**
	 * Nonce field name used on the menu editor screen.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'at8_mluc_menu_meta_nonce';

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Menu|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Menu
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
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'render_admin_fields' ), 10, 5 );
		add_action( 'wp_update_nav_menu_item', array( $this, 'save_item_visibility' ), 10, 2 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_menu_objects' ), 20 );
	}

	/**
	 * Render the visibility radio group on the menu item editor.
	 *
	 * @param int    $item_id Menu item ID.
	 * @param object $item    Menu item object.
	 * @return void
	 */
	public function render_admin_fields( $item_id, $item ) { // phpcs:ignore Universal.CodeAnalysis.Commenting.MissingComment -- Core action signature.
		$visibility = get_post_meta( $item_id, self::META_KEY, true );

		if ( ! in_array( $visibility, array( 'logged_in', 'logged_out' ), true ) ) {
			$visibility = '';
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME, false );
		?>
		<p class="field-at8-mluc-visibility description description-wide">
			<strong><?php esc_html_e( 'User Center · Visibility', 'at8-moonlight-user-center' ); ?></strong><br>
			<label style="margin-right:12px;">
				<input type="radio" name="at8-mluc-menu-visibility[<?php echo (int) $item_id; ?>]" value="" <?php checked( '', $visibility ); ?>>
				<?php esc_html_e( 'Always show', 'at8-moonlight-user-center' ); ?>
			</label>
			<label style="margin-right:12px;">
				<input type="radio" name="at8-mluc-menu-visibility[<?php echo (int) $item_id; ?>]" value="logged_in" <?php checked( 'logged_in', $visibility ); ?>>
				<?php esc_html_e( 'Logged-in users only', 'at8-moonlight-user-center' ); ?>
			</label>
			<label>
				<input type="radio" name="at8-mluc-menu-visibility[<?php echo (int) $item_id; ?>]" value="logged_out" <?php checked( 'logged_out', $visibility ); ?>>
				<?php esc_html_e( 'Logged-out visitors only', 'at8-moonlight-user-center' ); ?>
			</label>
			<br>
			<span class="description">
				<?php esc_html_e( 'Note: hiding a parent item hides its whole submenu. Magic links: #at8-mluc-login / #at8-mluc-register / #at8-mluc-account / #at8-mluc-lostpassword / #at8-mluc-logout — put one of these in the URL of a custom link and the front end replaces it with the real address.', 'at8-moonlight-user-center' ); ?>
			</span>
		</p>
		<?php
	}

	/**
	 * Persist the visibility rule when a menu item is saved.
	 *
	 * @param int $menu_id        Menu ID.
	 * @param int $menu_item_db_id Menu item ID.
	 * @return void
	 */
	public function save_item_visibility( $menu_id, $menu_item_db_id ) { // phpcs:ignore Universal.CodeAnalysis.Commenting.MissingComment -- Core action signature.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		$map = isset( $_POST['at8-mluc-menu-visibility'] ) && is_array( $_POST['at8-mluc-menu-visibility'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['at8-mluc-menu-visibility'] ) ) : array();
		$val = isset( $map[ $menu_item_db_id ] ) ? (string) $map[ $menu_item_db_id ] : '';

		if ( in_array( $val, array( 'logged_in', 'logged_out' ), true ) ) {
			update_post_meta( $menu_item_db_id, self::META_KEY, $val );
		} else {
			delete_post_meta( $menu_item_db_id, self::META_KEY );
		}
	}

	/**
	 * Filter the front-end menu objects.
	 *
	 * Rules:
	 * - an item whose own visibility does not match the visitor is removed;
	 * - when any ancestor in the chain was removed, the whole subtree goes too
	 *   (orphan cleanup), so no parentless items are left behind.
	 *
	 * @param array $items Sorted menu item objects.
	 * @return array Filtered menu item objects.
	 */
	public function filter_menu_objects( $items ) {
		if ( is_admin() || ! is_array( $items ) ) {
			return $items;
		}

		$logged_in = is_user_logged_in();

		// Original IDs, used to tell a hidden parent apart from an item that
		// was already an orphan before this filter ran.
		$original_ids = array();

		foreach ( $items as $item ) {
			$original_ids[ (int) $item->ID ] = true;
		}

		$kept = array();

		foreach ( $items as $item ) {
			$visibility = get_post_meta( $item->ID, self::META_KEY, true );

			if ( ( 'logged_in' === $visibility && ! $logged_in ) || ( 'logged_out' === $visibility && $logged_in ) ) {
				continue;
			}

			if ( isset( $item->url ) ) {
				$item->url = $this->replace_magic_link( $item->url );
			}

			$kept[ (int) $item->ID ] = $item;
		}

		$output = array();

		foreach ( $kept as $item ) {
			$parent_id = (int) $item->menu_item_parent;
			$visible   = true;
			$guard     = 0;

			while ( $parent_id && isset( $original_ids[ $parent_id ] ) && $guard++ < 50 ) {
				if ( ! isset( $kept[ $parent_id ] ) ) {
					$visible = false;
					break;
				}

				$parent_id = (int) $kept[ $parent_id ]->menu_item_parent;
			}

			if ( $visible ) {
				$output[] = $item;
			}
		}

		return $output;
	}

	/**
	 * Replace a `#at8-mluc-*` placeholder with the real URL.
	 *
	 * @param string $url Menu item URL.
	 * @return string
	 */
	private function replace_magic_link( $url ) {
		if ( ! is_string( $url ) ) {
			return $url;
		}

		switch ( strtolower( trim( $url ) ) ) {
			case '#at8-mluc-login':
				return at8_mluc_get_login_url( $this->current_url() );
			case '#at8-mluc-register':
				return at8_mluc_get_register_url();
			case '#at8-mluc-account':
				return at8_mluc_get_account_url();
			case '#at8-mluc-lostpassword':
				return at8_mluc_get_lostpassword_url();
			case '#at8-mluc-logout':
				return wp_logout_url( $this->current_url() );
			default:
				return $url;
		}
	}

	/**
	 * Full URL of the current request, used as the login/logout return target.
	 *
	 * @return string
	 */
	private function current_url() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return home_url( $request_uri );
	}
}
