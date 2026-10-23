<?php
/**
 * Points ledger.
 *
 * Points are the free plugin's single upgrade path: a member spends points to
 * redeem a membership level, and nothing else moves a level. The add-on unlocks
 * the remaining paths (direct purchase, tiered upgrades) without changing how
 * the ledger works.
 *
 * The ledger is the single source of truth: every movement is stored as its
 * own row (an atomic INSERT via add_user_meta) and the stored balance is a
 * cache derived by replaying those rows. Because each entry can carry an
 * idempotency key and the balance is always recomputed from the rows, an
 * interrupted award can always be resumed without duplicating or losing
 * anything — no locks are involved anywhere.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Point balances and ledger.
 */
class AT8_MLUC_Points {

	/**
	 * User meta holding the cached balance (derived from the ledger rows).
	 *
	 * @var string
	 */
	const BALANCE_META = 'at8_mluc_points';

	/**
	 * Legacy single-meta ledger kept for reading history written by older
	 * versions; new entries always go to ENTRY_META.
	 *
	 * @var string
	 */
	const LEDGER_META = 'at8_mluc_points_log';

	/**
	 * Per-entry ledger storage: one row per movement. Appends are atomic
	 * INSERTs, so concurrent awards for the same user can never lose an entry.
	 *
	 * @var string
	 */
	const ENTRY_META = 'at8_mluc_points_entry';

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
	 * adjust() is idempotent for the order key: whatever point the previous
	 * attempt was interrupted at (ledger row written, balance cache written,
	 * or fully completed), calling this again replays to the same state —
	 * the ledger row is never duplicated and the balance is recomputed from
	 * the ledger, so nothing can be awarded twice or lost.
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
		 * Compute first: an invalid amount or a zero result returns without
		 * touching any state, so a corrected order can be awarded later.
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

		$subject = is_string( $subject ) ? $subject : '';
		$reason  = sprintf(
			/* translators: 1: order ID, 2: purchased item (level slug or paywall post). */
			__( 'Order #%1$d (%2$s)', 'at8-moonlight-user-center' ),
			$order_id,
			$subject
		);

		// Idempotent award. The entry carries the order key, so this call
		// either records it now or replays the balance from a previous,
		// interrupted attempt — both end in exactly one award.
		$result = self::adjust( $user_id, $points, $reason, 'purchase', 'purchase:' . $order_id );

		if ( is_wp_error( $result ) ) {
			return;
		}

		// Fast-path marker for later triggers: with the marker present and the
		// idempotency key in the ledger, repeated hooks return immediately.
		add_post_meta( $order_id, '_at8_mluc_points_awarded', 1, true );
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
	 * Returns the cached balance; when no cache exists (a brand-new account,
	 * an interrupted write, or data from an older version) the balance is
	 * rebuilt by replaying the ledger.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function balance( $user_id ) {
		$cached = get_user_meta( $user_id, self::BALANCE_META, true );

		if ( '' === $cached ) {
			return self::recalc_balance( $user_id );
		}

		return max( 0, (int) $cached );
	}

