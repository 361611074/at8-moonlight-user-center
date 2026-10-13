<?php
/**
 * Membership upgrade paths.
 *
 * The free plugin registers exactly one way to move a member to another level:
 * spending points. Everything else — paying with a card, buying a tier ladder,
 * bulk seats — belongs to the add-on, which registers itself through the
 * `at8_mluc_upgrade_paths` filter without touching this class.
 *
 * Each path is a self-contained card: a label, whether it is usable for a given
 * level, and a render callback. The membership tab draws one card per path, so
 * adding a path adds no markup anywhere else.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Upgrade path registry and the points redemption path.
 */
class AT8_MLUC_Upgrade_Paths {

	/**
	 * AJAX action for redeeming a level with points.
	 *
	 * @var string
	 */
	const REDEEM_ACTION = 'at8_mluc_redeem_level';

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Upgrade_Paths|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Upgrade_Paths
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
		add_action( 'wp_ajax_' . self::REDEEM_ACTION, array( $this, 'ajax_redeem' ) );
		add_action( 'at8_mluc_membership_purchase', array( $this, 'render_paths' ), 20 );
	}

	/**
	 * Registered upgrade paths.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function paths() {
		$paths = array(
			'points' => array(
				'label'    => __( 'Redeem with points', 'at8-moonlight-user-center' ),
				'usable'   => array( __CLASS__, 'points_usable' ),
				'render'   => array( __CLASS__, 'render_points_card' ),
				'supports' => array( __CLASS__, 'points_usable' ),
			),
		);

		/**
		 * Filters the ways a member can move to another membership level.
		 *
		 * The free plugin registers the points path only. An add-on registers
		 * the remaining paths (card payment, tier ladders, bulk seats) here.
		 *
		 * @since 2.4.0
		 *
		 * @param array $paths Path id => definition.
		 */
		return (array) apply_filters( 'at8_mluc_upgrade_paths', $paths );
	}

	/**
	 * Whether the points path applies to a level.
	 *
	 * @param array $level Level definition.
	 * @return bool
	 */
	public static function points_usable( $level ) {
		return ! empty( $level['points'] ) && (int) $level['points'] > 0;
	}

	/**
	 * Render every registered path for the current member.
	 *
	 * @return void
	 */
	public function render_paths() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		$paths   = self::paths();
		$current = AT8_MLUC_Membership::get_user_level( $user_id );

		$cards = array();

		foreach ( $paths as $id => $path ) {
			$levels = AT8_MLUC_Membership::get_levels();

			foreach ( $levels as $key => $level ) {
				if ( $key === $current || 'free' === $key ) {
					continue;
				}

				if ( AT8_MLUC_Membership::get_level_sort_order( $key ) <= AT8_MLUC_Membership::get_level_sort_order( $current ) ) {
					continue;
				}

				if ( ! empty( $path['usable'] ) && is_callable( $path['usable'] ) && ! call_user_func( $path['usable'], $level ) ) {
					continue;
				}

				$cards[ $id ][ $key ] = $level;
			}
		}

		/**
		 * Fires before the upgrade path cards render.
		 *
		 * @since 2.4.0
		 *
		 * @param array $cards  Level cards per path.
		 * @param int   $user_id Current user ID.
		 */
		do_action( 'at8_mluc_before_upgrade_paths', $cards, $user_id );

		if ( empty( $cards ) ) {
			return;
		}

		echo '<section class="at8-mluc-card at8-mluc-upgrade-paths">';

		foreach ( $paths as $id => $path ) {
			if ( empty( $cards[ $id ] ) || ! is_callable( $path['render'] ) ) {
				continue;
			}

			printf(
				'<h3 class="at8-mluc-card-title">%s</h3>',
				esc_html( isset( $path['label'] ) ? $path['label'] : $id )
			);

			call_user_func( $path['render'], $cards[ $id ], $user_id );
		}

		echo '</section>';
	}

	/**
	 * Render the points redemption card.
	 *
	 * @param array $levels  Levels this path can unlock.
	 * @param int   $user_id Current user ID.
	 * @return void
	 */
	public static function render_points_card( $levels, $user_id ) {
		$balance = AT8_MLUC_Points::balance( $user_id );
		$label   = AT8_MLUC_Points::label();
		?>
		<p class="at8-mluc-redeem-balance">
			<?php
			printf(
				/* translators: 1: formatted balance, 2: point unit name. */
				esc_html__( 'Your balance: %1$s', 'at8-moonlight-user-center' ),
				'<strong>' . esc_html( AT8_MLUC_Points::format( $balance ) ) . '</strong>'
			);
			?>
		</p>

		<ul class="at8-mluc-redeem-list">
			<?php foreach ( $levels as $key => $level ) : ?>
				<?php
				$cost      = (int) $level['points'];
				$afford    = $balance >= $cost;
				$validity  = isset( $level['validity'] ) ? (int) $level['validity'] : 0;
				$validity_text = $validity > 0
					? sprintf(
						/* translators: %d: number of days. */
						__( 'valid %d days', 'at8-moonlight-user-center' ),
						$validity
					)
					: __( 'lifetime', 'at8-moonlight-user-center' );
				?>
				<li class="at8-mluc-redeem-item">
					<span class="at8-mluc-redeem-level">
						<?php echo wp_kses_post( AT8_MLUC_Membership::get_level_badge( $key ) ); ?>
						<span class="at8-mluc-redeem-note"><?php echo esc_html( $validity_text ); ?></span>
					</span>

					<span class="at8-mluc-redeem-cost">
						<?php
						printf(
							/* translators: 1: cost, 2: point unit name. */
							esc_html__( '%1$s %2$s', 'at8-moonlight-user-center' ),
							esc_html( number_format_i18n( $cost ) ),
							esc_html( $label )
						);
						?>
					</span>

					<?php if ( $afford ) : ?>
						<button type="button" class="at8-mluc-button at8-mluc-redeem-button"
							data-at8-mluc-redeem="<?php echo esc_attr( $key ); ?>"
							data-nonce="<?php echo esc_attr( wp_create_nonce( self::REDEEM_ACTION ) ); ?>">
							<?php esc_html_e( 'Redeem', 'at8-moonlight-user-center' ); ?>
						</button>
					<?php else : ?>
						<span class="at8-mluc-redeem-short">
							<?php
							printf(
								/* translators: %s: number of points still needed. */
								esc_html__( '%s more needed', 'at8-moonlight-user-center' ),
								esc_html( number_format_i18n( $cost - $balance ) )
							);
							?>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<p class="at8-mluc-redeem-notice" role="status" aria-live="polite" hidden></p>
		<?php
	}

	/**
	 * AJAX: redeem a level with points.
	 *
	 * @return void
	 */
	public function ajax_redeem() {
		if ( ! check_ajax_referer( self::REDEEM_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'The link has expired. Please refresh the page.', 'at8-moonlight-user-center' ) ) );
		}

		AT8_MLUC_Security::require_login();

		if ( ! AT8_MLUC_Security::allow( 'redeem' ) ) {
			AT8_MLUC_Security::reject_throttled();
		}

		$user_id = get_current_user_id();
		$level   = isset( $_POST['level'] ) ? sanitize_key( (string) $_POST['level'] ) : '';
		$levels  = AT8_MLUC_Membership::get_levels();

		if ( ! isset( $levels[ $level ] ) ) {
			wp_send_json_error( array( 'message' => __( 'That level does not exist.', 'at8-moonlight-user-center' ) ) );
		}

		$cost = isset( $levels[ $level ]['points'] ) ? (int) $levels[ $level ]['points'] : 0;

		if ( $cost <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'That level cannot be redeemed with points.', 'at8-moonlight-user-center' ) ) );
		}

		// Never let a redemption be a downgrade.
		$current = AT8_MLUC_Membership::get_user_level( $user_id );

		if ( AT8_MLUC_Membership::get_level_sort_order( $level ) <= AT8_MLUC_Membership::get_level_sort_order( $current ) ) {
			wp_send_json_error( array( 'message' => __( 'You already hold that level or a higher one.', 'at8-moonlight-user-center' ) ) );
		}

		if ( ! AT8_MLUC_Points::can_afford( $user_id, $cost ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have enough points for that level.', 'at8-moonlight-user-center' ) ) );
		}

		$spent = AT8_MLUC_Points::adjust(
			$user_id,
			-$cost,
			sprintf(
				/* translators: %s: level name. */
				__( 'Redeemed the %s level', 'at8-moonlight-user-center' ),
				AT8_MLUC_Membership::get_level_label( $level )
			),
			'redeem'
		);

		if ( is_wp_error( $spent ) ) {
			wp_send_json_error( array( 'message' => $spent->get_error_message() ) );
		}

		$validity = isset( $levels[ $level ]['validity'] ) ? (int) $levels[ $level ]['validity'] : 0;
		$expires  = $validity > 0 ? time() + ( $validity * DAY_IN_SECONDS ) : 0;

		AT8_MLUC_Membership::set_user_level( $user_id, $level, $expires );

		/**
		 * Fires after a level was redeemed with points.
		 *
		 * @since 2.4.0
		 *
		 * @param int    $user_id User ID.
		 * @param string $level   Level key.
		 * @param int    $cost    Points spent.
		 */
		do_action( 'at8_mluc_level_redeemed', $user_id, $level, $cost );

		wp_send_json_success(
			array(
				'message'  => __( 'Your membership has been upgraded.', 'at8-moonlight-user-center' ),
				'balance'  => AT8_MLUC_Points::format( $spent ),
				'redirect' => at8_mluc_get_account_url(),
			)
		);
	}
}
