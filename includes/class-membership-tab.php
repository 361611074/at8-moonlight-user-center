<?php
/**
 * Membership account tab.
 *
 * Adds a "Membership" tab to the account dashboard: the user's current level,
 * a level comparison table and the purchase card (levels, gateways, order
 * history). Add-ons extend the tab through the standard `at8_mluc_account_tabs`
 * filter and the `at8_mluc_membership_purchase` action.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Membership tab on the account dashboard.
 */
class AT8_MLUC_Membership_Tab {

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Membership_Tab|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Membership_Tab
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
		add_filter( 'at8_mluc_account_tabs', array( $this, 'register_tab' ) );
	}

	/**
	 * Add the membership tab.
	 *
	 * @param array $tabs Tab definitions keyed by slug.
	 * @return array
	 */
	public function register_tab( $tabs ) {
		$tabs['membership'] = array(
			'title'    => at8_mluc_ui_label( 'tab_membership', __( 'Membership', 'at8-moonlight-user-center' ) ),
			'callback' => array( $this, 'render_tab' ),
		);

		return $tabs;
	}

	/**
	 * Render the membership tab.
	 *
	 * @return void
	 */
	public function render_tab() {
		$user_id = get_current_user_id();

		at8_mluc_get_template(
			'account-membership',
			array(
				'user_id' => $user_id,
				'level'   => AT8_MLUC_Membership::get_user_level( $user_id ),
				'expires' => AT8_MLUC_Membership::get_user_expires( $user_id ),
				'expired' => AT8_MLUC_Membership::is_user_expired( $user_id ),
				'levels'  => AT8_MLUC_Membership::get_levels(),
			)
		);

		/**
		 * Fires inside the membership tab, after the level overview.
		 *
		 * The purchase card (levels, gateways, order history) renders here.
		 *
		 * @since 2.2.0
		 */
		do_action( 'at8_mluc_membership_purchase' );
	}
}
