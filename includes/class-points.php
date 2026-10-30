<?php
/**
 * Point balances and the points ledger.
 *
 * Storage model
 * -------------
 * Two dedicated tables hold the whole system:
 *
 * - `{prefix}at8_mluc_points_ledger` — append-only movements, one row per
 *   change, with a UNIQUE (user_id, idem_key) index that makes "award this
 *   order once" a database guarantee instead of an application promise.
 * - `{prefix}at8_mluc_points_account` — one row per user holding the running
 *   balance and a version number (`fence`).
 *
 * Every movement is a single transaction with two statements:
 *
 *   1. UPDATE account
 *        SET balance = balance + :amount, fence = :next
 *      WHERE user_id = :user
 *        AND fence = :seen            <-- the version this request read
 *        AND balance + :amount >= 0   <-- all-or-nothing overdraft guard
 *   2. INSERT INTO ledger (...)
 *
 * The version check lives inside the UPDATE. A request that read the account
 * and then stalled cannot write after somebody else moved it: the database
 * compares the stored version itself and affects zero rows, so the caller
 * re-reads and retries. This is why there is no lease, no TTL and no lock to
 * expire any more — the failure mode where a stale owner keeps writing after
 * its lock was taken over no longer exists in this design, because there is
 * no lock to take over.
 *
 * The ledger and the account can never disagree: they are written in the same
 * transaction, so a crash at any point rolls both back, and a movement is
 * never "undone afterwards" by deleting a row (the older implementation could
 * delete a ledger row that another request had already counted).
 *
 * Legacy data (2.5.x and older) lives in user meta and is migrated lazily,
 * once per user, on first use; nothing is deleted.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Point balances and ledger.
 */
class AT8_MLUC_Points {

	/**
	 * Legacy balance mirror. Still written for older integrations and for the
	 * privacy exporter, but never read as the source of truth.
	 *
	 * @var string
	 */
	const BALANCE_META = 'at8_mluc_points';

	/**
	 * Legacy single-meta ledger (an array) written before 2.5.7.
	 *
	 * @var string
	 */
	const LEDGER_META = 'at8_mluc_points_log';

	/**
	 * Legacy per-row ledger entries written by 2.5.7 – 2.5.9.
	 *
	 * @var string
	 */
	const ENTRY_META = 'at8_mluc_points_entry';

	/**
	 * Option holding the installed storage version.
	 *
	 * @var string
	 */
	const DB_OPTION = 'at8_mluc_points_db';

	/**
	 * Current storage version. Bump when the schema changes.
	 *
	 * @var int
	 */
	const DB_VERSION = 1;

	/**
	 * How often a movement retries when another request moved the account
	 * between its read and its write.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 40;

	/**
	 * Base back-off between attempts, in microseconds.
	 *
	 * @var int
	 */
	const RETRY_USLEEP = 15000;

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Points|null
	 */
	private static $instance = null;

	/**
	 * Whether the tables were confirmed during this request.
	 *
	 * @var bool
	 */
	private static $schema_ok = false;

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

	/* ---------------------------------------------------------------------
	 * Schema
	 * ------------------------------------------------------------------ */

