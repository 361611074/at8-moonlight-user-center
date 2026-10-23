<?php
/**
 * Points ledger.
 *
 * Points are the free plugin's single upgrade path: a member spends points to
 * redeem a membership level, and nothing else moves a level. The add-on unlocks
 * the remaining paths (direct purchase, tiered upgrades) without changing how
 * the ledger works.
 *
 * The ledger rows are the single source of truth and the stored balance is
 * only a mirror that is derived from them. Three mechanisms keep the rows and
 * the mirror consistent under concurrency, and none of them is a
 * "read, then write" pair that two requests could interleave:
 *
 * 1. Every movement is its own row, appended with add_user_meta(), which is a
 *    single INSERT — two awards for the same user can never overwrite or lose
 *    each other.
 * 2. A movement that carries an idempotency key first creates a claim row with
 *    a plain INSERT into the options table. option_name is UNIQUE in the
 *    WordPress schema, so the database — not a PHP check — decides which
 *    request owns the key. A retry finds the claim and replays instead of
 *    awarding twice.
 * 3. Balance changes for one user are serialised by a mutex row in the same
 *    table, taken over only through a compare-and-swap on its exact value, so
 *    the read-compute-write sequence inside it cannot be split by another
 *    writer. The mutex has a TTL so a crashed request cannot block the account
 *    forever, and it is released only by its owner.
 *
 * The balance is recomputed from the rows inside the mutex before every
 * movement and again after it, which is what makes an interrupted award
 * resumable: whatever step a request died at, the next one replays to the same
 * state. The stored balance is never read to decide anything — it is a mirror
 * for inspection, written last and verified.
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
	 * Prefix of the per-user mutex row in the options table.
	 *
	 * @var string
	 */
	const LOCK_PREFIX = 'at8_mluc_points_lock_';

	/**
	 * Prefix of the per-movement idempotency claim row in the options table.
	 *
	 * @var string
	 */
	const CLAIM_PREFIX = 'at8_mluc_points_claim_';

	/**
	 * Seconds a mutex row may live before another request may take it over.
	 * The critical section is a handful of queries, so this only has to cover
	 * a slow database, not a slow page.
	 *
	 * @var int
	 */
	const LOCK_TTL = 10;

	/**
	 * Seconds to wait for the per-user mutex before reporting "busy".
	 *
	 * @var int
	 */
	const LOCK_WAIT = 5;

	/**
	 * Seconds an in-flight claim may live before it is treated as abandoned
	 * (the request that created it died before writing anything).
	 *
	 * @var int
	 */
	const CLAIM_TTL = 60;

	/**
	 * Seconds to wait for the request that owns the same claim to finish.
	 *
	 * @var int
	 */
	const CLAIM_WAIT = 2;

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
	 * The order key is claimed in the database before anything is written, so
	 * concurrent callbacks for the same order produce exactly one ledger row:
	 * whichever request creates the claim row writes the movement, and the
	 * others observe that row and return the same balance. Whatever point an
	 * earlier attempt was interrupted at, a retry replays to the same state.
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

		// The claim on the order key is what makes this exactly-once: the
		// database admits one writer for 'purchase:<order>', every other
		// caller waits for that row to land and then reads the same balance.
		$result = self::adjust( $user_id, $points, $reason, 'purchase', 'purchase:' . $order_id );

		if ( is_wp_error( $result ) ) {
			return;
		}

		// Fast-path marker for later triggers. It is only an optimisation: the
		// claim row and the ledger row are what actually prevent a re-award.
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
	 * Balance for a user, replayed from the ledger rows.
	 *
	 * The stored mirror is deliberately not read here: it can only ever be as
	 * fresh as the last completed movement, so a page rendered during someone
	 * else's award would show a stale number. Summing the rows costs one meta
	 * query and is always right.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function balance( $user_id ) {
		return max( 0, self::replay( (int) $user_id ) );
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
	 * With a key the whole operation is idempotent at database level: the key
	 * is claimed with an atomic INSERT into the options table, whose
	 * option_name column is UNIQUE, so two requests can never both decide that
	 * they are the one that should write the movement. The loser waits for the
	 * winner to finish and then replays the resulting balance.
	 *
	 * Without a key the movement still runs inside the per-user mutex, so a
	 * deduction can never pass the overdraft check at the same time as another
	 * deduction, and the balance written afterwards always includes every row
	 * that was committed before it.
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
		$key     = (string) $key;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'at8_mluc_points_user', __( 'Unknown user.', 'at8-moonlight-user-center' ) );
		}

		/*
		 * Step 1 — claim the idempotency key. The INSERT either creates the
		 * row (this request owns the movement) or fails because the row is
		 * already there (another request owns it, or it was already done).
		 */
		$claim = array();

		if ( '' !== $key ) {
			$claim = self::claim( $user_id, $key );

			if ( is_wp_error( $claim ) ) {
				return self::settle_claimed( $user_id, $key, $claim );
			}
		}

		/*
		 * Step 2 — take the per-user mutex. Everything that reads the ledger
		 * and writes it back happens inside it, so the sequence cannot be
		 * interleaved with another writer for the same account.
		 */
		$lock = self::lock( $user_id );

		if ( false === $lock ) {
			self::drop_claim( $claim );

			return new WP_Error(
				'at8_mluc_points_busy',
				__( 'Another points update is in progress. Please try again in a moment.', 'at8-moonlight-user-center' )
			);
		}

		$applied = self::apply_locked( $user_id, $amount, $reason, $source, $key );

		self::unlock( $lock );

		if ( is_wp_error( $applied ) ) {
			// Every failure path above leaves no ledger row behind, so the
			// claim must be dropped to let a corrected retry through.
			self::drop_claim( $claim );

			return $applied;
		}

		/**
		 * Fires after a balance changed.
		 *
		 * @since 2.4.0
		 *
		 * @param int        $user_id User ID.
		 * @param int        $amount  Signed amount applied.
		 * @param int        $balance New balance.
		 * @param array|bool $entry   Ledger entry, or false when a previous
		 *                            attempt had already written it.
		 */
		do_action( 'at8_mluc_points_adjusted', $user_id, $amount, $applied['balance'], $applied['entry'] );

		return $applied['balance'];
	}

	/**
	 * The mutation itself, always called while holding the per-user mutex.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $amount  Signed amount.
	 * @param string $reason  Human-readable reason.
	 * @param string $source  Machine-readable source key.
	 * @param string $key     Idempotency key ('' for none).
	 * @return array|WP_Error array( balance, entry ) or an error.
	 */
	private static function apply_locked( $user_id, $amount, $reason, $source, $key ) {
		/*
		 * A previous attempt was interrupted after recording the row: the
		 * award already happened, so replay the balance and stop. This is the
		 * resume path, and it runs under the mutex so it cannot race with the
		 * request that is writing that row.
		 */
		if ( '' !== $key && self::has_entry( $user_id, $key ) ) {
			return array(
				'balance' => self::balance( $user_id ),
				'entry'   => false,
			);
		}

		$current = self::replay( $user_id );

		if ( $amount < 0 && abs( $amount ) > $current ) {
			return new WP_Error( 'at8_mluc_points_balance', __( 'That would take the balance below zero.', 'at8-moonlight-user-center' ) );
		}

		$entry = self::add_entry( $user_id, $amount, $reason, $source, $key );

		if ( false === $entry ) {
			return new WP_Error( 'at8_mluc_points_save', __( 'The points update could not be saved, please retry.', 'at8-moonlight-user-center' ) );
		}

		$new_balance = $current + $amount;

		if ( $new_balance < 0 ) {
			// Defence in depth: the check above already rejects this. If a
			// ledger edited outside this API ever replayed below zero, undo
			// exactly this row instead of storing a negative balance.
			self::remove_entry( $user_id, $entry );

			return new WP_Error( 'at8_mluc_points_balance', __( 'That would take the balance below zero.', 'at8-moonlight-user-center' ) );
		}

		self::store_balance( $user_id, $new_balance );

		return array(
			'balance' => $new_balance,
			'entry'   => $entry,
		);
	}

	/**
	 * Wait for the request that owns an idempotency key to finish, then report
	 * the balance it produced.
	 *
	 * Concurrent callbacks for the same order land here: one of them writes
	 * the movement, the others observe the resulting ledger row and return the
	 * same balance without touching anything.
	 *
	 * @param int      $user_id User ID.
	 * @param string   $key     Idempotency key.
	 * @param WP_Error $busy    Error to return when the owner does not finish in time.
	 * @return int|WP_Error
	 */
	private static function settle_claimed( $user_id, $key, $busy ) {
		$deadline = microtime( true ) + self::CLAIM_WAIT;

		do {
			if ( self::has_entry( $user_id, $key ) ) {
				return self::balance( $user_id );
			}

			usleep( 50000 );
		} while ( microtime( true ) < $deadline );

		return $busy;
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
	 * Sum every ledger row. This is the authoritative balance; it is not
	 * clamped, so an overdraft check can see that the rows went negative
	 * instead of being hidden behind a zero.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private static function replay( $user_id ) {
		$sum = 0;

		foreach ( self::entries( $user_id ) as $entry ) {
			$sum += (int) $entry['amount'];
		}

		return $sum;
	}

	/**
	 * Write the balance mirror.
	 *
	 * Only called while holding the mutex, after the rows were read. The value
	 * is verified by reading it back, because update_user_meta() also returns
	 * false when the stored value is already identical.
	 *
	 * @param int $user_id User ID.
	 * @param int $balance Balance to mirror.
	 * @return void
	 */
	private static function store_balance( $user_id, $balance ) {
		update_user_meta( $user_id, self::BALANCE_META, (int) $balance );

		if ( (int) get_user_meta( $user_id, self::BALANCE_META, true ) !== (int) $balance ) {
			update_user_meta( $user_id, self::BALANCE_META, (int) $balance );
		}
	}

	/**
	 * Claim an idempotency key.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Idempotency key.
	 * @return array|WP_Error array( name, value ) when this request owns the
	 *                        key, or an error when another request does.
	 */
	private static function claim( $user_id, $key ) {
		$name  = self::CLAIM_PREFIX . md5( (int) $user_id . '|' . $key );
		$value = self::token() . '|' . ( time() + self::CLAIM_TTL );

		if ( self::insert_row( $name, $value ) ) {
			return array(
				'name'  => $name,
				'value' => $value,
			);
		}

		$current = self::read_row( $name );

		if ( null === $current ) {
			// The row vanished between the INSERT and the read (its owner
			// finished and cleaned up). One more attempt settles it.
			return self::insert_row( $name, $value )
				? array(
					'name'  => $name,
					'value' => $value,
				)
				: new WP_Error( 'at8_mluc_points_busy', __( 'This points update is already being processed.', 'at8-moonlight-user-center' ) );
		}

		if ( self::expiry_of( $current ) >= time() ) {
			// Another request is working on this exact key right now.
			return new WP_Error( 'at8_mluc_points_busy', __( 'This points update is already being processed.', 'at8-moonlight-user-center' ) );
		}

		/*
		 * The owner died before writing anything. Take the claim over with the
		 * same compare-and-swap the mutex uses: only the request whose UPDATE
		 * still matches the value it read wins.
		 */
		return self::swap_row( $name, $current, $value )
			? array(
				'name'  => $name,
				'value' => $value,
			)
			: new WP_Error( 'at8_mluc_points_busy', __( 'This points update is already being processed.', 'at8-moonlight-user-center' ) );
	}

	/**
	 * Delete a claim this request owns, so a corrected retry can claim again.
	 *
	 * @param array $claim Claim handle from claim().
	 * @return void
	 */
	private static function drop_claim( $claim ) {
		if ( ! isset( $claim['name'], $claim['value'] ) ) {
			return;
		}

		self::delete_row( $claim['name'], $claim['value'] );
	}

	/**
	 * Take the per-user mutex.
	 *
	 * @param int $user_id User ID.
	 * @return array|false array( name, value ) or false when the wait timed out.
	 */
	private static function lock( $user_id ) {
		$name     = self::LOCK_PREFIX . (int) $user_id;
		$token    = self::token();
		$deadline = microtime( true ) + self::LOCK_WAIT;

		do {
			$value = $token . '|' . ( time() + self::LOCK_TTL );

			if ( self::insert_row( $name, $value ) ) {
				/**
				 * Fires when a request starts its critical section for a user.
				 *
				 * @since 2.5.8
				 *
				 * @param int $user_id User ID.
				 */
				do_action( 'at8_mluc_points_lock_acquired', (int) $user_id );

				return array(
					'name'  => $name,
					'value' => $value,
				);
			}

			$current = self::read_row( $name );

			if ( null !== $current && self::expiry_of( $current ) < time() ) {
				// Expired owner: take over only if the row still holds the
				// exact value that was just read. Two requests racing here
				// cannot both win, which is what the blind delete of an older
				// implementation got wrong.
				if ( self::swap_row( $name, $current, $value ) ) {
					/** This action is documented above. */
					do_action( 'at8_mluc_points_lock_acquired', (int) $user_id );

					return array(
						'name'  => $name,
						'value' => $value,
					);
				}
			}

			usleep( 20000 );
		} while ( microtime( true ) < $deadline );

		return false;
	}

	/**
	 * Release the mutex, but only if this request still owns it.
	 *
	 * @param array $lock Lock handle from lock().
	 * @return void
	 */
	private static function unlock( $lock ) {
		self::delete_row( $lock['name'], $lock['value'] );

		/**
		 * Fires when a request leaves its critical section for a user.
		 *
		 * @since 2.5.8
		 *
		 * @param int $user_id User ID.
		 */
		do_action( 'at8_mluc_points_lock_released', (int) substr( $lock['name'], strlen( self::LOCK_PREFIX ) ) );
	}

	/**
	 * Drop every cached view of a row written outside the options API.
	 *
	 * The "notoptions" cache is the one that matters: without clearing it a
	 * later get_option() for the same name would answer "does not exist" from
	 * the cache even though the row is in the database.
	 *
	 * @param string $name option_name.
	 * @return void
	 */
	private static function flush_row_cache( $name ) {
		wp_cache_delete( $name, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * Create a row only if its name is still free.
	 *
	 * A plain INSERT is the atomic test-and-set: option_name carries a UNIQUE
	 * index in the WordPress schema, so the database decides the winner even
	 * when two PHP processes run at the same instant.
	 *
	 * @param string $name  option_name.
	 * @param string $value option_value.
	 * @return bool Whether this call created the row.
	 */
	private static function insert_row( $name, $value ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A cached API cannot express test-and-set; the UNIQUE index on option_name is the whole point.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				$value
			)
		);

		$wpdb->suppress_errors( $suppress );
		self::flush_row_cache( $name );

		return false !== $result && $result > 0;
	}

	/**
	 * Read a row without going through the options cache.
	 *
	 * @param string $name option_name.
	 * @return string|null
	 */
	private static function read_row( $name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must observe the row another process just wrote, not a cached copy.
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * Replace a row only while it still holds the expected value.
	 *
	 * @param string $name     option_name.
	 * @param string $expected Value that must still be stored.
	 * @param string $value    New value.
	 * @return bool Whether this call won the swap.
	 */
	private static function swap_row( $name, $expected, $value ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap; the WHERE clause on the old value is the correctness guarantee.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$name,
				$expected
			)
		);

		$wpdb->suppress_errors( $suppress );
		self::flush_row_cache( $name );

		return false !== $result && $result > 0;
	}

	/**
	 * Delete a row only while it still holds the expected value.
	 *
	 * @param string $name     option_name.
	 * @param string $expected Value that must still be stored.
	 * @return bool Whether a row was deleted.
	 */
	private static function delete_row( $name, $expected ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete: a stale owner must never remove the row a newer owner created.
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$name,
				$expected
			)
		);

		$wpdb->suppress_errors( $suppress );
		self::flush_row_cache( $name );

		return false !== $result && $result > 0;
	}

	/**
	 * Opaque token that never contains the "|" separator.
	 *
	 * @return string
	 */
	private static function token() {
		return str_replace( '.', '', uniqid( '', true ) ) . wp_rand( 1000, 9999 );
	}

	/**
	 * Expiry timestamp stored in a lock or claim row.
	 *
	 * @param string $value Stored value, "token|expiry".
	 * @return int
	 */
	private static function expiry_of( $value ) {
		$parts = explode( '|', (string) $value );

		return isset( $parts[1] ) ? (int) $parts[1] : 0;
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
