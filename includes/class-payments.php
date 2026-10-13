<?php
/**
 * Membership payments: orders and the offline (manual) gateway.
 *
 * - Gateway: the built-in "bank transfer, admin confirms" flow; further
 *   gateways register through the `at8_mluc_payment_gateways` filter.
 * - Orders: the private `at8_mluc_order` post type, status in the
 *   `_at8_mluc_pay_status` meta (pending / paid / cancelled).
 * - Fulfilment: an admin confirms payment on the order screen, which grants
 *   the membership level (upgrades only). Online gateways complete orders
 *   themselves through `AT8_MLUC_Payments::complete_order()`.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order lifecycle and payment gateways.
 */
class AT8_MLUC_Payments {

	/**
	 * Order post type.
	 *
	 * @var string
	 */
	const CPT = 'at8_mluc_order';

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Payments|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Payments
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
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'wp_ajax_at8_mluc_buy_level', array( $this, 'ajax_buy_level' ) );
		add_action( 'add_meta_boxes_' . self::CPT, array( $this, 'register_meta_box' ) );
		add_action( 'admin_post_at8_mluc_pay_confirm', array( $this, 'admin_confirm' ) );
		add_action( 'admin_post_at8_mluc_pay_cancel', array( $this, 'admin_cancel' ) );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( $this, 'list_columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( $this, 'render_list_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'list_row_actions' ), 10, 2 );
		add_action( 'at8_mluc_pay_autoclose', array( $this, 'autoclose_orders' ) );
		add_action( 'at8_mluc_membership_purchase', array( $this, 'render_order_history' ), 30 );
		add_filter( 'at8_mluc_payment_gateways', array( $this, 'register_gateways' ) );
	}

	/**
	 * Whether a level can be bought through the offline gateway.
	 *
	 * Registered as the `purchase` upgrade path: a level without a price has
	 * nothing to sell, so the path card skips it.
	 *
	 * @param array $level Level definition.
	 * @return bool
	 */
	public static function purchase_usable( $level ) {
		return isset( $level['price'] ) && (float) $level['price'] > 0;
	}

	/**
	 * Register the offline gateway.
	 *
	 * @param array $gateways Gateway id => title.
	 * @return array
	 */
	public function register_gateways( $gateways ) {
		if ( ! empty( at8_mluc_get_option( 'manual_enabled', 1 ) ) ) {
			$gateways['manual'] = at8_mluc_ui_label( 'buy_gateway_manual', __( 'Bank Transfer (admin confirms payment)', 'at8-moonlight-user-center' ) );
		}

		return $gateways;
	}

	/**
	 * Currency symbol shown in the purchase flow.
	 *
	 * @return string
	 */
	public static function get_currency_symbol() {
		$symbol = trim( (string) at8_mluc_get_option( 'pay_currency_symbol', '' ) );

		return '' !== $symbol ? $symbol : '$';
	}

	/**
	 * Transfer instructions shown after an offline order is created.
	 *
	 * @return string
	 */
	public static function get_instructions() {
		$default = at8_mluc_ui_label( 'buy_instructions_body', __( 'Please complete the transfer as instructed below. Your membership will be activated once the administrator confirms your payment.', 'at8-moonlight-user-center' ) );
		$saved   = trim( (string) at8_mluc_get_option( 'pay_manual_instructions', '' ) );

		return '' !== $saved ? $saved : $default;
	}

	/**
	 * Available gateways.
	 *
	 * Every gateway registered through the filter is offered; nothing is
	 * trimmed by a licence or a quota.
	 *
	 * @return array<string, string>
	 */
	public static function get_gateways() {
		/**
		 * Filters the available payment gateways.
		 *
		 * @since 2.2.0
		 *
		 * @param array $gateways Gateway id => title.
		 */
		$gateways = (array) apply_filters( 'at8_mluc_payment_gateways', array() );

		return $gateways;
	}

	/**
	 * ISO currency code used by online gateways.
	 *
	 * @return string
	 */
	public static function currency_code() {
		$code = strtoupper( trim( (string) at8_mluc_get_option( 'pay_currency_code', 'USD' ) ) );

		return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : 'USD';
	}

	/**
	 * Whether a currency has no decimal places (JPY, KRW, …).
	 *
	 * @param string $code Optional currency code, defaults to the configured one.
	 * @return bool
	 */
	public static function is_zero_decimal( $code = '' ) {
		$code = $code ? strtoupper( $code ) : self::currency_code();

		return in_array( $code, array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ), true );
	}

	/**
	 * Amount string for gateway APIs: 10.00 (integer for zero-decimal currencies).
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function api_amount( $amount ) {
		$amount = (float) $amount;

		return self::is_zero_decimal() ? (string) (int) round( $amount ) : number_format( $amount, 2, '.', '' );
	}

	/**
	 * Amount in the smallest currency unit: 1000 (as-is for zero-decimal currencies).
	 *
	 * @param float $amount Amount.
	 * @return int
	 */
	public static function api_amount_minor( $amount ) {
		$amount = (float) $amount;

		return self::is_zero_decimal() ? (int) round( $amount ) : (int) round( $amount * 100 );
	}

	/**
	 * Mark an order paid and fulfil it.
	 *
	 * Membership orders grant the level; paywall orders are handed to the
	 * add-on that registered the paywall type through the
	 * `at8_mluc_complete_paywall_order` filter.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $txn      Gateway transaction reference.
	 * @param string $gateway  Gateway that completed the payment.
	 * @return true|WP_Error
	 */
	public static function complete_order( $order_id, $txn = '', $gateway = '' ) {
		$order_id = (int) $order_id;

		if ( $order_id <= 0 || get_post_type( $order_id ) !== self::CPT ) {
			return new WP_Error( 'at8_mluc_bad_order', __( 'The order does not exist.', 'at8-moonlight-user-center' ) );
		}

		if ( 'pending' !== (string) get_post_meta( $order_id, '_at8_mluc_pay_status', true ) ) {
			return new WP_Error( 'at8_mluc_dup', __( 'The order was already completed or cancelled.', 'at8-moonlight-user-center' ) );
		}

		$user_id = (int) get_post_meta( $order_id, '_at8_mluc_pay_user', true );
		$level   = (string) get_post_meta( $order_id, '_at8_mluc_pay_level', true );
		$type    = (string) get_post_meta( $order_id, '_at8_mluc_pay_type', true );

		// Paywall orders carry no membership level. The claim lives inside the
		// delivery handler: whoever delivers also flips pending → paid, so two
		// concurrent confirmations can never both grant access and the order
		// can never be auto-closed after it was paid.
		if ( 'paywall' === $type ) {
			$handled = apply_filters( 'at8_mluc_complete_paywall_order', false, $order_id, $user_id );

			if ( ! $handled ) {
				return new WP_Error( 'at8_mluc_bad_pw', __( 'The paywall order is invalid.', 'at8-moonlight-user-center' ) );
			}

			self::mark_paid( $order_id, $txn, $gateway );

			/**
			 * Fires after a payment completed.
			 *
			 * @since 2.2.0
			 *
			 * @param int    $order_id Order ID.
			 * @param int    $user_id  User ID.
			 * @param string $subject  Level slug or "paywall:<post_id>".
			 */
			do_action( 'at8_mluc_payment_completed', $order_id, $user_id, 'paywall' );

			return true;
		}

		if ( ! $user_id || ! isset( AT8_MLUC_Membership::get_levels()[ $level ] ) ) {
			return new WP_Error( 'at8_mluc_bad_level', __( 'The membership level does not exist or the user is invalid.', 'at8-moonlight-user-center' ) );
		}

		if ( ! self::claim_order( $order_id ) ) {
			return new WP_Error( 'at8_mluc_dup', __( 'The order was already completed or cancelled.', 'at8-moonlight-user-center' ) );
		}

		if ( ! AT8_MLUC_Membership::get_instance()->grant_level( $user_id, $level ) ) {
			// Roll back so a retry remains possible.
			update_post_meta( $order_id, '_at8_mluc_pay_status', 'pending' );

			return new WP_Error( 'at8_mluc_grant', __( 'Grant failed: the user already holds this level or higher.', 'at8-moonlight-user-center' ) );
		}

		self::mark_paid( $order_id, $txn, $gateway );
		do_action( 'at8_mluc_payment_completed', $order_id, $user_id, $level );

		return true;
	}

	/**
	 * Atomically claim a pending order for completion.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public static function claim_order( $order_id ) {
		global $wpdb;

		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = 'paid' WHERE post_id = %d AND meta_key = '_at8_mluc_pay_status' AND meta_value = 'pending'",
				$order_id
			)
		);

		if ( $claimed ) {
			clean_post_cache( $order_id );

			return true;
		}

		return false;
	}

	/**
	 * Record the paid marker fields on a completed order.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $txn      Gateway transaction reference.
	 * @param string $gateway  Gateway that completed the payment.
	 * @return void
	 */
	private static function mark_paid( $order_id, $txn = '', $gateway = '' ) {
		update_post_meta( $order_id, '_at8_mluc_pay_granted', current_time( 'mysql' ) );

		if ( '' !== $txn ) {
			update_post_meta( $order_id, '_at8_mluc_pay_txn', $txn );
		}

		if ( '' !== $gateway ) {
			update_post_meta( $order_id, '_at8_mluc_pay_gateway_paid', $gateway );
		}
	}

	/**
	 * Register the order post type and keep the auto-close cron in sync.
	 *
	 * @return void
	 */
	public function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'               => __( 'Membership Orders', 'at8-moonlight-user-center' ),
					'singular_name'      => __( 'Membership Order', 'at8-moonlight-user-center' ),
					'menu_name'          => __( 'Membership Orders', 'at8-moonlight-user-center' ),
					'all_items'          => __( 'Membership Orders', 'at8-moonlight-user-center' ),
					'add_new'            => __( 'New Order', 'at8-moonlight-user-center' ),
					'add_new_item'       => __( 'New Membership Order', 'at8-moonlight-user-center' ),
					'new_item'           => __( 'New Membership Order', 'at8-moonlight-user-center' ),
					'edit_item'          => __( 'Edit Membership Order', 'at8-moonlight-user-center' ),
					'view_item'          => __( 'View Membership Order', 'at8-moonlight-user-center' ),
					'search_items'       => __( 'Search Membership Orders', 'at8-moonlight-user-center' ),
					'not_found'          => __( 'No orders yet', 'at8-moonlight-user-center' ),
					'not_found_in_trash' => __( 'No orders in the trash', 'at8-moonlight-user-center' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => class_exists( 'AT8_MLUC_Settings' ) ? AT8_MLUC_Settings::PAGE : true,
				'show_in_rest'    => false,
				'menu_icon'       => 'dashicons-tickets-alt',
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);

		$hours = (int) at8_mluc_get_option( 'order_auto_close_hours', 72 );

		if ( $hours > 0 && ! wp_next_scheduled( 'at8_mluc_pay_autoclose' ) ) {
			wp_schedule_event( time() + 3600, 'hourly', 'at8_mluc_pay_autoclose' );
		} elseif ( $hours <= 0 && wp_next_scheduled( 'at8_mluc_pay_autoclose' ) ) {
			wp_clear_scheduled_hook( 'at8_mluc_pay_autoclose' );
		}
	}

	/**
	 * Close stale pending orders.
	 *
	 * Only orders that are still pending and older than the configured
	 * threshold are cancelled; paid orders and orders an online gateway is
	 * currently processing are never touched (keep the threshold at 24h or
	 * more so it covers the longest checkout session).
	 *
	 * @return void
	 */
	public function autoclose_orders() {
		$hours = (int) at8_mluc_get_option( 'order_auto_close_hours', 72 );

		if ( $hours <= 0 ) {
			return;
		}

		$order_ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array(
					array(
						'column' => 'post_date_gmt',
						'before' => gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ),
					),
				),
				'meta_query'     => array(
					array(
						'key'   => '_at8_mluc_pay_status',
						'value' => 'pending',
					),
				),
			)
		);

		foreach ( $order_ids as $order_id ) {
			global $wpdb;

			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_value = 'cancelled' WHERE post_id = %d AND meta_key = '_at8_mluc_pay_status' AND meta_value = 'pending'",
					$order_id
				)
			);

			if ( $claimed ) {
				clean_post_cache( $order_id );
				update_post_meta( $order_id, '_at8_mluc_pay_autoclosed', current_time( 'mysql' ) );

				/**
				 * Fires after an order was auto-closed.
				 *
				 * @since 2.2.0
				 *
				 * @param int $order_id Order ID.
				 */
				do_action( 'at8_mluc_order_autoclosed', (int) $order_id );
			}
		}
	}

	/**
	 * A user's own orders, newest first.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_user_orders( $user_id, $limit = 5 ) {
		$order_ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => (int) $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_at8_mluc_pay_user',
						'value'   => (int) $user_id,
						'compare' => '=',
					),
				),
			)
		);

		$orders = array();

		foreach ( $order_ids as $order_id ) {
			$orders[] = array(
				'id'     => (int) $order_id,
				'title'  => get_the_title( $order_id ),
				'level'  => (string) get_post_meta( $order_id, '_at8_mluc_pay_level', true ),
				'price'  => (float) get_post_meta( $order_id, '_at8_mluc_pay_price', true ),
				'status' => (string) get_post_meta( $order_id, '_at8_mluc_pay_status', true ),
				'date'   => get_the_date( '', $order_id ),
			);
		}

		return $orders;
	}

	/**
	 * Render the purchase card as the `purchase` upgrade path.
	 *
	 * The path registry passes only the levels this member can still move up
	 * to; a direct call without levels falls back to the full catalogue.
	 *
	 * @param array $levels  Levels this path can unlock.
	 * @param int   $user_id Current user ID.
	 * @return void
	 */
	public static function render_purchase_path( $levels = array(), $user_id = 0 ) {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html( at8_mluc_ui_label( 'purchases_login', __( 'Please log in first to view your purchased content.', 'at8-moonlight-user-center' ) ) ) . '</p>';
			return;
		}

		$user_id = $user_id > 0 ? (int) $user_id : get_current_user_id();
		$levels  = is_array( $levels ) && $levels ? $levels : AT8_MLUC_Membership::get_levels();

		// Read-only notice code echoed back after a payment attempt; no state changes.
		$notice = isset( $_GET['at8_mluc_pay_notice'] ) ? sanitize_key( wp_unslash( $_GET['at8_mluc_pay_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

		$args = array(
			'levels'       => $levels,
			'gateways'     => self::get_gateways(),
			'symbol'       => self::get_currency_symbol(),
			'instructions' => self::get_instructions(),
			'nonce'        => wp_create_nonce( 'at8_mluc_nonce' ),
			'pay_notice'   => $notice,
		);

		/**
		 * Filters the template arguments before the template is rendered.
		 *
		 * Evaluated here (the same filter the template loader applies) so the
		 * purchase script and the gateway SDK can be enqueued conditionally.
		 *
		 * @since 2.2.0
		 *
		 * @param array  $args Template arguments.
		 * @param string $slug Template slug.
		 */
		$args = (array) apply_filters( 'at8_mluc_template_args', $args, 'membership-purchase' );

		self::enqueue_purchase_script( $args );

		at8_mluc_get_template( 'membership-purchase', $args );
	}

	/**
	 * Enqueue and configure the purchase script.
	 *
	 * Every card that posts to `at8_mluc_buy_level` (the purchase path, an
	 * add-on's upgrade ladder) calls this so the script, its nonce and the
	 * PayPal strings are present exactly once per page.
	 *
	 * @param array $args Template arguments (nonce, PayPal flags).
	 * @return void
	 */
	public static function enqueue_purchase_script( $args = array() ) {
		wp_enqueue_script(
			'at8-mluc-purchase',
			AT8_MLUC_PLUGIN_URL . 'assets/js/at8-mluc-purchase.js',
			array(),
			AT8_MLUC_VERSION,
			true
		);

		wp_localize_script(
			'at8-mluc-purchase',
			'at8MlucPurchase',
			array(
				'ajaxUrl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'nonce'   => isset( $args['nonce'] ) ? (string) $args['nonce'] : '',
				'strings' => array(
					'paypalLoad'  => __( 'Failed to load PayPal. Please refresh the page and try again.', 'at8-moonlight-user-center' ),
					'confirming'  => __( 'Confirming payment...', 'at8-moonlight-user-center' ),
					'paypalError' => __( 'PayPal payment error. Please try again or contact the administrator.', 'at8-moonlight-user-center' ),
					'paypalDo'    => __( 'Please complete the PayPal payment below.', 'at8-moonlight-user-center' ),
					'network'     => __( 'Network error. Please try again later.', 'at8-moonlight-user-center' ),
				),
			)
		);

		if ( ! empty( $args['paypal_on'] ) && ! empty( $args['paypal_sdk'] ) ) {
			/*
			 * Third-party SDK: the query string carries PayPal's own version,
			 * so a plugin version here would only lie about the asset.
			 */
			wp_enqueue_script( // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- versioned by PayPal in the URL.
				'at8-mluc-paypal-sdk',
				(string) $args['paypal_sdk'],
				array(),
				null,
				true
			);
		}
	}

	/**
	 * Render the member's order history below the upgrade paths.
	 *
	 * @return void
	 */
	public function render_order_history() {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html( at8_mluc_ui_label( 'purchases_login', __( 'Please log in first to view your purchased content.', 'at8-moonlight-user-center' ) ) ) . '</p>';
			return;
		}

		at8_mluc_get_template(
			'membership-orders',
			array(
				'orders' => self::get_user_orders( get_current_user_id(), 5 ),
				'symbol' => self::get_currency_symbol(),
			)
		);
	}

	/**
	 * Create an order from the front end.
	 *
	 * @return void
	 */
	public function ajax_buy_level() {
		check_ajax_referer( 'at8_mluc_nonce', 'nonce', false );

		if ( ! is_user_logged_in() ) {
			at8_mluc_send_json( false, at8_mluc_ui_label( 'buy_login_required', __( 'Please log in first.', 'at8-moonlight-user-center' ) ) );
		}

		$user_id = get_current_user_id();
		$level   = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : '';
		$gateway = isset( $_POST['gateway'] ) ? sanitize_key( wp_unslash( $_POST['gateway'] ) ) : '';

		$levels = AT8_MLUC_Membership::get_levels();

		if ( '' === $level || 'free' === $level || ! isset( $levels[ $level ] ) ) {
			at8_mluc_send_json( false, at8_mluc_ui_label( 'buy_invalid_level', __( 'Invalid membership level.', 'at8-moonlight-user-center' ) ) );
		}

		$price = AT8_MLUC_Membership::get_level_price( $level );

		/**
		 * Filters the price a membership order is created at.
		 *
		 * An add-on returns a differential (upgrade) price when the request
		 * carries the upgrade marker, crediting what the member already paid
		 * for a lower level.
		 *
		 * @since 2.4.1
		 *
		 * @param float  $price   List price of the level.
		 * @param string $level   Level slug.
		 * @param int    $user_id Buyer.
		 * @param bool   $upgrade Whether the request is a paid upgrade.
		 */
		$price = (float) apply_filters( 'at8_mluc_order_price', $price, $level, $user_id, ! empty( $_POST['upgrade'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag, nonce already checked above.

		if ( $price <= 0 ) {
			at8_mluc_send_json( false, at8_mluc_ui_label( 'buy_no_price', __( 'This level has no price set and cannot be purchased yet.', 'at8-moonlight-user-center' ) ) );
		}

		// Ordering a level the user already holds could never be fulfilled.
		$current = AT8_MLUC_Membership::get_user_level( $user_id );

		if ( AT8_MLUC_Membership::get_level_sort_order( $current ) >= AT8_MLUC_Membership::get_level_sort_order( $level ) ) {
			at8_mluc_send_json( false, at8_mluc_ui_label( 'buy_level_too_low', __( 'Your current membership level is not lower than the selected one. No need to purchase.', 'at8-moonlight-user-center' ) ) );
		}

		$gateways = self::get_gateways();

		if ( '' === $gateway || ! isset( $gateways[ $gateway ] ) ) {
			at8_mluc_send_json( false, at8_mluc_ui_label( 'buy_invalid_gateway', __( 'Invalid payment method.', 'at8-moonlight-user-center' ) ) );
		}

		// Rate limit: at most 10 orders per user per 10 minutes.
		$rate_key = 'at8_mluc_buy_rl_' . $user_id;
		$hits     = (int) get_transient( $rate_key );

		if ( $hits >= 10 ) {
			at8_mluc_send_json( false, at8_mluc_ui_label( 'buy_too_many', __( 'Too many orders in a short period. Please try again later.', 'at8-moonlight-user-center' ) ) );
		}

		set_transient( $rate_key, $hits + 1, 10 * MINUTE_IN_SECONDS );

		$order_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => sprintf( 'MB-%s-%s', gmdate( 'YmdHis' ), $levels[ $level ]['label'] ),
				'post_author' => $user_id,
			)
		);

		if ( is_wp_error( $order_id ) || ! $order_id ) {
			at8_mluc_send_json( false, at8_mluc_ui_label( 'buy_order_failed', __( 'Failed to create the order. Please try again later.', 'at8-moonlight-user-center' ) ) );
		}

		update_post_meta( $order_id, '_at8_mluc_pay_user', $user_id );
		update_post_meta( $order_id, '_at8_mluc_pay_level', $level );
		update_post_meta( $order_id, '_at8_mluc_pay_price', $price );
		update_post_meta( $order_id, '_at8_mluc_pay_gateway', $gateway );
		update_post_meta( $order_id, '_at8_mluc_pay_status', 'pending' );

		/**
		 * Fires after a membership order was created.
		 *
		 * @since 2.2.0
		 *
		 * @param int    $order_id Order ID.
		 * @param int    $user_id  User ID.
		 * @param string $level    Level slug.
		 * @param string $gateway  Gateway slug.
		 */
		do_action( 'at8_mluc_payment_order_created', $order_id, $user_id, $level, $gateway );

		$response = array(
			'success' => true,
			'message' => at8_mluc_ui_label( 'buy_order_manual', __( 'Order created. Please complete the transfer as instructed. Your membership will be activated once the administrator confirms your payment.', 'at8-moonlight-user-center' ) ),
			'data'    => array(
				'order_id'      => (int) $order_id,
				'flow'          => 'manual',
				'instructions'  => self::get_instructions(),
			),
		);

		/**
		 * Filters the response of a newly created order.
		 *
		 * Online gateways replace the default manual response with their own
		 * flow marker (for example a redirect URL).
		 *
		 * @since 2.2.0
		 *
		 * @param array  $response  Response payload.
		 * @param int    $order_id  Order ID.
		 * @param string $gateway   Gateway slug.
		 * @param string $level     Level slug.
		 */
		$response = apply_filters( 'at8_mluc_payment_order_response', $response, $order_id, $gateway, $level );

		if ( ! empty( $response['success'] ) && ! empty( $response['data']['redirect'] ) && 'manual' === $response['data']['flow'] ) {
			$response['data']['flow'] = 'redirect';
		}

		at8_mluc_send_json( ! empty( $response['success'] ), (string) ( $response['message'] ?? '' ), (array) ( $response['data'] ?? array() ) );
	}

	/**
	 * Human-readable payment status labels.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels() {
		return array(
			'pending'   => __( 'Pending', 'at8-moonlight-user-center' ),
			'paid'      => __( 'Paid', 'at8-moonlight-user-center' ),
			'cancelled' => __( 'Cancelled', 'at8-moonlight-user-center' ),
		);
	}

	/**
	 * Order list columns.
	 *
	 * @param array<string, string> $columns Default columns.
	 * @return array<string, string>
	 */
	public function list_columns( $columns ) {
		$columns = is_array( $columns ) ? $columns : array();

		// Rebuild around the columns that carry meaning for an order book.
		$built = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				continue;
			}

			$built[ $key ] = $label;
		}

		$built['at8_mluc_status']  = __( 'Status', 'at8-moonlight-user-center' );
		$built['at8_mluc_customer'] = __( 'Member', 'at8-moonlight-user-center' );
		$built['at8_mluc_subject']  = __( 'Item', 'at8-moonlight-user-center' );
		$built['at8_mluc_gateway']  = __( 'Gateway', 'at8-moonlight-user-center' );
		$built['at8_mluc_amount']   = __( 'Amount', 'at8-moonlight-user-center' );
		$built['date']              = __( 'Date', 'at8-moonlight-user-center' );

		return $built;
	}

	/**
	 * Render a custom order list cell.
	 *
	 * @param string  $column Column key.
	 * @param int     $post_id Order post ID.
	 * @return void
	 */
	public function render_list_column( $column, $post_id ) {
		switch ( $column ) {
			case 'at8_mluc_status':
				$status = (string) get_post_meta( $post_id, '_at8_mluc_pay_status', true );
				$status = '' !== $status ? $status : 'pending';
				$labels = self::status_labels();

				printf(
					'<span class="at8-mluc-order-status at8-mluc-order-status-%1$s">%2$s</span>',
					esc_attr( sanitize_html_class( $status ) ),
					esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status )
				);
				break;

			case 'at8_mluc_customer':
				$user_id = (int) get_post_meta( $post_id, '_at8_mluc_pay_user', true );
				$user    = $user_id ? get_user_by( 'id', $user_id ) : false;

				if ( ! $user ) {
					echo '—';
					break;
				}

				$link = current_user_can( 'edit_users' ) ? get_edit_user_link( $user_id ) : '';

				echo $link
					? '<a href="' . esc_url( $link ) . '">' . esc_html( $user->display_name ) . '</a>'
					: esc_html( $user->display_name );
				break;

			case 'at8_mluc_subject':
				if ( 'paywall' === (string) get_post_meta( $post_id, '_at8_mluc_pay_type', true ) ) {
					$target = (int) get_post_meta( $post_id, '_at8_mluc_pay_post', true );

					echo $target
						? '<a href="' . esc_url( (string) get_edit_post_link( $target ) ) . '">' . esc_html( get_the_title( $target ) ) . '</a>'
						: '—';

					break;
				}

				$level = (string) get_post_meta( $post_id, '_at8_mluc_pay_level', true );

				echo esc_html( '' !== $level ? AT8_MLUC_Membership::get_level_label( $level ) : '—' );
				break;

			case 'at8_mluc_gateway':
				$gateway  = (string) get_post_meta( $post_id, '_at8_mluc_pay_gateway', true );
				$gateways = self::get_gateways();

				echo esc_html( isset( $gateways[ $gateway ] ) ? $gateways[ $gateway ] : ( '' !== $gateway ? $gateway : '—' ) );
				break;

			case 'at8_mluc_amount':
				echo esc_html( self::get_currency_symbol() . number_format( (float) get_post_meta( $post_id, '_at8_mluc_pay_price', true ), 2 ) );
				break;
		}
	}

	/**
	 * Add confirm / cancel actions to pending orders.
	 *
	 * Both handlers re-check the capability and the nonce, so exposing them
	 * as row links adds no new attack surface.
	 *
	 * @param array<string, string> $actions Existing row actions.
	 * @param WP_Post               $post    Order post.
	 * @return array<string, string>
	 */
	public function list_row_actions( $actions, $post ) {
		$actions = is_array( $actions ) ? $actions : array();

		if ( self::CPT !== get_post_type( $post ) || ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		if ( 'pending' !== (string) get_post_meta( $post->ID, '_at8_mluc_pay_status', true ) ) {
			return $actions;
		}

		$actions['at8_mluc_confirm'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action'   => 'at8_mluc_pay_confirm',
							'order_id' => $post->ID,
						),
						admin_url( 'admin-post.php' )
					),
					'at8_mluc_pay_confirm'
				)
			),
			esc_attr(
				sprintf(
					/* translators: %d: order ID. */
					__( 'Confirm payment for order %d', 'at8-moonlight-user-center' ),
					$post->ID
				)
			),
			esc_html__( 'Confirm payment', 'at8-moonlight-user-center' )
		);

		$actions['at8_mluc_cancel'] = sprintf(
			'<a href="%1$s" class="submitdelete" aria-label="%2$s">%3$s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action'   => 'at8_mluc_pay_cancel',
							'order_id' => $post->ID,
						),
						admin_url( 'admin-post.php' )
					),
					'at8_mluc_pay_cancel'
				)
			),
			esc_attr(
				sprintf(
					/* translators: %d: order ID. */
					__( 'Cancel order %d', 'at8-moonlight-user-center' ),
					$post->ID
				)
			),
			esc_html__( 'Cancel', 'at8-moonlight-user-center' )
		);

		return $actions;
	}

	/**
	 * Register the order meta box.
	 *
	 * @param WP_Post $post Order post.
	 * @return void
	 */
	public function register_meta_box( $post ) {
		add_meta_box( 'at8_mluc_pay_meta', __( 'Payment Details', 'at8-moonlight-user-center' ), array( $this, 'render_meta_box' ), self::CPT, 'normal', 'high' );
	}

	/**
	 * Render the order meta box.
	 *
	 * @param WP_Post $post Order post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'at8_mluc_pay_meta', 'at8_mluc_pay_meta_nonce' );

		$user_id  = (int) get_post_meta( $post->ID, '_at8_mluc_pay_user', true );
		$level    = (string) get_post_meta( $post->ID, '_at8_mluc_pay_level', true );
		$price    = (float) get_post_meta( $post->ID, '_at8_mluc_pay_price', true );
		$gateway  = (string) get_post_meta( $post->ID, '_at8_mluc_pay_gateway', true );
		$status   = (string) get_post_meta( $post->ID, '_at8_mluc_pay_status', true );
		$granted  = (string) get_post_meta( $post->ID, '_at8_mluc_pay_granted', true );
		$pay_type = (string) get_post_meta( $post->ID, '_at8_mluc_pay_type', true );

		$user      = $user_id ? get_user_by( 'id', $user_id ) : false;
		$gateways  = self::get_gateways();
		$is_paywall = ( 'paywall' === $pay_type );

		$status_labels = self::status_labels();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'User', 'at8-moonlight-user-center' ); ?></th>
				<td><?php echo $user ? esc_html( $user->user_login . ' (#' . $user->ID . ')' ) : '—'; ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Subject', 'at8-moonlight-user-center' ); ?></th>
				<td>
					<?php
					if ( $is_paywall ) {
						$post_id = (int) get_post_meta( $post->ID, '_at8_mluc_pay_post', true );

						echo $post_id
							? '<a href="' . esc_url( (string) get_edit_post_link( $post_id ) ) . '">' . esc_html( get_the_title( $post_id ) . ' (#' . $post_id . ')' ) . '</a>'
							: '—';
					} else {
						echo esc_html( '' !== $level ? AT8_MLUC_Membership::get_level_label( $level ) . ' (' . $level . ')' : '—' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Amount', 'at8-moonlight-user-center' ); ?></th>
				<td><?php echo esc_html( self::get_currency_symbol() . number_format( $price, 2 ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Gateway', 'at8-moonlight-user-center' ); ?></th>
				<td><?php echo esc_html( isset( $gateways[ $gateway ] ) ? $gateways[ $gateway ] : $gateway ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Status', 'at8-moonlight-user-center' ); ?></th>
				<td>
					<?php echo esc_html( isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status ); ?>
					<?php if ( $granted ) : ?>｜<?php esc_html_e( 'Completed at', 'at8-moonlight-user-center' ); ?> <?php echo esc_html( $granted ); ?><?php endif; ?>
				</td>
			</tr>
		</table>
		<?php if ( 'pending' === $status ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
				<?php wp_nonce_field( 'at8_mluc_pay_confirm' ); ?>
				<input type="hidden" name="action" value="at8_mluc_pay_confirm">
				<input type="hidden" name="order_id" value="<?php echo (int) $post->ID; ?>">
				<button type="submit" class="button button-primary">
					<?php
					echo $is_paywall
						? esc_html__( 'Confirm payment and unlock content', 'at8-moonlight-user-center' )
						: esc_html__( 'Confirm payment and grant membership', 'at8-moonlight-user-center' );
					?>
				</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
				<?php wp_nonce_field( 'at8_mluc_pay_cancel' ); ?>
				<input type="hidden" name="action" value="at8_mluc_pay_cancel">
				<input type="hidden" name="order_id" value="<?php echo (int) $post->ID; ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Cancel order', 'at8-moonlight-user-center' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Confirm payment from the order screen.
	 *
	 * @return void
	 */
	public function admin_confirm() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'at8_mluc_pay_confirm' ) ) {
			wp_die( esc_html__( 'Insufficient permissions or verification failed.', 'at8-moonlight-user-center' ), '', array( 'response' => 403 ) );
		}

		$order_id = isset( $_REQUEST['order_id'] ) ? (int) $_REQUEST['order_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() above.
		$redirect = wp_get_referer() ?: admin_url( 'post.php?post=' . $order_id . '&action=edit' );

		if ( $order_id && get_post_type( $order_id ) === self::CPT
			&& 'pending' === get_post_meta( $order_id, '_at8_mluc_pay_status', true ) ) {
			$result = self::complete_order( $order_id );

			set_transient(
				'at8_mluc_admin_notice_' . get_current_user_id(),
				array(
					'success' => ! is_wp_error( $result ),
					'message' => is_wp_error( $result )
						? $result->get_error_message()
						: __( 'Payment confirmed and membership granted.', 'at8-moonlight-user-center' ),
				),
				60
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Cancel an order from the order screen.
	 *
	 * @return void
	 */
	public function admin_cancel() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'at8_mluc_pay_cancel' ) ) {
			wp_die( esc_html__( 'Insufficient permissions or verification failed.', 'at8-moonlight-user-center' ), '', array( 'response' => 403 ) );
		}

		$order_id = isset( $_REQUEST['order_id'] ) ? (int) $_REQUEST['order_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() above.
		$redirect = wp_get_referer() ?: admin_url( 'edit.php?post_type=' . self::CPT );

		if ( $order_id && get_post_type( $order_id ) === self::CPT ) {
			update_post_meta( $order_id, '_at8_mluc_pay_status', 'cancelled' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