	/**
	 * Create or upgrade the two tables.
	 *
	 * Both are InnoDB so the account update and the ledger insert belong to
	 * one transaction. dbDelta is idempotent, so this is safe to call on
	 * activation and again whenever DB_VERSION moves.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$ledger  = $wpdb->prefix . 'at8_mluc_points_ledger';
		$account = $wpdb->prefix . 'at8_mluc_points_account';

		dbDelta(
			"CREATE TABLE {$ledger} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  amount int(11) NOT NULL DEFAULT 0,
  reason varchar(191) NOT NULL DEFAULT '',
  source varchar(32) NOT NULL DEFAULT '',
  by_user bigint(20) unsigned NOT NULL DEFAULT 0,
  idem_key varchar(150) DEFAULT NULL,
  fence bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY idem_key (user_id,idem_key),
  KEY user_id (user_id,id)
) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$account} (
  user_id bigint(20) unsigned NOT NULL,
  balance bigint(20) unsigned NOT NULL DEFAULT 0,
  fence bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (user_id)
) ENGINE=InnoDB {$charset};"
		);

		self::$schema_ok = true;

		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Install the tables when the stored schema version is behind.
	 *
	 * Runs on `plugins_loaded` before anything can touch a balance, so an
	 * in-place plugin update gets its tables without re-activation.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::DB_OPTION, 0 ) === self::DB_VERSION ) {
			self::$schema_ok = true;

			return;
		}

		self::install();
	}

	/**
	 * Rebuild a user's account row from their ledger rows.
	 *
	 * The account row moves only through the versioned UPDATE, so it cannot
	 * drift on its own. This exists for recovery (a row deleted by hand, a
	 * database restored without the account table) and for the test suite.
	 *
	 * @param int $user_id User ID.
	 * @return int Rebuilt balance.
	 */
	public static function reconcile( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Summing the ledger is the authoritative recompute.
		$sum = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE( SUM( amount ), 0 ) FROM {$wpdb->prefix}at8_mluc_points_ledger WHERE user_id = %d", $user_id )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the row this repair is about to rewrite.
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}at8_mluc_points_account WHERE user_id = %d", $user_id )
		);

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repair write.
			$wpdb->query(
				$wpdb->prepare( "UPDATE {$wpdb->prefix}at8_mluc_points_account SET balance = %d, updated_at = %d WHERE user_id = %d", $sum, time(), $user_id )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repair write.
			$wpdb->query(
				$wpdb->prepare( "INSERT INTO {$wpdb->prefix}at8_mluc_points_account (user_id, balance, fence, updated_at) VALUES (%d, %d, 0, %d)", $user_id, $sum, time() )
			);
		}

		self::sync_mirror( $user_id, $sum );

		return $sum;
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * Balance for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function balance( $user_id ) {
		$account = self::account( (int) $user_id );

		return max( 0, (int) $account[0] );
	}

	/**
	 * Ledger entries, newest first.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit  Maximum entries to return.
	 * @return array<int, array<string, mixed>>
	 */
	public static function ledger( $user_id, $limit = 20 ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$limit   = max( 1, (int) $limit );

		// Make sure legacy meta was migrated before reading the table.
		self::account( $user_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only table, read straight from the database.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, amount, reason, source, by_user, idem_key, created_at FROM {$wpdb->prefix}at8_mluc_points_ledger WHERE user_id = %d ORDER BY id DESC LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);

		$entries = array();

		foreach ( (array) $rows as $row ) {
			$entries[] = array(
				'time'   => (int) $row['created_at'],
				'amount' => (int) $row['amount'],
				'reason' => (string) $row['reason'],
				'source' => (string) $row['source'],
				'by'     => (int) $row['by_user'],
				'key'    => (string) $row['idem_key'],
				'u'      => (string) $row['id'],
			);
		}

		return $entries;
	}

	/**
	 * Whether a movement with the given idempotency key was recorded.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Idempotency key.
	 * @return bool
	 */
	public static function has_entry( $user_id, $key ) {
		global $wpdb;

		$key = (string) $key;

		if ( '' === $key ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The UNIQUE index answers this in the database; a cached answer could be stale.
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}at8_mluc_points_ledger WHERE user_id = %d AND idem_key = %s LIMIT 1", (int) $user_id, $key )
		);
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Add or remove points.
	 *
	 * With a key the movement happens at most once per user, enforced by the
	 * UNIQUE (user_id, idem_key) index: a duplicate callback loses the INSERT,
	 * rolls back its own balance change and reports the balance that the
	 * winning request produced.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $amount  Signed amount.
	 * @param string $reason  Human-readable reason.
	 * @param string $source  Machine-readable source key.
	 * @param string $key     Optional idempotency key for this movement.
	 * @return int|WP_Error New balance, or an error when nothing was recorded.
	 */
	public static function adjust( $user_id, $amount, $reason = '', $source = 'manual', $key = '' ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$amount  = (int) $amount;
		$key     = (string) $key;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'at8_mluc_points_user', __( 'Unknown user.', 'at8-moonlight-user-center' ) );
		}

		if ( 0 === $amount ) {
			return self::balance( $user_id );
		}

		$reason    = sanitize_text_field( (string) $reason );
		$source    = sanitize_key( (string) $source );
		$by        = get_current_user_id();
		$processed = time();

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			list( $balance, $fence ) = self::account( $user_id );

			// Already applied — a duplicate callback, or a retry after a crash
			// that happened after the commit. Nothing to write.
			if ( '' !== $key && self::has_entry( $user_id, $key ) ) {
				return $balance;
			}

			if ( $amount < 0 && $balance + $amount < 0 ) {
				return new WP_Error( 'at8_mluc_points_balance', __( 'That would take the balance below zero.', 'at8-moonlight-user-center' ) );
			}

			$next = $fence + 1;

			self::begin();

			/*
			 * The version is part of the WHERE clause, so ownership at write
			 * time is decided by the database, not by a check this request
			 * did a moment earlier. A request whose account snapshot is stale
			 * affects zero rows here — it cannot overwrite a newer balance.
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional account update; the meta API cannot express compare-and-swap plus an overdraft guard.
			$moved = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}at8_mluc_points_account SET balance = balance + %d, fence = %d, updated_at = %d WHERE user_id = %d AND fence = %d AND balance + %d >= 0",
					$amount,
					$next,
					$processed,
					$user_id,
					$fence,
					$amount
				)
			);

			if ( 1 !== (int) $moved ) {
				self::rollback();

				list( $now, $now_fence ) = self::account( $user_id );

				if ( $amount < 0 && $now + $amount < 0 ) {
					return new WP_Error( 'at8_mluc_points_balance', __( 'That would take the balance below zero.', 'at8-moonlight-user-center' ) );
				}

				if ( $now_fence === $fence ) {
					// Nothing moved yet the statement matched nothing: the
					// storage layer refused the write.
					return self::save_error();
				}

				// Somebody else moved the account in between: read again.
				usleep( (int) ( self::RETRY_USLEEP * min( $attempt, 4 ) ) );
				continue;
			}

			$entry_id = self::insert_movement( $user_id, $amount, $reason, $source, $by, $key, $next, $processed );

			if ( false === $entry_id ) {
				// The ledger row did not land, so the balance change must not
				// survive either: one transaction, one outcome.
				self::rollback();

				if ( '' !== $key && self::has_entry( $user_id, $key ) ) {
					return self::balance( $user_id );
				}

				return self::save_error();
			}

			self::commit();

			$new_balance = $balance + $amount;

			self::sync_mirror( $user_id, $new_balance );

			$entry = array(
				'time'   => $processed,
				'amount' => $amount,
				'reason' => $reason,
				'source' => $source,
				'by'     => $by,
				'key'    => $key,
				'u'      => (string) $entry_id,
			);

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

			return $new_balance;
		}

		return new WP_Error(
			'at8_mluc_points_busy',
			__( 'The points account is busy. Please try again in a moment.', 'at8-moonlight-user-center' )
		);
	}

	/**
	 * Award points for a completed order, based on the configured
	 * points-per-currency rate.
	 *
	 * The order key is the idempotency key, so concurrent callbacks for the
	 * same order produce exactly one ledger row — the UNIQUE index decides,
	 * not the application.
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

		// Compute first: an invalid amount or a zero result returns without
		// touching any state, so a corrected order can be awarded later.
		// The price comes from the order record; client input is never trusted.
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

		$result = self::adjust( $user_id, $points, $reason, 'purchase', 'purchase:' . $order_id );

		if ( is_wp_error( $result ) ) {
			return;
		}

		// Fast-path marker for later triggers. It is only an optimisation: the
		// idempotency key and the account version are what prevent a re-award.
		add_post_meta( $order_id, '_at8_mluc_points_awarded', 1, true );
	}

	/**
	 * Grant the configured signup bonus.
	 *
	 * @param int $user_id New user ID.
	 * @return void
	 */
	public function grant_signup_bonus( $user_id ) {
		$user_id = (int) $user_id;
		$bonus   = self::signup_bonus();

		if ( $bonus <= 0 || $user_id <= 0 ) {
			return;
		}

		self::adjust( $user_id, $bonus, __( 'Welcome bonus', 'at8-moonlight-user-center' ), 'signup', 'signup:' . $user_id );
	}

	/* ---------------------------------------------------------------------
	 * Account row
	 * ------------------------------------------------------------------ */

	/**
	 * Read a user's account row, migrating legacy meta the first time.
	 *
	 * @param int $user_id User ID.
	 * @return array{0:int,1:int} Balance and version.
	 */
	private static function account( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return array( 0, 0 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must observe the row another process just committed.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT balance, fence FROM {$wpdb->prefix}at8_mluc_points_account WHERE user_id = %d", $user_id ),
			ARRAY_A
		);

		if ( is_array( $row ) ) {
			return array( (int) $row['balance'], (int) $row['fence'] );
		}

		return self::migrate_user( $user_id );
	}

	/**
	 * Create the account row for a user and import whatever the legacy meta
	 * holds. Runs at most once per user; the PRIMARY KEY on user_id makes the
	 * creation itself the mutual exclusion.
	 *
	 * @param int $user_id User ID.
	 * @return array{0:int,1:int} Balance and version.
	 */
	private static function migrate_user( $user_id ) {
		global $wpdb;

		$entries = self::legacy_entries( $user_id );
		$total   = 0;

		foreach ( $entries as $entry ) {
			$total += (int) $entry['amount'];
		}

		$mirror  = get_user_meta( $user_id, self::BALANCE_META, true );
		$opening = 0;

		/*
		 * Very old installs only stored a balance. Import it as an opening
		 * movement so the ledger still explains the number.
		 */
		if ( empty( $entries ) && is_numeric( $mirror ) && (int) $mirror > 0 ) {
			$opening = (int) $mirror;
		}

		$balance = $total + $opening;

		self::begin();

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Creating the account row is the migration's mutual exclusion; a cached API cannot express it.
		$created = $wpdb->insert(
			$wpdb->prefix . 'at8_mluc_points_account',
			array(
				'user_id'    => $user_id,
				'balance'    => 0,
				'fence'      => 0,
				'updated_at' => time(),
			),
			array( '%d', '%d', '%d', '%d' )
		);

		$wpdb->suppress_errors( $suppress );

		if ( ! $created ) {
			// Another request created it first. Its transaction may still be
			// in flight, so retry the read briefly before giving up.
			self::rollback();

			for ( $i = 0; $i < 5; $i++ ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the row the other request is committing.
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT balance, fence FROM {$wpdb->prefix}at8_mluc_points_account WHERE user_id = %d", $user_id ),
					ARRAY_A
				);

				if ( is_array( $row ) ) {
					return array( (int) $row['balance'], (int) $row['fence'] );
				}

				usleep( 20000 );
			}

			return array( $balance, 0 );
		}

		$when = time();

		foreach ( $entries as $index => $entry ) {
			/*
			 * Keep the original idempotency key: an order that was already
			 * awarded before the upgrade must stay awarded afterwards, so a
			 * repeated payment callback does not pay out twice.
			 */
			$key = (string) $entry['key'];

			if ( '' !== $key && self::insert_movement( $user_id, (int) $entry['amount'], (string) $entry['reason'], (string) $entry['source'], (int) $entry['by'], $key, 0, (int) $entry['time'] > 0 ? (int) $entry['time'] : $when ) ) {
				continue;
			}

			if ( '' === $key ) {
				self::insert_movement(
					$user_id,
					(int) $entry['amount'],
					(string) $entry['reason'],
					(string) $entry['source'],
					(int) $entry['by'],
					'',
					0,
					(int) $entry['time'] > 0 ? (int) $entry['time'] : $when
				);

				continue;
			}

			// The same key appears twice in the old data: keep both movements
			// by giving the second one a unique key of its own.
			$fingerprint = 'legacy:' . md5( $user_id . '|' . $index . '|' . (int) $entry['time'] . '|' . (string) $entry['u'] );

			self::insert_movement(
				$user_id,
				(int) $entry['amount'],
				(string) $entry['reason'],
				(string) $entry['source'],
				(int) $entry['by'],
				$fingerprint,
				0,
				(int) $entry['time'] > 0 ? (int) $entry['time'] : $when
			);
		}

		if ( 0 !== $opening ) {
			self::insert_movement(
				$user_id,
				$opening,
				__( 'Opening balance', 'at8-moonlight-user-center' ),
				'migrate',
				0,
				'legacy:opening:' . $user_id,
				0,
				$when
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Setting the freshly created account row.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}at8_mluc_points_account SET balance = %d, updated_at = %d WHERE user_id = %d",
				$balance,
				$when,
				$user_id
			)
		);

		self::commit();

		self::sync_mirror( $user_id, $balance );

		return array( $balance, 0 );
	}

	/**
	 * Legacy movements from the pre-2.6 user meta, oldest first.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function legacy_entries( $user_id ) {
		$entries = array();

		$legacy = get_user_meta( $user_id, self::LEDGER_META, true );

		if ( is_array( $legacy ) ) {
			foreach ( $legacy as $row ) {
				if ( is_array( $row ) && isset( $row['amount'] ) ) {
					$entries[] = self::normalise_entry( $row );
				}
			}
		}

		$rows = get_user_meta( $user_id, self::ENTRY_META, false );

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && isset( $row['amount'] ) ) {
					$entries[] = self::normalise_entry( $row );
				}
			}
		}

		usort(
			$entries,
			function ( $a, $b ) {
				return (int) $a['time'] <=> (int) $b['time'];
			}
		);

		return $entries;
	}

	/**
	 * Fill the gaps in a legacy row.
	 *
	 * @param array<string, mixed> $row Legacy row.
	 * @return array<string, mixed>
	 */
	private static function normalise_entry( $row ) {
		return array(
			'time'   => isset( $row['time'] ) ? (int) $row['time'] : 0,
			'amount' => (int) $row['amount'],
			'reason' => isset( $row['reason'] ) ? (string) $row['reason'] : '',
			'source' => isset( $row['source'] ) ? sanitize_key( (string) $row['source'] ) : 'legacy',
			'by'     => isset( $row['by'] ) ? (int) $row['by'] : 0,
			'key'    => isset( $row['key'] ) ? (string) $row['key'] : '',
			'u'      => isset( $row['u'] ) ? (string) $row['u'] : '',
		);
	}

	/**
	 * Append one movement to the ledger.
	 *
	 * A UNIQUE (user_id, idem_key) index turns "this already happened" into a
	 * database error instead of a race between two PHP requests. Rows without
	 * a key store NULL, which the index treats as distinct in both MySQL and
	 * SQLite.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $amount  Signed amount.
	 * @param string $reason  Reason.
	 * @param string $source  Source key.
	 * @param int    $by      Acting user ID.
	 * @param string $key     Idempotency key ('' for none).
	 * @param int    $fence   Account version this movement was applied at.
	 * @param int    $when    Unix timestamp.
	 * @return int|false Row ID, or false when nothing was written.
	 */
	private static function insert_movement( $user_id, $amount, $reason, $source, $by, $key, $fence, $when ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only insert; the UNIQUE index is the idempotency guard.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}at8_mluc_points_ledger (user_id, amount, reason, source, by_user, idem_key, fence, created_at) VALUES (%d, %d, %s, %s, %d, NULLIF(%s, ''), %d, %d)",
				(int) $user_id,
				(int) $amount,
				(string) $reason,
				(string) $source,
				(int) $by,
				(string) $key,
				(int) $fence,
				(int) $when
			)
		);

		$row_id = (int) $wpdb->insert_id;

		$wpdb->suppress_errors( $suppress );

		if ( false === $inserted || $inserted < 1 ) {
			return false;
		}

		return $row_id;
	}

	/**
	 * Error returned when a movement could not be stored.
	 *
	 * @return WP_Error
	 */
	private static function save_error() {
		return new WP_Error( 'at8_mluc_points_save', __( 'The points update could not be saved, please retry.', 'at8-moonlight-user-center' ) );
	}

	/**
	 * Keep the legacy balance meta roughly in step.
	 *
	 * Nothing reads it as the truth any more — the account row is the truth —
	 * but older integrations and the privacy exporter still expect it, so it
	 * is refreshed after every committed movement. A failure here is not an
	 * error: the movement itself is already durable.
	 *
	 * @param int $user_id User ID.
	 * @param int $balance Balance to mirror.
	 * @return void
	 */
	private static function sync_mirror( $user_id, $balance ) {
		update_user_meta( $user_id, self::BALANCE_META, (int) $balance );
	}

	/* ---------------------------------------------------------------------
	 * Transactions
	 * ------------------------------------------------------------------ */

	/**
	 * Open a transaction.
	 *
	 * @return void
	 */
	private static function begin() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control is not part of the query API.
		$wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Commit the current transaction.
	 *
	 * @return void
	 */
	private static function commit() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control is not part of the query API.
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * Roll the current transaction back.
	 *
	 * @return void
	 */
	private static function rollback() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control is not part of the query API.
		$wpdb->query( 'ROLLBACK' );
	}

	/* ---------------------------------------------------------------------
	 * Presentation helpers
	 * ------------------------------------------------------------------ */

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
