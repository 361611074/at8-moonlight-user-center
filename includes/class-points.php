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
	 * Which transaction this request opened: '' (none), 'own' (START
	 * TRANSACTION) or 'savepoint' (nested inside a transaction another plugin
	 * had already opened, in which case committing would destroy their work).
	 *
	 * @var string
	 */
	private static $tx_mode = '';

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
	 * Create or upgrade the two tables, then prove they are usable.
	 *
	 * Both are InnoDB so the account update and the ledger insert belong to
	 * one transaction. dbDelta is idempotent, so this is safe to call on
	 * activation and again whenever DB_VERSION moves.
	 *
	 * The schema option is only written when the verification passes: a
	 * silently failed dbDelta must not leave the site believing the tables
	 * exist.
	 *
	 * @return true|WP_Error
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

		$problems = self::schema_problems();

		if ( $problems ) {
			self::$schema_ok = false;

			return new WP_Error(
				'at8_mluc_points_schema',
				sprintf(
					/* translators: %s: semicolon separated list of schema problems. */
					__( 'The points tables are not usable: %s', 'at8-moonlight-user-center' ),
					implode( '; ', array_slice( $problems, 0, 4 ) )
				)
			);
		}

		self::$schema_ok = true;

		update_option( self::DB_OPTION, self::DB_VERSION, false );

		return true;
	}

	/**
	 * Everything that must be true for the two tables to be safe to write to.
	 *
	 * @return array<int, string> Human readable problems, empty when fine.
	 */
	private static function schema_problems() {
		global $wpdb;

		$problems = array();
		$ledger   = $wpdb->prefix . 'at8_mluc_points_ledger';
		$account  = $wpdb->prefix . 'at8_mluc_points_account';

		foreach ( array( $ledger, $account ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifying the plugin's own schema.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $table !== $exists ) {
				/* translators: %s: table name. */
				$problems[] = sprintf( __( 'table %s is missing', 'at8-moonlight-user-center' ), $table );
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Engine check.
			$status = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );

			if ( is_array( $status ) && isset( $status[0]['Engine'] ) && '' !== $status[0]['Engine'] && 0 !== strcasecmp( 'InnoDB', (string) $status[0]['Engine'] ) ) {
				/* translators: 1: table name, 2: storage engine. */
				$problems[] = sprintf( __( 'table %1$s uses %2$s instead of InnoDB', 'at8-moonlight-user-center' ), $table, (string) $status[0]['Engine'] );
			}
		}

		if ( $problems ) {
			return $problems;
		}

		// The unique index is what makes "award once" a database guarantee.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Index check on the plugin's own table.
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}at8_mluc_points_ledger", ARRAY_A );
		$unique  = array();

		foreach ( (array) $indexes as $index ) {
			if ( 0 === (int) $index['Non_unique'] && 'PRIMARY' !== $index['Key_name'] ) {
				$unique[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = strtolower( (string) $index['Column_name'] );
			}
		}

		$has_idem = false;

		foreach ( $unique as $columns ) {
			ksort( $columns );

			if ( array( 'user_id', 'idem_key' ) === array_values( $columns ) ) {
				$has_idem = true;
			}
		}

		if ( ! $has_idem ) {
			$problems[] = sprintf(
				/* translators: %s: table name. */
				__( 'table %s has no UNIQUE (user_id, idem_key) index', 'at8-moonlight-user-center' ),
				$ledger
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Primary key check on the plugin's own table.
		$account_keys = $wpdb->get_results( "SHOW KEYS FROM {$wpdb->prefix}at8_mluc_points_account", ARRAY_A );
		$has_pk       = false;

		foreach ( (array) $account_keys as $key ) {
			if ( 'PRIMARY' === $key['Key_name'] && 'user_id' === strtolower( (string) $key['Column_name'] ) ) {
				$has_pk = true;
			}
		}

		if ( ! $has_pk ) {
			$problems[] = sprintf(
				/* translators: %s: table name. */
				__( 'table %s has no PRIMARY KEY (user_id)', 'at8-moonlight-user-center' ),
				$account
			);
		}

		$problems = array_merge( $problems, self::column_problems( 'ledger', self::ledger_columns() ) );
		$problems = array_merge( $problems, self::column_problems( 'account', self::account_columns() ) );

		return $problems;
	}

	/**
	 * Column name => expected type prefix for the ledger table.
	 *
	 * @return array<string, string>
	 */
	private static function ledger_columns() {
		return array(
			'id'         => 'bigint',
			'user_id'    => 'bigint',
			'amount'     => 'int',
			'reason'     => 'varchar',
			'source'     => 'varchar',
			'by_user'    => 'bigint',
			'idem_key'   => 'varchar',
			'fence'      => 'bigint',
			'created_at' => 'bigint',
		);
	}

	/**
	 * Column name => expected type prefix for the account table.
	 *
	 * @return array<string, string>
	 */
	private static function account_columns() {
		return array(
			'user_id'    => 'bigint',
			'balance'    => 'bigint',
			'fence'      => 'bigint',
			'updated_at' => 'bigint',
		);
	}

	/**
	 * Compare a table's real columns with the ones the writer expects.
	 *
	 * Without this a renamed column would only show up as a failed movement
	 * at runtime, long after the migration claimed to be done.
	 *
	 * @param string                $table   Table name.
	 * @param array<string, string> $expected Column => type prefix.
	 * @return array<int, string>
	 */
	private static function column_problems( $which, $expected ) {
		global $wpdb;

		if ( 'ledger' === $which ) {
			$table = $wpdb->prefix . 'at8_mluc_points_ledger';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Column check on the plugin's own table.
			$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}at8_mluc_points_ledger", ARRAY_A );
		} else {
			$table = $wpdb->prefix . 'at8_mluc_points_account';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Column check on the plugin's own table.
			$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}at8_mluc_points_account", ARRAY_A );
		}

		$actual   = array();
		$problems = array();

		foreach ( (array) $rows as $row ) {
			$actual[ strtolower( (string) $row['Field'] ) ] = strtolower( (string) $row['Type'] );
		}

		foreach ( $expected as $column => $type ) {
			if ( ! isset( $actual[ $column ] ) ) {
				$problems[] = sprintf(
					/* translators: 1: table name, 2: column name. */
					__( 'table %1$s has no %2$s column', 'at8-moonlight-user-center' ),
					$table,
					$column
				);

				continue;
			}

			if ( 0 !== strpos( $actual[ $column ], $type ) ) {
				$problems[] = sprintf(
					/* translators: 1: table name, 2: column name, 3: expected type. */
					__( 'column %1$s.%2$s is not %3$s', 'at8-moonlight-user-center' ),
					$table,
					$column,
					$type
				);
			}
		}

		return $problems;
	}

	/**
	 * Confirm the schema once per request, repairing it if it is broken.
	 *
	 * Called before any write, so a site whose tables did not survive a
	 * migration gets a clear error instead of a silent no-op.
	 *
	 * @return true|WP_Error
	 */
	private static function schema_ready() {
		if ( self::$schema_ok ) {
			return true;
		}

		if ( ! self::schema_problems() ) {
			self::$schema_ok = true;

			return true;
		}

		// One repair attempt: dbDelta recreates a missing table and adds a
		// missing index. install() re-verifies before claiming success.
		$repaired = self::install();

		return $repaired;
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
			// The stored version only says "dbDelta ran once". The tables are
			// verified lazily, before the first write, by schema_ready().
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
	 * The rebuild goes through the same versioned UPDATE every movement uses:
	 * it reads the sum and the version, then writes only if the version is
	 * still the one it read. A movement that lands in between moves the
	 * version, the repair loses the race and starts over with fresh numbers —
	 * it can never overwrite a newer balance with an older snapshot.
	 *
	 * @param int $user_id User ID.
	 * @return int|WP_Error Rebuilt balance, or an error.
	 */
	public static function reconcile( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;

		$ready = self::schema_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$account = self::account( $user_id );

			if ( is_wp_error( $account ) ) {
				return $account;
			}

			list( , $fence ) = $account;

			if ( ! self::begin() ) {
				return self::tx_error();
			}

			// Sum inside the transaction: once the UPDATE below has taken the
			// row, no other writer can change the ledger behind our back.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Summing the ledger is the authoritative recompute.
			$sum = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COALESCE( SUM( amount ), 0 ) FROM {$wpdb->prefix}at8_mluc_points_ledger WHERE user_id = %d", $user_id )
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repair write, guarded by the row version.
			$moved = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}at8_mluc_points_account SET balance = %d, fence = %d, updated_at = %d WHERE user_id = %d AND fence = %d",
					$sum,
					$fence + 1,
					time(),
					$user_id,
					$fence
				)
			);

			if ( 1 !== (int) $moved ) {
				// A movement was applied while this repair was running: its
				// snapshot is newer than ours, so start over instead of
				// overwriting it.
				self::rollback();
				usleep( (int) ( self::RETRY_USLEEP * min( $attempt, 4 ) ) );
				continue;
			}

			if ( ! self::commit() ) {
				return self::tx_error();
			}

			self::sync_mirror( $user_id, $sum );

			return $sum;
		}

		return new WP_Error(
			'at8_mluc_points_busy',
			__( 'The points account is busy. Please try again in a moment.', 'at8-moonlight-user-center' )
		);
	}

	/**
	 * Whether the stored balance still equals the sum of the movements.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function verify_balance( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$account = self::account( $user_id );

		if ( is_wp_error( $account ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Consistency check.
		$sum = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE( SUM( amount ), 0 ) FROM {$wpdb->prefix}at8_mluc_points_ledger WHERE user_id = %d", $user_id )
		);

		return (int) $account[0] === $sum;
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

		if ( is_wp_error( $account ) ) {
			// The migration could not run (missing table, broken write). Show
			// the legacy total rather than a wrong zero, and write nothing.
			return max( 0, self::legacy_total( (int) $user_id ) );
		}

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
		if ( is_wp_error( self::account( $user_id ) ) ) {
			return array();
		}

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

		$ready = self::schema_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$reason    = sanitize_text_field( (string) $reason );
		$source    = sanitize_key( (string) $source );
		$by        = get_current_user_id();
		$processed = time();

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$account = self::account( $user_id );

			if ( is_wp_error( $account ) ) {
				return $account;
			}

			list( $balance, $fence ) = $account;

			// Already applied — a duplicate callback, or a retry after a crash
			// that happened after the commit. Nothing to write.
			if ( '' !== $key && self::has_entry( $user_id, $key ) ) {
				return $balance;
			}

			if ( $amount < 0 && $balance + $amount < 0 ) {
				return new WP_Error( 'at8_mluc_points_balance', __( 'That would take the balance below zero.', 'at8-moonlight-user-center' ) );
			}

			$next = $fence + 1;

			// Without a transaction the balance and the ledger could drift
			// apart, so a failed BEGIN means "do nothing at all".
			if ( ! self::begin() ) {
				return self::tx_error();
			}

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

				$current = self::account( $user_id );

				if ( is_wp_error( $current ) ) {
					return $current;
				}

				list( $now, $now_fence ) = $current;

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

			$db_error = '';
			$entry_id = self::insert_movement( $user_id, $amount, $reason, $source, $by, $key, $next, $processed, $db_error );

			if ( false === $entry_id ) {
				// The ledger row did not land, so the balance change must not
				// survive either: one transaction, one outcome.
				self::rollback();

				// A concurrent request with the same key won the race: report
				// the balance it produced. Anything else is a real failure.
				if ( self::is_duplicate_error( $user_id, $key, $db_error ) ) {
					return self::balance( $user_id );
				}

				return self::save_error();
			}

			// A failed COMMIT means the movement is not durable: report an
			// error and never fire the "balance changed" action for it.
			if ( ! self::commit() ) {
				return self::tx_error();
			}

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
	 * @return array{0:int,1:int}|WP_Error Balance and version, or an error when
	 *                                         the account could not be created.
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
	 * The import is all-or-nothing: every movement is checked, and the account
	 * balance is only written once all of them are in. Any failure — a real
	 * database error, an unwritable movement, a refused balance update, a
	 * failed COMMIT — rolls the whole thing back, so a half-migrated account
	 * can never exist.
	 *
	 * @param int $user_id User ID.
	 * @return array{0:int,1:int}|WP_Error Balance and version, or an error.
	 */
	private static function migrate_user( $user_id ) {
		global $wpdb;

		$ready = self::schema_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

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

		if ( ! self::begin() ) {
			return self::tx_error();
		}

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

		$create_error = (string) $wpdb->last_error;

		$wpdb->suppress_errors( $suppress );

		if ( ! $created ) {
			// Another request may have created the row a moment ago and not
			// committed it yet, so give it a short window before deciding
			// that this is a real failure.
			self::rollback();

			for ( $i = 0; $i < 10; $i++ ) {
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

			// Not a race: the row simply could not be created.
			return new WP_Error(
				'at8_mluc_points_schema',
				sprintf(
					/* translators: %s: database error message. */
					__( 'The points account could not be created: %s', 'at8-moonlight-user-center' ),
					self::clean_error( $create_error )
				)
			);
		}

		$when = time();

		foreach ( $entries as $index => $entry ) {
			if ( ! self::migrate_movement( $user_id, $entry, (int) $index, $when ) ) {
				// One movement is missing, so the history would be incomplete:
				// abandon the whole migration instead of committing a partial
				// copy of it.
				self::rollback();

				return self::save_error();
			}
		}

		if ( 0 !== $opening ) {
			$opening_entry = array(
				'time'   => $when,
				'amount' => $opening,
				'reason' => __( 'Opening balance', 'at8-moonlight-user-center' ),
				'source' => 'migrate',
				'by'     => 0,
				'key'    => 'legacy:opening:' . $user_id,
				'u'      => 'opening',
			);

			if ( ! self::migrate_movement( $user_id, $opening_entry, -1, $when ) ) {
				self::rollback();

				return self::save_error();
			}
		}

		// The balance is only written once every movement is in place.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Setting the freshly created account row.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}at8_mluc_points_account SET balance = %d, updated_at = %d WHERE user_id = %d AND fence = 0",
				$balance,
				$when,
				$user_id
			)
		);

		if ( 1 !== (int) $updated ) {
			self::rollback();

			return self::save_error();
		}

		if ( ! self::commit() ) {
			// Nothing is reported as migrated unless the commit went through.
			return self::tx_error();
		}

		self::sync_mirror( $user_id, $balance );

		return array( $balance, 0 );
	}

	/**
	 * Import one legacy movement.
	 *
	 * The original idempotency key is kept, so an order that was already
	 * awarded before the upgrade stays awarded.
	 *
	 * The old data may use the same key twice, and the second movement then
	 * needs a key of its own. That is decided by looking the key up first:
	 * deliberately provoking a UNIQUE violation would abort the surrounding
	 * transaction on drivers that roll back the whole transaction on any
	 * statement error (the SQLite integration does), which would leave a
	 * half-migrated account behind.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $entry   Normalised legacy entry.
	 * @param int                 $index   Position in the legacy list.
	 * @param int                 $when    Fallback timestamp.
	 * @return bool Whether the movement is stored.
	 */
	private static function migrate_movement( $user_id, $entry, $index, $when ) {
		$amount = (int) $entry['amount'];
		$reason = (string) $entry['reason'];
		$source = (string) $entry['source'];
		$by     = (int) $entry['by'];
		$time   = (int) $entry['time'] > 0 ? (int) $entry['time'] : (int) $when;
		$key    = (string) $entry['key'];

		if ( '' !== $key && self::has_entry( $user_id, $key ) ) {
			// The same key appears more than once in the old history: keep
			// both movements by giving this one a unique key.
			$key = 'legacy:' . md5( $user_id . '|' . (int) $index . '|' . $time . '|' . (string) $entry['u'] );
		}

		$error = '';

		return (bool) self::insert_movement( $user_id, $amount, $reason, $source, $by, $key, 0, $time, $error );
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
	 * @param string $error   Filled with the database error when the write fails.
	 * @return int|false Row ID, or false when nothing was written.
	 */
	private static function insert_movement( $user_id, $amount, $reason, $source, $by, $key, $fence, $when, &$error = null ) {
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

		$error  = (string) $wpdb->last_error;
		$row_id = (int) $wpdb->insert_id;

		$wpdb->suppress_errors( $suppress );

		if ( false === $inserted || $inserted < 1 ) {
			return false;
		}

		$error = '';

		return $row_id;
	}

	/**
	 * Whether a failed INSERT lost against the UNIQUE (user_id, idem_key) index.
	 *
	 * Anything else — a missing table, a renamed column, a dropped connection
	 * — is a real failure and must never be mistaken for "already recorded".
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Idempotency key of the failed insert.
	 * @param string $error   Raw driver error.
	 * @return bool
	 */
	private static function is_duplicate_error( $user_id, $key, $error ) {
		if ( '' === (string) $key ) {
			return false;
		}

		$clean = self::clean_error( $error );

		if ( preg_match( '/duplicate (entry|key)|unique constraint|errno:? ?1062/i', $clean ) ) {
			return true;
		}

		// Some drivers describe it differently; a row with this exact key
		// already present is proof that the INSERT collided on the index.
		return self::has_entry( $user_id, $key );
	}

	/**
	 * One-line, markup-free version of a driver error, safe to show.
	 *
	 * @param string $error Raw error.
	 * @return string
	 */
	private static function clean_error( $error ) {
		$clean = wp_strip_all_tags( (string) $error );
		$clean = trim( preg_replace( '/\s+/', ' ', $clean ) );

		return substr( $clean, 0, 200 );
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
	 * If another plugin already has a transaction open, starting our own would
	 * silently commit theirs, so a savepoint is used instead and only our own
	 * statements are ever rolled back.
	 *
	 * @return bool Whether the scope was opened.
	 */
	private static function begin() {
		if ( '' !== self::$tx_mode ) {
			// Nested scope: never needed by this class, and silently joining
			// an outer transaction would make the caller's rollback wrong.
			return false;
		}

		$mode = self::has_open_transaction() ? 'savepoint' : 'own';
		$sql  = 'savepoint' === $mode ? 'SAVEPOINT at8_mluc_points_tx' : 'START TRANSACTION';

		if ( ! self::run_sql( $sql ) ) {
			return false;
		}

		self::$tx_mode = $mode;

		return true;
	}

	/**
	 * Commit the current scope. The result decides whether the caller may
	 * report success.
	 *
	 * @return bool Whether the work is durable.
	 */
	private static function commit() {
		if ( '' === self::$tx_mode ) {
			return false;
		}

		$mode          = self::$tx_mode;
		self::$tx_mode = '';

		$committed = self::run_sql( 'savepoint' === $mode ? 'RELEASE SAVEPOINT at8_mluc_points_tx' : 'COMMIT' );

		if ( ! $committed && 'own' === $mode ) {
			// A refused COMMIT can leave the transaction open; close it so the
			// rest of the request is not writing inside a phantom transaction.
			self::run_sql( 'ROLLBACK' );
		}

		return $committed;
	}

	/**
	 * Undo the current scope.
	 *
	 * @return bool Whether the rollback was accepted.
	 */
	private static function rollback() {
		if ( '' === self::$tx_mode ) {
			return false;
		}

		$mode          = self::$tx_mode;
		self::$tx_mode = '';

		return self::run_sql( 'savepoint' === $mode ? 'ROLLBACK TO SAVEPOINT at8_mluc_points_tx' : 'ROLLBACK' );
	}

	/**
	 * Run a statement and tell success from failure using what the driver
	 * actually did, not just PHP's return value.
	 *
	 * A transaction statement legitimately affects zero rows, so `false` and a
	 * non-empty driver error are the failure signals — checked against the
	 * cleaned error text, because some drivers append markup to it.
	 *
	 * @param string $sql Statement.
	 * @return bool
	 */
	private static function run_sql( $sql ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only the fixed transaction statements built inside this class reach this call.
		$result = $wpdb->query( $sql );
		$error  = self::clean_error( (string) $wpdb->last_error );

		$wpdb->suppress_errors( $suppress );

		return false !== $result && '' === $error;
	}

	/**
	 * Whether somebody else already has a transaction open on this connection.
	 *
	 * MySQL 5.7.36+ and MariaDB 10.3+ answer `@@in_transaction`; older servers
	 * (and drivers that do not support it) return null, which is treated as
	 * "no transaction".
	 *
	 * @return bool
	 */
	private static function has_open_transaction() {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Server variable, no user input.
		$value = $wpdb->get_var( 'SELECT @@in_transaction' );
		$wpdb->suppress_errors( $suppress );

		return null !== $value && 1 === (int) $value;
	}

	/**
	 * Error returned when the transaction could not be opened or committed.
	 *
	 * @return WP_Error
	 */
	private static function tx_error() {
		return new WP_Error(
			'at8_mluc_points_tx',
			__( 'The points update could not be committed. Please try again.', 'at8-moonlight-user-center' )
		);
	}

	/**
	 * Legacy balance of a user, computed without touching the tables.
	 *
	 * Used only when the account could not be read or migrated: showing the
	 * number the old meta describes is better than showing zero, and it writes
	 * nothing.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private static function legacy_total( $user_id ) {
		$entries = self::legacy_entries( $user_id );
		$total   = 0;

		foreach ( $entries as $entry ) {
			$total += (int) $entry['amount'];
		}

		if ( empty( $entries ) ) {
			$mirror = get_user_meta( $user_id, self::BALANCE_META, true );

			if ( is_numeric( $mirror ) && (int) $mirror > 0 ) {
				$total = (int) $mirror;
			}
		}

		return $total;
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
