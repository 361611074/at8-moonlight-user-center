<?php
/**
 * Paywall core.
 *
 * Sell access to a single post or page: set a price, pick the one membership
 * level that unlocks it, and the order engine does the rest. Members who
 * already hold that level never see the gate.
 *
 * This is deliberately the small core. It stores four meta fields, renders one
 * locked card and records one unlock per user. An add-on extends the same
 * fields with additional levels, resource types and drip schedules through
 * `at8_mluc_paywall_levels`, `at8_mluc_paywall_price`,
 * `at8_mluc_paywall_locked_html`, `at8_mluc_paywall_unlocked_html`,
 * `at8_mluc_paywall_meta_extra` and friends; it does not need its own
 * content filter.
 *
 * The core always runs. An add-on that still ships its own independent gate
 * (legacy builds) keys off different meta, so the two never double-gate the
 * same post: whichever gate recognises the post replaces the content.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single-post paywall.
 */
class AT8_MLUC_Paywall {

	/**
	 * Meta: paywall on.
	 *
	 * @var string
	 */
	const META_ENABLED = '_at8_mluc_pw_on';

	/**
	 * Meta: price.
	 *
	 * @var string
	 */
	const META_PRICE = '_at8_mluc_pw_price';

	/**
	 * Meta: required level slug.
	 *
	 * @var string
	 */
	const META_LEVEL = '_at8_mluc_pw_level';

	/**
	 * Meta: access length in days (0 = forever).
	 *
	 * @var string
	 */
	const META_DAYS = '_at8_mluc_pw_days';

	/**
	 * User meta: post id => expiry timestamp (0 = forever).
	 *
	 * @var string
	 */
	const UNLOCK_META = 'at8_mluc_pay_unlocks';

	/**
	 * Nonce action for the unlock button.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'at8_mluc_paywall_buy';

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Paywall|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Paywall
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
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta' ), 25 );
		add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
		add_filter( 'at8_mluc_complete_paywall_order', array( $this, 'grant_for_order' ), 10, 3 );
		add_action( 'wp_ajax_at8_mluc_paywall_buy', array( $this, 'ajax_buy' ) );
	}

	/**
	 * How many levels one paywall rule may name.
	 *
	 * The free core names exactly one; an add-on raises it.
	 *
	 * @return int
	 */
	public static function level_limit() {
		return max( 1, (int) apply_filters( 'at8_mluc_paywall_level_limit', 1 ) );
	}

