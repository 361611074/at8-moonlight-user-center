<?php
/**
 * Points ledger.
 *
 * Points are the free plugin's single upgrade path: a member spends points to
 * redeem a membership level, and nothing else moves a level. The add-on unlocks
 * the remaining paths (direct purchase, tiered upgrades) without changing how
 * the ledger works.
 *
 * Every movement is stored as a log entry, so a balance can always be
 * explained: the stored balance is a cache, the log is the truth.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Point balances and ledger.
 */
class AT8_MLUC_Points {

	/**
	 * User meta holding the cached balance.
	 *
	 * @var string
	 */
	const BALANCE_META = 'at8_mluc_points';

	/**
	 * User meta holding the ledger.
	 *
	 * @var string
	 */
	const LEDGER_META = 'at8_mluc_points_log';

	/**
	 * Maximum ledger entries kept per user.
	 *
	 * @var int
	 */
	const LOG_LIMIT = 200;

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Points|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Points
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
		add_action( 'at8_mluc_after_register', array( $this, 'grant_signup_bonus' ), 20 );
		add_action( 'show_user_profile', array( $this, 'render_admin_balance' ) );
		add_action( 'edit_user_profile', array( $this, 'render_admin_balance' ) );
		add_action( 'admin_print_footer_scripts-profile.php', array( $this, 'enqueue_admin_script' ) );
		add_action( 'admin_print_footer_scripts-user-edit.php', array( $this, 'enqueue_admin_script' ) );
		add_action( 'wp_ajax_at8_mluc_adjust_points', array( $this, 'ajax_adjust' ) );
		add_action( 'at8_mluc_payment_completed', array( $this, 'grant_for_order' ), 10, 3 );
	}

	/**
	 * Award points for a completed order, based on the configured
	 * points-per-currency rate.
	 *
	 * The hook fires exactly once per order because complete_order() refuses
	 * to run twice; the unique post-meta lock additionally makes the award
	 * race-proof and keeps an auditable marker on the order itself.
	 *
	 * @param int    $order_id Completed order ID.
	 * @param int    $user_id  Buyer user ID.
	 * @param string $subject  Level slug or "paywall:<post_id>".
	 * @return void
	 */
	public function grant_for_order( $order_id, $user_id, $subject ) {
		$order_id = (int) $order_id;
		$user_id  = (int) $user_id;
		$rate     = self::rate();

		if ( $order_id <= 0 || $user_id <= 0 || $rate <= 0 ) {
			return;
		}

		// Defensive status check: the hook is documented to fire on completed
		// orders only, so anything still pending, cancelled or failed earns
		// nothing even if another plugin fires the hook prematurely.
		if ( 'paid' !== (string) get_post_meta( $order_id, '_at8_mluc_pay_status', true ) ) {
			return;
		}

		if ( ! get_userdata( $user_id ) ) {
			return;
		}

		/*
		 * Compute first, lock last: an invalid amount or a zero result returns
		 * before the lock exists, so a corrected order can be awarded later.
		 * The price comes from the order record; client input is never trusted.
		 */
		$price = (float) get_post_meta( $order_id, '_at8_mluc_pay_price', true );

		if ( $price <= 0 ) {
			return;
		}

		$points = (int) floor( $price * $rate );

		if ( $points <= 0 ) {
			return;
		}

		// One award per order: add_post_meta with $unique fails when the lock
		// already exists, so concurrent or repeated triggers cannot double-award.
		if ( ! add_post_meta( $order_id, '_at8_mluc_points_awarded', 1, true ) ) {
			return;
		}

		$subject = is_string( $subject ) ? $subject : '';
		$reason  = sprintf(
			/* translators: 1: order ID, 2: purchased item (level slug or paywall post). */
			__( 'Order #%1$d (%2$s)', 'at8-moonlight-user-center' ),
			$order_id,
			$subject
		);

		$before = (int) self::balance( $user_id );
		$result = self::adjust( $user_id, $points, $reason, 'purchase' );

		/*
		 * Award verification: a WP_Error or a balance that did not actually
		 * move by $points (e.g. a failed meta write) rolls the lock back, so
		 * the next trigger can retry the award instead of losing it.
		 */
		if ( is_wp_error( $result ) || (int) self::balance( $user_id ) !== $before + $points ) {
			delete_post_meta( $order_id, '_at8_mluc_points_awarded' );
			return;
		}
	}

	/**
	 * Grant the configured signup bonus.
	 *
	 * @param int $user_id New user ID.
	 * @return void
	 */
	public function grant_signup_bonus( $user_id ) {
		$bonus = self::signup_bonus();

		if ( $bonus <= 0 ) {
			return;
		}

		self::adjust( $user_id, $bonus, __( 'Welcome bonus', 'at8-moonlight-user-center' ), 'signup' );
	}

	/**
	 * Wire the grant / deduct buttons on the profile screen.
	 *
	 * @return void
	 */
	/**
	 * Enqueue the profile-screen script through the standard script API.
	 *
	 * The hook only fires on profile.php / user-edit.php, so the asset loads
	 * exactly where the grant / deduct buttons exist.
	 *
	 * @return void
	 */
	public function enqueue_admin_script() {
		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'at8_mluc_nonce' ),
		);

		wp_register_script( 'at8-mluc-points-admin', '', array(), AT8_MLUC_VERSION, true );
		wp_add_inline_script(
			'at8-mluc-points-admin',
			'window.at8MlucData = ' . wp_json_encode( $config ) . ';',
			'before'
		);
		wp_enqueue_script( 'at8-mluc-points-admin' );
		wp_enqueue_script(
			'at8-mluc-points-admin-buttons',
			AT8_MLUC_PLUGIN_URL . 'assets/js/at8-mluc-points-admin.js',
			array( 'at8-mluc-points-admin' ),
			AT8_MLUC_VERSION,
			true
		);
	}

	/**
	 * Singular label for the point unit.
	 *
	 * @return string
	 */
	public static function label() {
		$label = (string) at8_mluc_get_option( 'points_label', 'points' );

		return '' !== $label ? $label : 'points';
	}

	/**
	 * Balance for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function balance( $user_id ) {
		return max( 0, (int) get_user_meta( $user_id, self::BALANCE_META, true ) );
	}

	/**
	 * Ledger entries, newest first.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit  Maximum entries to return.
	 * @return array<int, array<string, mixed>>
	 */
	public static function ledger( $user_id, $limit = 20 ) {
		$log = get_user_meta( $user_id, self::LEDGER_META, true );

		if ( ! is_array( $log ) ) {
			return array();
		}

		$log = array_values( array_filter( $log, 'is_array' ) );
		$log = array_reverse( $log );

		return array_slice( $log, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Add or remove points.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $amount  Signed amount.
	 * @param string $reason  Human-readable reason.
	 * @param string $source  Machine-readable source key.
	 * @return int|WP_Error New balance, or an error when the balance would go negative.
	 */
	public static function adjust( $user_id, $amount, $reason = '', $source = 'manual' ) {
		$user_id = (int) $user_id;
		$amount  = (int) $amount;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'at8_mluc_points_user', __( 'Unknown user.', 'at8-moonlight-user-center' ) );
		}

		/*
		 * Per-user mutual exclusion. add_option() is an INSERT, so acquiring
		 * the lock is atomic even when two orders complete for the same user
		 * at the same moment. The stamp enables stale-lock takeover and the
		 * deadline bounds the wait.
		 */
		$lock     = 'at8_mluc_points_lock_' . $user_id;
		$deadline = time() + 5;

		while ( true ) {
			if ( add_option( $lock, array( 't' => time() ), '', 'no' ) ) {
				break;
			}

			$held = get_option( $lock );

			if ( is_array( $held ) && ( time() - (int) $held['t'] ) > 10 ) {
				delete_option( $lock );
				continue;
			}

			if ( time() > $deadline ) {
				return new WP_Error( 'at8_mluc_points_busy', __( 'Another points update is in progress, please retry.', 'at8-moonlight-user-center' ) );
			}

			usleep( 50000 );
		}

		$balance = self::balance( $user_id );

		if ( $amount < 0 && abs( $amount ) > $balance ) {
			delete_option( $lock );
			return new WP_Error( 'at8_mluc_points_balance', __( 'That would take the balance below zero.', 'at8-moonlight-user-center' ) );
		}

		$new_balance = max( 0, $balance + $amount );

		$entry = array(
			'time'   => time(),
			'amount' => $amount,
			'reason' => sanitize_text_field( $reason ),
			'source' => sanitize_key( $source ),
			'by'     => get_current_user_id(),
		);

		$log = get_user_meta( $user_id, self::LEDGER_META, true );
		$log = is_array( $log ) ? $log : array();

		$log[] = $entry;

		if ( count( $log ) > self::LOG_LIMIT ) {
			$log = array_slice( $log, -self::LOG_LIMIT );
		}

		update_user_meta( $user_id, self::BALANCE_META, $new_balance );
		update_user_meta( $user_id, self::LEDGER_META, $log );

		/*
		 * Read-back verification while still holding the mutex: a silently
		 * failed meta write is detected here and the half-written state is
		 * rolled back, so balance and ledger can never drift apart.
		 */
		$saved_balance = (int) self::balance( $user_id );
		$saved_log     = get_user_meta( $user_id, self::LEDGER_META, true );
		$last          = is_array( $saved_log ) ? end( $saved_log ) : null;
		$ledger_ok     = is_array( $last ) && (int) $last['amount'] === $amount && $last['source'] === $entry['source'] && (int) $last['time'] === (int) $entry['time'];

		if ( $saved_balance !== $new_balance || ! $ledger_ok ) {
			update_user_meta( $user_id, self::BALANCE_META, $balance );
			$rolled_log = is_array( $saved_log ) ? $saved_log : $log;
			if ( count( $rolled_log ) > 0 && $last === end( $rolled_log ) ) {
				$rolled_log = array_slice( $rolled_log, 0, -1 );
			}
			update_user_meta( $user_id, self::LEDGER_META, $rolled_log );
			delete_option( $lock );
			return new WP_Error( 'at8_mluc_points_save', __( 'The points update could not be saved, please retry.', 'at8-moonlight-user-center' ) );
		}

		/**
		 * Fires after a balance changed.
		 *
		 * @since 2.4.0
		 *
		 * @param int   $user_id User ID.
		 * @param int   $amount  Signed amount applied.
		 * @param int   $balance New balance.
		 * @param array $entry   Ledger entry.
		 */
		do_action( 'at8_mluc_points_adjusted', $user_id, $amount, $new_balance, $entry );

		delete_option( $lock );

		return $new_balance;
	}

	/**
	 * Format an amount with the configured unit.
	 *
	 * @param int $amount Amount.
	 * @return string
	 */
	public static function format( $amount ) {
		return number_format_i18n( (int) $amount ) . ' ' . self::label();
	}

	/**
	 * Whether a user can cover a cost.
	 *
	 * @param int $user_id User ID.
	 * @param int $cost   Cost in points.
	 * @return bool
	 */
	public static function can_afford( $user_id, $cost ) {
		return self::balance( $user_id ) >= (int) $cost;
	}

	/**
	 * Points awarded per unit of currency spent.
	 *
	 * @return float
	 */
	public static function rate() {
		return (float) at8_mluc_get_option( 'points_per_currency', 1 );
	}

	/**
	 * Points granted to a new account.
	 *
	 * @return int
	 */
	public static function signup_bonus() {
		return max( 0, (int) at8_mluc_get_option( 'points_signup_bonus', 0 ) );
	}

	/**
	 * Admin balance widget on the user profile screen.
	 *
	 * @param WP_User $user User being edited.
	 * @return void
	 */
	public function render_admin_balance( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$balance = self::balance( $user->ID );
		$ledger  = self::ledger( $user->ID, 10 );
		?>
		<h2><?php esc_html_e( 'Points', 'at8-moonlight-user-center' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="at8-mluc-points-balance"><?php esc_html_e( 'Balance', 'at8-moonlight-user-center' ); ?></label></th>
				<td>
					<input type="number" id="at8-mluc-points-balance" value="<?php echo esc_attr( (string) $balance ); ?>" class="small-text" readonly />
					<p class="description">
						<?php esc_html_e( 'Adjust the balance with the buttons below; every change is written to the ledger.', 'at8-moonlight-user-center' ); ?>
					</p>

					<p>
						<input type="number" id="at8-mluc-points-amount" value="10" class="small-text" step="1" min="1" />
						<button type="button" class="button" id="at8-mluc-points-add"
							data-user="<?php echo esc_attr( (string) $user->ID ); ?>">
							<?php esc_html_e( 'Grant', 'at8-moonlight-user-center' ); ?>
						</button>
						<button type="button" class="button" id="at8-mluc-points-deduct"
							data-user="<?php echo esc_attr( (string) $user->ID ); ?>">
							<?php esc_html_e( 'Deduct', 'at8-moonlight-user-center' ); ?>
						</button>
						<span id="at8-mluc-points-status" role="status" aria-live="polite"></span>
					</p>
				</td>
			</tr>
		</table>

		<?php if ( ! empty( $ledger ) ) : ?>
			<h3><?php esc_html_e( 'Recent activity', 'at8-moonlight-user-center' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'at8-moonlight-user-center' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Amount', 'at8-moonlight-user-center' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reason', 'at8-moonlight-user-center' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ledger as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) $entry['time'] ) ); ?></td>
							<td>
								<?php echo esc_html( ( (int) $entry['amount'] > 0 ? '+' : '' ) . number_format_i18n( (int) $entry['amount'] ) ); ?>
							</td>
							<td><?php echo esc_html( $entry['reason'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * AJAX: grant or deduct points from the user profile screen.
	 *
	 * @return void
	 */
	public function ajax_adjust() {
		if ( ! check_ajax_referer( 'at8_mluc_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'The link has expired. Please refresh the page.', 'at8-moonlight-user-center' ) ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$amount  = isset( $_POST['amount'] ) ? (int) $_POST['amount'] : 0;
		$mode    = isset( $_POST['mode'] ) ? sanitize_key( (string) $_POST['mode'] ) : 'add';

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'at8-moonlight-user-center' ) ) );
		}

		if ( $amount <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Enter an amount above zero.', 'at8-moonlight-user-center' ) ) );
		}

		$signed = 'deduct' === $mode ? -$amount : $amount;
		$result = self::adjust( $user_id, $signed, __( 'Admin adjustment', 'at8-moonlight-user-center' ), 'admin' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'balance' => $result,
				'label'   => self::format( $result ),
			)
		);
	}
}
