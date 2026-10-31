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
	 * Savepoint used when an outer transaction already owns the commit.
	 *
	 * @var string
	 */
	const SAVEPOINT = 'at8_mluc_points_tx';

	/**
	 * Savepoint used to find out whether somebody else owns a transaction.
	 *
	 * @since 2.6.4
	 */
	const PROBE_SAVEPOINT = 'at8_mluc_points_probe';

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
	 * Whether the last movement only released a savepoint, i.e. the commit
	 * still belongs to somebody else and nothing may be described as durable.
	 *
	 * @var bool
	 */
	private static $last_deferred = false;

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

			/*
			 * The engine decides whether the account update and the ledger
			 * insert can share a transaction at all, so "cannot tell" is a
			 * failure: an unverifiable table is treated exactly like a wrong
			 * engine instead of being waved through.
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Engine check.
			$status = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
			$error  = self::clean_error( (string) $wpdb->last_error );

			if ( '' !== $error || ! is_array( $status ) || array() === $status ) {
				/* translators: %s: table name. */
				$problems[] = sprintf( __( 'the storage engine of %s could not be verified', 'at8-moonlight-user-center' ), $table );
				continue;
			}

			if ( ! isset( $status[0]['Engine'] ) || '' === (string) $status[0]['Engine'] ) {
				/* translators: %s: table name. */
				$problems[] = sprintf( __( 'the storage engine of %s is unknown (no Engine field)', 'at8-moonlight-user-center' ), $table );
				continue;
			}

			if ( 0 !== strcasecmp( 'InnoDB', (string) $status[0]['Engine'] ) ) {
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

		$error = self::clean_error( (string) $wpdb->last_error );

		if ( '' !== $error || ! is_array( $rows ) || array() === $rows ) {
			return array(
				sprintf(
					/* translators: %s: table name. */
					__( 'the columns of %s could not be read', 'at8-moonlight-user-center' ),
					$table
				),
			);
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

			$begun = self::begin();

			if ( is_wp_error( $begun ) ) {
				return $begun;
			}

			/*
			 * The sum decides what the balance becomes, so a failed read must
			 * never be mistaken for "no movements": an empty ledger and an
			 * unreadable one both used to look like 0, which silently zeroed
			 * real balances.
			 */
			$sum = self::ledger_sum( $user_id );

			if ( is_wp_error( $sum ) ) {
				$undone = self::rollback();

				return is_wp_error( $undone ) ? $undone : $sum;
			}

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

			$moved_error = self::clean_error( (string) $wpdb->last_error );

			/*
			 * Three outcomes with three meanings. `false` is the driver
			 * refusing the statement, `0` is the version guard not matching
			 * (a movement landed while this repair held its snapshot) and `1`
			 * is the repair itself. Only the middle one is worth retrying;
			 * reporting a broken table as "busy" would hide it behind forty
			 * pointless attempts.
			 */
			if ( false === $moved || '' !== $moved_error ) {
				$undone = self::rollback();

				if ( is_wp_error( $undone ) ) {
					return $undone;
				}

				return new WP_Error(
					'at8_mluc_points_db',
					sprintf(
						/* translators: %s: database error message. */
						__( 'The points balance could not be rewritten: %s', 'at8-moonlight-user-center' ),
						'' !== $moved_error ? $moved_error : self::last_error_text()
					)
				);
			}

			if ( 0 === (int) $moved ) {
				// A movement was applied while this repair was running: its
				// snapshot is newer than ours, so start over instead of
				// overwriting it.
				$undone = self::rollback();

				if ( is_wp_error( $undone ) ) {
					return $undone;
				}

				usleep( (int) ( self::RETRY_USLEEP * min( $attempt, 4 ) ) );
				continue;
			}

			if ( 1 !== (int) $moved ) {
				$undone = self::rollback();

				if ( is_wp_error( $undone ) ) {
					return $undone;
				}

				return new WP_Error(
					'at8_mluc_points_db',
					sprintf(
						/* translators: %d: number of rows. */
						__( 'The points balance rewrite touched %d rows instead of one.', 'at8-moonlight-user-center' ),
						(int) $moved
					)
				);
			}

			$deferred  = ( 'savepoint' === self::$tx_mode );
			$committed = self::commit();

			if ( is_wp_error( $committed ) ) {
				return $committed;
			}

			// Inside somebody else's transaction the mirror could outlive a
			// rollback in the object cache, so it is only written when this
			// request owns the commit.
			if ( ! $deferred ) {
				self::sync_mirror( $user_id, $sum );
			}

			return $sum;
		}

		return new WP_Error(
			'at8_mluc_points_busy',
			__( 'The points account is busy. Please try again in a moment.', 'at8-moonlight-user-center' )
		);
	}

	/**
	 * Sum of a user's movements.
	 *
	 * The query always returns exactly one row thanks to COALESCE, so a null
	 * result means the read failed rather than "no movements" — the two must
	 * never be conflated.
	 *
	 * @param int $user_id User ID.
	 * @return int|WP_Error
	 */
	private static function ledger_sum( $user_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Summing the ledger is the authoritative read.
		$sum = $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE( SUM( amount ), 0 ) FROM {$wpdb->prefix}at8_mluc_points_ledger WHERE user_id = %d", (int) $user_id )
		);

		$error = self::clean_error( (string) $wpdb->last_error );

		if ( null === $sum || '' !== $error ) {
			return new WP_Error(
				'at8_mluc_points_read',
				sprintf(
					/* translators: %s: database error message. */
					__( 'The points ledger could not be read: %s', 'at8-moonlight-user-center' ),
					'' !== $error ? $error : __( 'unknown database error', 'at8-moonlight-user-center' )
				)
			);
		}

		return (int) $sum;
	}

	/**
	 * Whether the stored balance still equals the sum of the movements.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function verify_balance( $user_id ) {
		$user_id = (int) $user_id;
		$account = self::account( $user_id );

		if ( is_wp_error( $account ) ) {
			return false;
		}

		$sum = self::ledger_sum( $user_id );

		if ( is_wp_error( $sum ) ) {
			return false;
		}

		return (int) $account[0] === $sum;
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * Balance for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int|WP_Error Balance, or an error when it could not be read.
	 *                      Never a made-up zero: callers that only display it
	 *                      should show "unavailable" instead of 0.
	 */
	public static function balance( $user_id ) {
		$user_id = (int) $user_id;
		$account = self::account( $user_id );

		if ( is_wp_error( $account ) ) {
			// The account could not be read. Fall back to the legacy history,
			// which is read straight from the table; if that also fails the
			// caller gets the error rather than a fake zero.
			$legacy = self::legacy_total( $user_id );

			if ( is_wp_error( $legacy ) ) {
				return $legacy;
			}

			return max( 0, (int) $legacy );
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
	 * @return bool|WP_Error Whether a movement with this key exists, or an error
	 *                       when the lookup itself failed.
	 */
	public static function has_entry( $user_id, $key ) {
		global $wpdb;

		$key = (string) $key;

		if ( '' === $key ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The UNIQUE index answers this in the database; a cached answer could be stale.
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}at8_mluc_points_ledger WHERE user_id = %d AND idem_key = %s LIMIT 1", (int) $user_id, $key )
		);

		$error = self::clean_error( (string) $wpdb->last_error );

		if ( '' !== $error ) {
			// Reporting "not found" here would let a second award through.
			return new WP_Error(
				'at8_mluc_points_read',
				sprintf(
					/* translators: %s: database error message. */
					__( 'The points ledger could not be read: %s', 'at8-moonlight-user-center' ),
					$error
				)
			);
		}

		return null !== $found;
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

		$reason              = sanitize_text_field( (string) $reason );
		$source              = sanitize_key( (string) $source );
		$by                  = get_current_user_id();
		$processed           = time();
		self::$last_deferred = false;

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$account = self::account( $user_id );

			if ( is_wp_error( $account ) ) {
				return $account;
			}

			list( $balance, $fence ) = $account;

			// Already applied — a duplicate callback, or a retry after a crash
			// that happened after the commit. Nothing to write.
			if ( '' !== $key ) {
				$seen = self::has_entry( $user_id, $key );

				if ( is_wp_error( $seen ) ) {
					return $seen;
				}

				if ( $seen ) {
					return $balance;
				}
			}

			if ( $amount < 0 && $balance + $amount < 0 ) {
				return new WP_Error( 'at8_mluc_points_balance', __( 'That would take the balance below zero.', 'at8-moonlight-user-center' ) );
			}

			$next = $fence + 1;

			// Without a transaction the balance and the ledger could drift
			// apart, so a failed BEGIN means "do nothing at all".
			$begun = self::begin();

			if ( is_wp_error( $begun ) ) {
				return $begun;
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
				$undone = self::rollback();

				if ( is_wp_error( $undone ) ) {
					return $undone;
				}

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
				$undone = self::rollback();

				if ( is_wp_error( $undone ) ) {
					return $undone;
				}

				// A concurrent request with the same key won the race: report
				// the balance it produced. Anything else is a real failure.
				if ( self::is_duplicate_error( $user_id, $key, $db_error ) ) {
					return self::balance( $user_id );
				}

				return self::save_error();
			}

			/*
			 * A savepoint release only hands the work to the outer transaction,
			 * so nothing may be described as durable in that case: no mirror
			 * write (it would survive a rollback in the object cache) and no
			 * success action (its side effects cannot be undone).
			 */
			$deferred = ( 'savepoint' === self::$tx_mode );

			// A failed COMMIT means the movement is not durable: report an
			// error and never fire the "balance changed" action for it.
			$committed = self::commit();

			if ( is_wp_error( $committed ) ) {
				return $committed;
			}

			$new_balance = $balance + $amount;

			if ( ! $deferred ) {
				self::sync_mirror( $user_id, $new_balance );
			}

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
			self::$last_deferred = $deferred;

			if ( $deferred ) {
				/**
				 * Fires when a movement is inside a transaction this plugin does
				 * not own. The caller's transaction may still roll back, so the
				 * movement is not durable yet.
				 *
				 * @since 2.6.3
				 *
				 * @param int   $user_id User ID.
				 * @param int   $amount  Signed amount applied.
				 * @param int   $balance Balance including this movement.
				 * @param array $entry   Ledger entry.
				 */
				do_action( 'at8_mluc_points_adjusted_deferred', $user_id, $amount, $new_balance, $entry );
			} else {
				do_action( 'at8_mluc_points_adjusted', $user_id, $amount, $new_balance, $entry );
			}

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

		/*
		 * Fast-path marker for later triggers. It is only an optimisation: the
		 * idempotency key and the account version are what prevent a re-award.
		 * It is skipped when the movement sits inside somebody else's
		 * transaction, because the marker would outlive a rollback in the
		 * object cache while the movement itself disappears.
		 */
		if ( ! self::$last_deferred ) {
			add_post_meta( $order_id, '_at8_mluc_points_awarded', 1, true );
		}
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

		$error = self::clean_error( (string) $wpdb->last_error );

		if ( '' !== $error ) {
			// A failed read is not an empty account: migrating here would
			// import the same history a second time.
			return new WP_Error(
				'at8_mluc_points_read',
				sprintf(
					/* translators: %s: database error message. */
					__( 'The points account could not be read: %s', 'at8-moonlight-user-center' ),
					$error
				)
			);
		}

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

		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$total = 0;

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

		$begun = self::begin();

		if ( is_wp_error( $begun ) ) {
			return $begun;
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
			$undone = self::rollback();

			return is_wp_error( $undone ) ? $undone : self::save_error();
		}

		$deferred  = ( 'savepoint' === self::$tx_mode );
		$committed = self::commit();

		// Nothing is reported as migrated unless the commit went through.
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}

		if ( ! $deferred ) {
			self::sync_mirror( $user_id, $balance );
		}

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

		if ( '' !== $key ) {
			$seen = self::has_entry( $user_id, $key );

			if ( is_wp_error( $seen ) ) {
				return false;
			}

			if ( $seen ) {
				// The same key appears more than once in the old history: keep
				// both movements by giving this one a unique key.
				$key = 'legacy:' . md5( $user_id . '|' . (int) $index . '|' . $time . '|' . (string) $entry['u'] );
			}
		}

		$error = '';

		return (bool) self::insert_movement( $user_id, $amount, $reason, $source, $by, $key, 0, $time, $error );
	}

	/**
	 * Legacy movements from the pre-2.6 user meta, oldest first.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function legacy_entries( $user_id ) {
		global $wpdb;

		/*
		 * Read the raw rows instead of going through the user-meta API: the
		 * API answers an empty value for a failed query and serves cached
		 * copies, so neither a broken read nor a stale cache can be told apart
		 * from "this user has no history". This is the only source the first
		 * migration has, so it is read from the table.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time history import, read straight from the table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key IN ( %s, %s ) ORDER BY umeta_id ASC",
				(int) $user_id,
				self::LEDGER_META,
				self::ENTRY_META
			),
			ARRAY_A
		);

		$error = self::clean_error( (string) $wpdb->last_error );

		if ( '' !== $error || null === $rows ) {
			// An unreadable history must not look like "no history": that
			// would migrate nothing and report a zero balance as truth.
			return new WP_Error(
				'at8_mluc_points_read',
				sprintf(
					/* translators: %s: database error message. */
					__( 'The legacy points history could not be read: %s', 'at8-moonlight-user-center' ),
					'' !== $error ? $error : __( 'unknown database error', 'at8-moonlight-user-center' )
				)
			);
		}

		$entries = array();

		foreach ( (array) $rows as $row ) {
			$value = maybe_unserialize( $row['meta_value'] );

			if ( self::LEDGER_META === $row['meta_key'] ) {
				// Older versions kept the whole history in one meta row.
				if ( ! is_array( $value ) ) {
					continue;
				}

				foreach ( $value as $entry ) {
					if ( is_array( $entry ) && isset( $entry['amount'] ) ) {
						$entries[] = self::normalise_entry( $entry );
					}
				}

				continue;
			}

			// 2.5.7 – 2.5.9 wrote one meta row per movement.
			if ( is_array( $value ) && isset( $value['amount'] ) ) {
				$entries[] = self::normalise_entry( $value );
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
		$seen = self::has_entry( $user_id, $key );

		return ! is_wp_error( $seen ) && (bool) $seen;
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
	 * Open a transaction scope.
	 *
	 * If another plugin already has a transaction open, starting our own would
	 * silently commit theirs, so a savepoint is used instead and only our own
	 * statements are ever rolled back. When the state cannot be determined the
	 * call fails instead of guessing: an unknown state must never lead to a
	 * blind START TRANSACTION.
	 *
	 * @return true|WP_Error
	 */
	private static function begin() {
		if ( '' !== self::$tx_mode ) {
			// Nested scope: never needed by this class, and silently joining
			// an outer transaction would make the caller's rollback wrong.
			return new WP_Error(
				'at8_mluc_points_tx',
				__( 'The points update was already inside a transaction scope.', 'at8-moonlight-user-center' )
			);
		}

		$mode = self::transaction_mode();

		if ( is_wp_error( $mode ) ) {
			return $mode;
		}

		$sql = 'savepoint' === $mode ? 'SAVEPOINT ' . self::SAVEPOINT : 'START TRANSACTION';

		if ( ! self::run_sql( $sql ) ) {
			return new WP_Error(
				'at8_mluc_points_tx',
				'savepoint' === $mode
					/* translators: %s: database error message. */
					? sprintf( __( 'The points savepoint could not be created: %s', 'at8-moonlight-user-center' ), self::last_error_text() )
					/* translators: %s: database error message. */
					: sprintf( __( 'The points transaction could not be started: %s', 'at8-moonlight-user-center' ), self::last_error_text() )
			);
		}

		self::$tx_mode = $mode;

		return true;
	}

	/**
	 * Close the current scope.
	 *
	 * Releasing a savepoint only hands the work to the outer transaction, so
	 * the caller still may not call the movement durable.
	 *
	 * @return true|WP_Error
	 */
	private static function commit() {
		if ( '' === self::$tx_mode ) {
			return new WP_Error(
				'at8_mluc_points_tx',
				__( 'The points update had no transaction to commit.', 'at8-moonlight-user-center' )
			);
		}

		$mode          = self::$tx_mode;
		self::$tx_mode = '';

		if ( 'savepoint' === $mode ) {
			if ( ! self::run_sql( 'RELEASE SAVEPOINT ' . self::SAVEPOINT ) ) {
				$release_error = self::last_error_text();

				// The savepoint still exists: undo the movement inside the
				// caller's transaction instead of leaving it half applied.
				if ( ! self::run_sql( 'ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT ) ) {
					return self::report_tx_error(
						new WP_Error(
							'at8_mluc_points_tx',
							sprintf(
								/* translators: 1: release error, 2: rollback error. */
								__( 'The points savepoint could not be released (%1$s) and undoing the movement inside the enclosing transaction failed as well (%2$s). That transaction may still carry the unconfirmed points movement, so it must not be treated as if the movement were gone.', 'at8-moonlight-user-center' ),
								$release_error,
								self::last_error_text()
							)
						)
					);
				}

				// The movement is gone; drop the savepoint rather than leaving
				// a marker behind in somebody else's transaction.
				self::run_sql( 'RELEASE SAVEPOINT ' . self::SAVEPOINT );

				return self::report_tx_error(
					new WP_Error(
						'at8_mluc_points_tx',
						sprintf(
							/* translators: %s: database error message. */
							__( 'The points savepoint could not be released (%s), so the movement is not confirmed; it was undone inside the enclosing transaction.', 'at8-moonlight-user-center' ),
							$release_error
						)
					)
				);
			}

			return true;
		}

		if ( ! self::run_sql( 'COMMIT' ) ) {
			// A refused COMMIT can leave the transaction open; close it so the
			// rest of the request is not writing inside a phantom transaction.
			self::run_sql( 'ROLLBACK' );

			return new WP_Error(
				'at8_mluc_points_tx',
				/* translators: %s: database error message. */
				sprintf( __( 'The points update could not be committed: %s', 'at8-moonlight-user-center' ), self::last_error_text() )
			);
		}

		return true;
	}

	/**
	 * Undo the current scope.
	 *
	 * @return true|WP_Error
	 */
	private static function rollback() {
		if ( '' === self::$tx_mode ) {
			return new WP_Error(
				'at8_mluc_points_tx',
				__( 'The points update had no transaction to roll back.', 'at8-moonlight-user-center' )
			);
		}

		$mode          = self::$tx_mode;
		self::$tx_mode = '';

		if ( 'savepoint' === $mode ) {
			if ( ! self::run_sql( 'ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT ) ) {
				return self::report_tx_error(
					new WP_Error(
						'at8_mluc_points_tx',
						/* translators: %s: database error message. */
						sprintf( __( 'The points savepoint could not be rolled back: %s', 'at8-moonlight-user-center' ), self::last_error_text() )
					)
				);
			}

			// The savepoint itself stays behind until it is released; ignoring
			// a failure here is safe because the outer owner will discard it.
			self::run_sql( 'RELEASE SAVEPOINT ' . self::SAVEPOINT );

			return true;
		}

		if ( ! self::run_sql( 'ROLLBACK' ) ) {
			return self::report_tx_error(
				new WP_Error(
					'at8_mluc_points_tx',
					/* translators: %s: database error message. */
					sprintf( __( 'The points transaction could not be rolled back: %s', 'at8-moonlight-user-center' ), self::last_error_text() )
				)
			);
		}

		return true;
	}

	/**
	 * Make a rollback failure visible: it means the connection may still hold
	 * half of a movement.
	 *
	 * @param WP_Error $error Failure to report.
	 * @return WP_Error The same error, for chaining.
	 */
	private static function report_tx_error( $error ) {
		/**
		 * Fires when a transaction could not be finished cleanly.
		 *
		 * @since 2.6.3
		 *
		 * @param WP_Error $error What went wrong.
		 */
		do_action( 'at8_mluc_points_transaction_error', $error );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'AT8 Moonlight User Center: ' . $error->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostics for a transaction that could not be rolled back.
		}

		return $error;
	}

	/**
	 * Which scope this request needs.
	 *
	 * @return string|WP_Error 'own' or 'savepoint'.
	 */
	private static function transaction_mode() {
		return self::probe_transaction_state();
	}

	/**
	 * Decide whether somebody else already owns a transaction.
	 *
	 * The answer is either known or it is an error — it is never guessed.
	 * START TRANSACTION implicitly commits whatever the caller already has
	 * open, so "I could not tell" must never turn into "there is nothing to
	 * worry about".
	 *
	 * 1. `SELECT @@in_transaction` — exact on MySQL 5.7.36+ / MariaDB 10.3+.
	 *    A failed query is a failed query; it is not a zero.
	 * 2. A throwaway savepoint. Outside a transaction SAVEPOINT is a no-op and
	 *    the matching ROLLBACK TO answers 1305 "does not exist"; inside one
	 *    both succeed. Nothing is written between the two statements, so
	 *    neither outcome changes data.
	 *
	 * Only that specific 1305 answer proves there is no transaction. Any other
	 * failure — a create that is refused, a rollback refused for a reason the
	 * probe cannot explain — leaves the state unknown and returns an error.
	 *
	 * @return string|WP_Error 'own' or 'savepoint'.
	 */
	private static function probe_transaction_state() {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Server variable, no user input.
		$value     = $wpdb->get_var( 'SELECT @@in_transaction' );
		$value_err = self::clean_error( (string) $wpdb->last_error );

		if ( '' === $value_err && in_array( (string) $value, array( '0', '1' ), true ) ) {
			$wpdb->suppress_errors( $suppress );

			return '1' === (string) $value ? 'savepoint' : 'own';
		}

		$probe = self::PROBE_SAVEPOINT;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed identifier built in this class.
		$created       = $wpdb->query( 'SAVEPOINT ' . $probe );
		$created_error = self::clean_error( (string) $wpdb->last_error );

		if ( false === $created || '' !== $created_error ) {
			$wpdb->suppress_errors( $suppress );

			// No savepoint means no probe, which means no answer. Assuming
			// "no transaction" here would be exactly the guess that commits
			// somebody else's work.
			return self::unknown_transaction_state(
				'' !== $created_error
					? $created_error
					: __( 'the database refused to create a savepoint', 'at8-moonlight-user-center' )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed identifier built in this class.
		$rewound       = $wpdb->query( 'ROLLBACK TO SAVEPOINT ' . $probe );
		$rewound_error = self::clean_error( (string) $wpdb->last_error );

		if ( false !== $rewound && '' === $rewound_error ) {
			// A transaction is open. The probe held no changes, so releasing it
			// only removes the marker; a failure here is harmless because the
			// outer owner discards leftover savepoints on commit anyway.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed identifier built in this class.
			$wpdb->query( 'RELEASE SAVEPOINT ' . $probe );
			$wpdb->suppress_errors( $suppress );

			return 'savepoint';
		}

		if ( self::is_missing_savepoint_error( $rewound_error ) ) {
			// Definitive: outside a transaction the SAVEPOINT was a no-op, so
			// the rewind cannot find it. Nothing was written either way.
			$wpdb->suppress_errors( $suppress );

			return 'own';
		}

		// Unknown: the probe exists and could not be rewound. Drop it so it
		// does not linger in the caller's transaction, then refuse to write.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed identifier built in this class.
		$wpdb->query( 'RELEASE SAVEPOINT ' . $probe );
		$wpdb->suppress_errors( $suppress );

		return self::unknown_transaction_state(
			'' !== $rewound_error
				? $rewound_error
				: __( 'the savepoint probe could not be rolled back', 'at8-moonlight-user-center' )
		);
	}

	/**
	 * Whether a driver error is the documented "that savepoint does not exist"
	 * answer.
	 *
	 * MySQL reports 1305 / SQLSTATE 3B001 for a savepoint that was never
	 * created, and that is precisely what an out-of-transaction SAVEPOINT
	 * leaves behind. Every other error means the probe learnt nothing.
	 *
	 * @param string $error Cleaned driver error.
	 * @return bool
	 */
	private static function is_missing_savepoint_error( $error ) {
		$error = (string) $error;

		if ( '' === $error ) {
			return false;
		}

		if ( preg_match( '/\b1305\b/', $error ) ) {
			return true;
		}

		if ( preg_match( '/\b3B001\b/i', $error ) ) {
			return true;
		}

		return false !== stripos( $error, 'savepoint' ) && false !== stripos( $error, 'does not exist' );
	}

	/**
	 * Build the "this cannot be decided" error.
	 *
	 * @param string $detail Driver error or explanation.
	 * @return WP_Error
	 */
	private static function unknown_transaction_state( $detail ) {
		return new WP_Error(
			'at8_mluc_points_tx',
			sprintf(
				/* translators: %s: database error message. */
				__( 'The database transaction state could not be determined, so nothing was written: %s', 'at8-moonlight-user-center' ),
				$detail
			)
		);
	}

	/**
	 * One-line text of the driver error that just happened.
	 *
	 * @return string
	 */
	private static function last_error_text() {
		global $wpdb;

		$error = self::clean_error( (string) $wpdb->last_error );

		return '' !== $error ? $error : __( 'unknown database error', 'at8-moonlight-user-center' );
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
	 * Legacy balance of a user, computed without touching the tables.
	 *
	 * Used only when the account could not be read or migrated: showing the
	 * number the old meta describes is better than showing zero, and it writes
	 * nothing.
	 *
	 * @param int $user_id User ID.
	 * @return int|WP_Error
	 */
	private static function legacy_total( $user_id ) {
		global $wpdb;

		$entries = self::legacy_entries( $user_id );

		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$total = 0;

		foreach ( $entries as $entry ) {
			$total += (int) $entry['amount'];
		}

		if ( ! empty( $entries ) ) {
			return $total;
		}

		// Very old installs only stored a balance; read it the same way so a
		// failed query cannot pass for "no points".
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading one meta row for the fallback total.
		$mirror = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
				(int) $user_id,
				self::BALANCE_META
			)
		);

		$error = self::clean_error( (string) $wpdb->last_error );

		if ( '' !== $error ) {
			return new WP_Error(
				'at8_mluc_points_read',
				sprintf(
					/* translators: %s: database error message. */
					__( 'The stored points balance could not be read: %s', 'at8-moonlight-user-center' ),
					$error
				)
			);
		}

		return is_numeric( $mirror ) && (int) $mirror > 0 ? (int) $mirror : 0;
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
		$balance = self::balance( $user_id );

		// An unreadable balance is not an affordable one.
		if ( is_wp_error( $balance ) ) {
			return false;
		}

		return $balance >= (int) $cost;
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

		if ( is_wp_error( $balance ) ) {
			echo '<h2>' . esc_html__( 'Points', 'at8-moonlight-user-center' ) . '</h2>';
			echo '<p>' . esc_html( $balance->get_error_message() ) . '</p>';

			return;
		}

		$ledger = self::ledger( $user->ID, 10 );
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