	/**
	 * Ledger entries, newest first.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit  Maximum entries to return.
	 * @return array<int, array<string, mixed>>
	 */
	public static function ledger( $user_id, $limit = 20 ) {
		$entries = self::entries( $user_id );
		$entries = array_reverse( $entries );

		return array_slice( $entries, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Add or remove points.
	 *
	 * When $key is given the whole operation is idempotent: a retry after any
	 * kind of interruption replays to the same state instead of awarding
	 * twice, because the ledger row carries the key and the balance is
	 * recomputed from the ledger rows.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $amount  Signed amount.
	 * @param string $reason  Human-readable reason.
	 * @param string $source  Machine-readable source key.
	 * @param string $key     Optional idempotency key for this movement.
	 * @return int|WP_Error New balance, or an error when the movement could not be recorded.
	 */
	public static function adjust( $user_id, $amount, $reason = '', $source = 'manual', $key = '' ) {
		$user_id = (int) $user_id;
		$amount  = (int) $amount;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'at8_mluc_points_user', __( 'Unknown user.', 'at8-moonlight-user-center' ) );
		}

		/*
		 * Idempotent replay: the ledger row for this key already exists, so a
		 * previous attempt was interrupted after recording it. Recomputing the
		 * balance cache from the ledger completes whatever step was missing.
		 */
		if ( '' !== $key && self::has_entry( $user_id, $key ) ) {
			return self::recalc_balance( $user_id );
		}

		// Fast overdraft pre-check against the cached balance; the replayed
		// balance below is authoritative and rejects again if it would go
		// negative under concurrency.
		$balance = self::balance( $user_id );

		if ( $amount < 0 && abs( $amount ) > $balance ) {
			return new WP_Error( 'at8_mluc_points_balance', __( 'That would take the balance below zero.', 'at8-moonlight-user-center' ) );
		}

		/*
		 * The ledger row is an atomic INSERT — the audit intent is recorded
		 * first. The balance cache is then recomputed by replaying the rows,
		 * which is what actually moves the balance.
		 */
		$stored = self::add_entry( $user_id, $amount, $reason, $source, $key );

		if ( false === $stored ) {
			return new WP_Error( 'at8_mluc_points_save', __( 'The points update could not be saved, please retry.', 'at8-moonlight-user-center' ) );
		}

		$new_balance = self::recalc_balance( $user_id );

		if ( $new_balance < 0 ) {
			// Concurrent deductions took the replay below zero: undo exactly
			// this movement and report the overdraft.
			self::remove_entry( $user_id, $stored );
			self::recalc_balance( $user_id );
			return new WP_Error( 'at8_mluc_points_balance', __( 'That would take the balance below zero.', 'at8-moonlight-user-center' ) );
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
		do_action( 'at8_mluc_points_adjusted', $user_id, $amount, $new_balance, $stored );

		return $new_balance;
	}

	/**
	 * Append one ledger row. add_user_meta is an atomic INSERT, so rows from
	 * concurrent adjustments for the same user interleave safely instead of
	 * overwriting each other.
	 *
	 * Returns the row exactly as stored (including the unique u token), so a
	 * caller that needs to undo this movement can delete precisely that row.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $amount  Signed amount.
	 * @param string $reason  Human-readable reason.
	 * @param string $source  Machine-readable source key.
	 * @param string $key     Idempotency key ('' for none).
	 * @return array|false The stored entry, or false when the write failed.
	 */
	private static function add_entry( $user_id, $amount, $reason, $source, $key = '' ) {
		$entry = array(
			'time'   => time(),
			'amount' => (int) $amount,
			'reason' => sanitize_text_field( $reason ),
			'source' => sanitize_key( $source ),
			'by'     => get_current_user_id(),
			'key'    => (string) $key,
			'u'      => uniqid( '', true ),
		);

		if ( ! add_user_meta( $user_id, self::ENTRY_META, $entry ) ) {
			return false;
		}

		return $entry;
	}

	/**
	 * Remove exactly one ledger row (the one this call added).
	 *
	 * @param int   $user_id User ID.
	 * @param array $entry   The stored entry as returned by add_entry().
	 * @return void
	 */
	private static function remove_entry( $user_id, $entry ) {
		delete_user_meta( $user_id, self::ENTRY_META, $entry );
	}

	/**
	 * Whether a ledger row carrying the given idempotency key exists.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Idempotency key.
	 * @return bool
	 */
	public static function has_entry( $user_id, $key ) {
		foreach ( self::entries( $user_id ) as $entry ) {
			if ( isset( $entry['key'] ) && '' !== $entry['key'] && $entry['key'] === $key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * All ledger rows, oldest first. Rows written by this version are merged
	 * with any legacy single-meta history so nothing already recorded is lost.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function entries( $user_id ) {
		$entries = get_user_meta( $user_id, self::ENTRY_META, false );
		$entries = is_array( $entries ) ? array_values( array_filter( $entries, 'is_array' ) ) : array();

		$legacy = get_user_meta( $user_id, self::LEDGER_META, true );
		if ( is_array( $legacy ) ) {
			$entries = array_merge( array_values( array_filter( $legacy, 'is_array' ) ), $entries );
		}

		usort( $entries, function ( $a, $b ) {
			return (int) $a['time'] <=> (int) $b['time'];
		} );

		return $entries;
	}

	/**
	 * Recompute the cached balance by replaying every ledger row and store it.
	 *
	 * This is the one place the balance cache is written; because it derives
	 * from the ledger rows, calling it at any time — after an interrupted
	 * award, after a retry, or out of the blue — always yields the correct
	 * balance.
	 *
	 * @param int $user_id User ID.
	 * @return int The replayed balance.
	 */
	private static function recalc_balance( $user_id ) {
		$sum = 0;

		foreach ( self::entries( $user_id ) as $entry ) {
			$sum += (int) $entry['amount'];
		}

		$sum = max( 0, $sum );

		update_user_meta( $user_id, self::BALANCE_META, $sum );

		return $sum;
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
