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
		add_action( 'admin_print_footer_scripts-profile.php', array( $this, 'print_admin_script' ) );
		add_action( 'admin_print_footer_scripts-user-edit.php', array( $this, 'print_admin_script' ) );
		add_action( 'wp_ajax_at8_mluc_adjust_points', array( $this, 'ajax_adjust' ) );
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
	public function print_admin_script() {
		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'at8_mluc_nonce' ),
		);
		?>
		<script type="text/javascript">
		window.at8MlucData = <?php echo wp_json_encode( $config ); ?>;
		( function () {
			var data = window.at8MlucData || {};

			function post( mode ) {
				var userId = document.getElementById( 'at8-mluc-points-add' ).getAttribute( 'data-user' );
				var amount = parseInt( document.getElementById( 'at8-mluc-points-amount' ).value, 10 );
				var status = document.getElementById( 'at8-mluc-points-status' );
				var body = new FormData();

				body.append( 'action', 'at8_mluc_adjust_points' );
				body.append( 'nonce', data.nonce );
				body.append( 'user_id', userId );
				body.append( 'amount', isNaN( amount ) ? 0 : amount );
				body.append( 'mode', mode );

				fetch( data.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( ! res || ! res.success ) {
							status.textContent = ( res && res.data && res.data.message ) || 'Error.';
							return;
						}

						document.getElementById( 'at8-mluc-points-balance' ).value = res.data.balance;
						status.textContent = res.data.label;
					} )
					.catch( function () {
						status.textContent = 'Error.';
					} );
			}

			var add = document.getElementById( 'at8-mluc-points-add' );
			var deduct = document.getElementById( 'at8-mluc-points-deduct' );

			if ( add ) {
				add.addEventListener( 'click', function () { post( 'add' ); } );
			}

			if ( deduct ) {
				deduct.addEventListener( 'click', function () { post( 'deduct' ); } );
			}
		}() );
		</script>
		<?php
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

		$balance = self::balance( $user_id );

		if ( $amount < 0 && abs( $amount ) > $balance ) {
			return new WP_Error( 'at8_mluc_points_balance', __( 'That would take the balance below zero.', 'at8-moonlight-user-center' ) );
		}

		$balance = max( 0, $balance + $amount );

		update_user_meta( $user_id, self::BALANCE_META, $balance );

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

		update_user_meta( $user_id, self::LEDGER_META, $log );

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
		do_action( 'at8_mluc_points_adjusted', $user_id, $amount, $balance, $entry );

		return $balance;
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