	/**
	 * Whether a post is behind the paywall.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_enabled( $post_id ) {
		/**
		 * Filters whether the paywall covers a post.
		 *
		 * An add-on maps its own enable flag onto the core so posts configured
		 * before it existed keep their gate.
		 *
		 * @since 2.4.1
		 *
		 * @param bool $enabled Whether the core flag is set.
		 * @param int  $post_id Post ID.
		 */
		return (bool) apply_filters(
			'at8_mluc_paywall_enabled',
			(bool) get_post_meta( $post_id, self::META_ENABLED, true ),
			$post_id
		);
	}

	/**
	 * Price for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return float
	 */
	public static function price( $post_id ) {
		$price = (float) get_post_meta( $post_id, self::META_PRICE, true );

		/**
		 * Filters the price a visitor pays for a post.
		 *
		 * An add-on returns a member price for the visitor's level.
		 *
		 * @since 2.4.1
		 *
		 * @param float $price   Base price.
		 * @param int   $post_id Post ID.
		 */
		return (float) apply_filters( 'at8_mluc_paywall_price', $price, $post_id );
	}

	/**
	 * Levels that unlock a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, string>
	 */
	public static function levels( $post_id ) {
		$level = (string) get_post_meta( $post_id, self::META_LEVEL, true );
		$found = '' === $level ? array() : array( $level );

		/**
		 * Filters the membership levels that unlock a post.
		 *
		 * The core names at most one; an add-on returns several.
		 *
		 * @since 2.4.1
		 *
		 * @param array $levels  Level slugs.
		 * @param int   $post_id Post ID.
		 */
		return (array) apply_filters( 'at8_mluc_paywall_levels', $found, $post_id );
	}

	/**
	 * Access length in days.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function days( $post_id ) {
		$days = max( 0, (int) get_post_meta( $post_id, self::META_DAYS, true ) );

		/**
		 * Filters the access length in days.
		 *
		 * @since 2.4.1
		 *
		 * @param int $days    Access length in days (0 = forever).
		 * @param int $post_id Post ID.
		 */
		return max( 0, (int) apply_filters( 'at8_mluc_paywall_days', $days, $post_id ) );
	}

	/**
	 * Timestamp at which a fresh unlock expires (0 = forever).
	 *
	 * @param int $post_id  Post ID.
	 * @param int $user_id  User ID.
	 * @return int
	 */
	public static function unlock_expire( $post_id, $user_id = 0 ) {
		$days = self::days( $post_id );

		$expires = $days > 0 ? time() + ( $days * DAY_IN_SECONDS ) : 0;

		/**
		 * Filters the expiry timestamp of a fresh unlock.
		 *
		 * An add-on with finer units (hours, months) returns its own timestamp.
		 *
		 * @since 2.4.1
		 *
		 * @param int $expires Expiry timestamp (0 = forever).
		 * @param int $post_id Post ID.
		 * @param int $user_id User ID.
		 */
		return (int) apply_filters( 'at8_mluc_paywall_unlock_expire', $expires, $post_id, $user_id );
	}

	/**
	 * Whether a post is gated and the visitor has not paid for it.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_paywalled( $post_id ) {
		if ( ! self::is_enabled( $post_id ) || self::price( $post_id ) <= 0 ) {
			return false;
		}

		$user_id = get_current_user_id();

		foreach ( self::levels( $post_id ) as $level ) {
			if ( AT8_MLUC_Membership::user_can_access( $level, $user_id ) ) {
				return false;
			}
		}

		return ! self::is_unlocked( $post_id, $user_id );
	}

	/**
	 * Whether the visitor has an active unlock.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID (0 for the current user).
	 * @return bool
	 */
	public static function is_unlocked( $post_id, $user_id = 0 ) {
		$user_id = $user_id > 0 ? (int) $user_id : get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		$unlocks = get_user_meta( $user_id, self::UNLOCK_META, true );

		if ( ! is_array( $unlocks ) || ! isset( $unlocks[ $post_id ] ) ) {
			return false;
		}

		$expires = (int) $unlocks[ $post_id ];

		if ( $expires > 0 && $expires < time() ) {
			// Expired unlocks are dropped lazily; nothing else depends on them.
			unset( $unlocks[ $post_id ] );
			update_user_meta( $user_id, self::UNLOCK_META, $unlocks );

			return false;
		}

		return true;
	}

	/**
	 * Grant an unlock.
	 *
	 * @param int $post_id  Post ID.
	 * @param int $user_id  User ID.
	 * @param int $days     Access length in days (0 = forever).
	 * @return void
	 */
	public static function grant_unlock( $post_id, $user_id, $days = 0 ) {
		self::grant_unlock_ts( $post_id, $user_id, $days > 0 ? time() + ( $days * DAY_IN_SECONDS ) : 0 );
	}

	/**
	 * Grant an unlock that expires at a fixed timestamp.
	 *
	 * @param int $post_id  Post ID.
	 * @param int $user_id  User ID.
	 * @param int $expires  Expiry timestamp (0 = forever).
	 * @return void
	 */
	public static function grant_unlock_ts( $post_id, $user_id, $expires = 0 ) {
		$unlocks = get_user_meta( $user_id, self::UNLOCK_META, true );
		$unlocks = is_array( $unlocks ) ? $unlocks : array();

		$unlocks[ $post_id ] = max( 0, (int) $expires );

		update_user_meta( $user_id, self::UNLOCK_META, $unlocks );

		/**
		 * Fires after a post was unlocked for a user.
		 *
		 * @since 2.4.1
		 *
		 * @param int $post_id Post ID.
		 * @param int $user_id User ID.
		 * @param int $expires Expiry timestamp (0 = forever).
		 */
		do_action( 'at8_mluc_paywall_unlocked', $post_id, $user_id, max( 0, (int) $expires ) );
	}

	/**
	 * Deliver a paid paywall order.
	 *
	 * @param bool $handled  Whether another handler already delivered.
	 * @param int  $order_id Order ID.
	 * @param int  $user_id  User ID.
	 * @return bool
	 */
	public function grant_for_order( $handled, $order_id, $user_id ) {
		if ( $handled ) {
			return $handled;
		}

		if ( 'paywall' !== (string) get_post_meta( $order_id, '_at8_mluc_pay_type', true ) ) {
			return $handled;
		}

		$post_id = (int) get_post_meta( $order_id, '_at8_mluc_pay_post', true );

		if ( $post_id <= 0 ) {
			return $handled;
		}

		// Claim first: this is the only atomic pending → paid transition, so a
		// second confirmation finds nothing to update and delivers nothing.
		if ( ! AT8_MLUC_Payments::claim_order( $order_id ) ) {
			return $handled;
		}

		self::grant_unlock_ts( $post_id, $user_id, self::unlock_expire( $post_id, $user_id ) );

		return true;
	}

	/**
	 * Replace the content of a locked post with the paywall card.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content( $content ) {
		// Feeds carry no login state, so paid content is never served there.
		if ( is_feed() ) {
			$feed_post = (int) get_the_ID();

			if ( $feed_post > 0 && self::is_paywalled( $feed_post ) ) {
				return '';
			}

			return $content;
		}

		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();

		if ( ! $post_id || ! self::is_paywalled( $post_id ) ) {
			return $content;
		}

		if ( self::is_unlocked( $post_id ) ) {
			$extra = '';

			/**
			 * Filters the markup appended to an unlocked post.
			 *
			 * An add-on returns its resource blocks (downloads, galleries,
			 * players) here.
			 *
			 * @since 2.4.1
			 *
			 * @param string $extra   Markup (empty by default).
			 * @param int    $post_id Post ID.
			 */
			$extra = (string) apply_filters( 'at8_mluc_paywall_unlocked_html', $extra, $post_id );

			return '' === $extra ? $content : $content . $extra;
		}

		ob_start();
		at8_mluc_get_template(
			'paywall-locked',
			array(
				'post_id'  => $post_id,
				'price'    => self::price( $post_id ),
				'symbol'   => AT8_MLUC_Payments::get_currency_symbol(),
				'levels'   => self::levels( $post_id ),
				'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
				'loggedin' => is_user_logged_in(),
				'login_url' => at8_mluc_get_login_url(),
			)
		);
		$card = ob_get_clean();

		/**
		 * Filters the locked card markup.
		 *
		 * An add-on returns its own richer card and the core renders nothing.
		 *
		 * @since 2.4.1
		 *
		 * @param string $card    Core card markup.
		 * @param int    $post_id Post ID.
		 */
		$card = (string) apply_filters( 'at8_mluc_paywall_locked_html', $card, $post_id );

		/*
		 * Keep a short excerpt so search results and social cards stay
		 * meaningful; the teaser is capped in the template itself.
		 */
		$teaser = wp_trim_words( wp_strip_all_tags( $content ), 60, '…' );

		return '<p class="at8-mluc-paywall-teaser">' . esc_html( $teaser ) . '</p>' . $card;
	}

	/**
	 * AJAX: create an order for a paywalled post.
	 *
	 * @return void
	 */
	public function ajax_buy() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce', false );

		if ( ! is_user_logged_in() ) {
			at8_mluc_send_json( false, __( 'Please log in first to unlock this content.', 'at8-moonlight-user-center' ) );
		}

		$user_id = get_current_user_id();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( $post_id <= 0 || ! self::is_paywalled( $post_id ) ) {
			at8_mluc_send_json( false, __( 'This content is not available for purchase.', 'at8-moonlight-user-center' ) );
		}

		/**
		 * Filters whether the current user may buy this post at all.
		 *
		 * An add-on restricts purchases to a membership tier.
		 *
		 * @since 2.4.1
		 *
		 * @param bool $can_buy Whether the core allows the purchase.
		 * @param int  $post_id Post ID.
		 * @param int  $user_id User ID.
		 */
		$can_buy = (bool) apply_filters( 'at8_mluc_paywall_can_purchase', true, $post_id, $user_id );

		if ( ! $can_buy ) {
			at8_mluc_send_json( false, __( 'Your membership level does not include this purchase.', 'at8-moonlight-user-center' ) );
		}

		$price = self::price( $post_id );

		if ( $price <= 0 ) {
			at8_mluc_send_json( false, __( 'This content has no price set yet.', 'at8-moonlight-user-center' ) );
		}

		$gateway = isset( $_POST['gateway'] ) ? sanitize_key( wp_unslash( $_POST['gateway'] ) ) : '';
		$gateways = AT8_MLUC_Payments::get_gateways();

		if ( '' === $gateway || ! isset( $gateways[ $gateway ] ) ) {
			at8_mluc_send_json( false, __( 'Please choose a payment method.', 'at8-moonlight-user-center' ) );
		}

		$rate_key = 'at8_mluc_pw_rl_' . $user_id;
		$hits     = (int) get_transient( $rate_key );

		if ( $hits >= 10 ) {
			at8_mluc_send_json( false, __( 'Too many attempts in a short period. Please try again later.', 'at8-moonlight-user-center' ) );
		}

		set_transient( $rate_key, $hits + 1, 10 * MINUTE_IN_SECONDS );

		$order_id = wp_insert_post(
			array(
				'post_type'   => AT8_MLUC_Payments::CPT,
				'post_status' => 'publish',
				'post_title'  => sprintf( 'PW-%s-%d', gmdate( 'YmdHis' ), $post_id ),
				'post_author' => $user_id,
			)
		);

		if ( is_wp_error( $order_id ) || ! $order_id ) {
			at8_mluc_send_json( false, __( 'Failed to create the order. Please try again later.', 'at8-moonlight-user-center' ) );
		}

		update_post_meta( $order_id, '_at8_mluc_pay_user', $user_id );
		update_post_meta( $order_id, '_at8_mluc_pay_type', 'paywall' );
		update_post_meta( $order_id, '_at8_mluc_pay_post', $post_id );
		update_post_meta( $order_id, '_at8_mluc_pay_price', $price );
		update_post_meta( $order_id, '_at8_mluc_pay_gateway', $gateway );
		update_post_meta( $order_id, '_at8_mluc_pay_status', 'pending' );

		/**
		 * Fires after a paywall order was created.
		 *
		 * @since 2.4.0
		 *
		 * @param int $order_id Order ID.
		 * @param int $user_id  User ID.
		 * @param int $post_id  Post being unlocked.
		 */
		do_action( 'at8_mluc_paywall_order_created', $order_id, $user_id, $post_id );

		at8_mluc_send_json(
			true,
			__( 'Order created. Once the payment is confirmed the content unlocks.', 'at8-moonlight-user-center' ),
			array(
				'order_id'     => (int) $order_id,
				'instructions' => AT8_MLUC_Payments::get_instructions(),
			)
		);
	}

	/**
	 * Register the meta box.
	 *
	 * An add-on that ships its own, richer paywall box (modes, resources,
	 * per-level pricing) filters `at8_mluc_paywall_meta_box` to false and
	 * takes the box over; the core gate keeps reading whatever meta that box
	 * writes through the `at8_mluc_paywall_*` filters.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		/**
		 * Filters whether the core paywall meta box is registered.
		 *
		 * @since 2.4.1
		 *
		 * @param bool $register Whether to register the core box.
		 */
		if ( ! apply_filters( 'at8_mluc_paywall_meta_box', true ) ) {
			return;
		}

		foreach ( array( 'post', 'page' ) as $type ) {
			add_meta_box(
				'at8-mluc-paywall',
				__( 'Paywall', 'at8-moonlight-user-center' ),
				array( $this, 'render_meta_box' ),
				$type,
				'side'
			);
		}
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'at8_mluc_paywall_save', 'at8_mluc_paywall_nonce' );

		$on    = self::is_enabled( $post->ID );
		$price = self::price( $post->ID );
		$days  = self::days( $post->ID );
		$level = implode( ',', self::levels( $post->ID ) );
		$levels = AT8_MLUC_Membership::get_levels();
		?>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( self::META_ENABLED ); ?>" value="1" <?php checked( $on ); ?> />
				<?php esc_html_e( 'Sell access to this content', 'at8-moonlight-user-center' ); ?>
			</label>
		</p>

		<p>
			<label for="at8-mluc-pw-price"><?php esc_html_e( 'Price', 'at8-moonlight-user-center' ); ?></label>
			<input type="number" step="0.01" min="0" class="widefat" id="at8-mluc-pw-price"
				name="<?php echo esc_attr( self::META_PRICE ); ?>" value="<?php echo esc_attr( (string) $price ); ?>" />
		</p>

		<p>
			<label for="at8-mluc-pw-level"><?php esc_html_e( 'Unlocked by membership', 'at8-moonlight-user-center' ); ?></label>
			<select class="widefat" id="at8-mluc-pw-level" name="<?php echo esc_attr( self::META_LEVEL ); ?>">
				<option value=""><?php esc_html_e( '— Purchase only —', 'at8-moonlight-user-center' ); ?></option>
				<?php foreach ( array_keys( $levels ) as $key ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $level, $key ); ?>>
						<?php echo esc_html( AT8_MLUC_Membership::get_level_label( $key ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="description">
				<?php esc_html_e( 'Members of this level read it for free. Leave empty to sell it to everyone.', 'at8-moonlight-user-center' ); ?>
			</span>
		</p>

		<p>
			<label for="at8-mluc-pw-days"><?php esc_html_e( 'Access length (days)', 'at8-moonlight-user-center' ); ?></label>
			<input type="number" min="0" class="widefat" id="at8-mluc-pw-days"
				name="<?php echo esc_attr( self::META_DAYS ); ?>" value="<?php echo esc_attr( (string) $days ); ?>" />
			<span class="description"><?php esc_html_e( '0 keeps the access forever.', 'at8-moonlight-user-center' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Save the meta box.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save_meta( $post_id ) {
		if ( ! isset( $_POST['at8_mluc_paywall_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['at8_mluc_paywall_nonce'] ) ), 'at8_mluc_paywall_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$on = isset( $_POST[ self::META_ENABLED ] ) ? 1 : 0;

		if ( ! $on ) {
			delete_post_meta( $post_id, self::META_ENABLED );
			delete_post_meta( $post_id, self::META_PRICE );
			delete_post_meta( $post_id, self::META_LEVEL );
			delete_post_meta( $post_id, self::META_DAYS );

			return;
		}

		update_post_meta( $post_id, self::META_ENABLED, 1 );
		update_post_meta( $post_id, self::META_PRICE, isset( $_POST[ self::META_PRICE ] ) ? (float) wp_unslash( $_POST[ self::META_PRICE ] ) : 0 );

		$levels = AT8_MLUC_Membership::get_levels();
		$level  = isset( $_POST[ self::META_LEVEL ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_LEVEL ] ) ) : '';

		if ( '' !== $level && ! isset( $levels[ $level ] ) ) {
			$level = '';
		}

		if ( '' === $level ) {
			delete_post_meta( $post_id, self::META_LEVEL );
		} else {
			update_post_meta( $post_id, self::META_LEVEL, $level );
		}

		update_post_meta( $post_id, self::META_DAYS, isset( $_POST[ self::META_DAYS ] ) ? max( 0, (int) wp_unslash( $_POST[ self::META_DAYS ] ) ) : 0 );
	}
}
