<?php
/**
 * One-time migration of the identifiers used before 2.7.0.
 *
 * 2.7.0 renamed every function, class, option, meta key, post type and table
 * so the plugin prefix is long enough for the WordPress.org naming rules. An
 * install that already has data keeps it: this routine copies the old option
 * rows to their new names, renames the meta keys and post types, and moves the
 * two points tables.
 *
 * The old names are derived from the new ones instead of being written out:
 * before 2.7.0 the same names simply carried an extra underscore after the
 * brand prefix. Everything legacy therefore lives in this one file, and the
 * old names never appear anywhere else in the plugin.
 *
 * Nothing is deleted. Old option rows and old tables are left behind, so a
 * failed run never destroys data — the worst case is that a copy has to be
 * made again on the next request.
 *
 * Reliability rules this file follows, because it runs on a live site:
 *
 * 1. Every step reports success / skipped / conflict / failed. "Zero rows
 *    touched" is not a failure; `false` from wpdb is.
 * 2. The completion marker is written only when no step failed, and only
 *    after reading the marker back. A partial migration never claims to be
 *    finished.
 * 3. A failure schedules a retry instead of re-running the whole sweep on
 *    every page view, which on a large site would be a per-request table scan.
 * 4. Concurrent requests are serialised by an atomic lease in the options
 *    table, taken and released with compare-and-swap, never by overwriting it.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rename the plugin's own storage from the previous prefix.
 */
class AT8MLUC_Prefix_Migration {

	/**
	 * Option that records a finished migration.
	 */
	const DONE_OPTION = 'at8mluc_prefix_migrated';

	/**
	 * Option row used as the migration lease.
	 */
	const LOCK_OPTION = 'at8mluc_prefix_migrating';

	/**
	 * Transient that holds a failed run back for a while.
	 */
	const RETRY_TRANSIENT = 'at8mluc_prefix_retry';

	/**
	 * Option holding the step names of the last failed run.
	 */
	const LAST_ERROR_OPTION = 'at8mluc_prefix_last_error';

	/**
	 * Seconds a lease stays valid before another request may take it over.
	 */
	const LOCK_TTL = 300;

	/**
	 * Seconds to wait before a failed run is attempted again.
	 */
	const RETRY_DELAY = 900;

	/*
	 * Step statuses. Callers branch on these instead of on booleans, so
	 * "already correct" and "could not be done" never look the same.
	 */
	const OK       = 'success';
	const SKIPPED  = 'skipped';
	const CONFLICT = 'conflict';
	const FAILED   = 'failed';

	/**
	 * Option suffixes that moved to the new prefix.
	 *
	 * @var string[]
	 */
	private static $option_suffixes = array( 'options', 'custom_fields', 'version', 'points_db' );

	/**
	 * Run the migration once per site.
	 *
	 * @return void
	 */
	public static function maybe_run() {
		if ( '' !== (string) get_option( self::DONE_OPTION, '' ) ) {
			return;
		}

		// A previous run failed: wait instead of scanning the tables again on
		// every single request.
		if ( false !== get_transient( self::RETRY_TRANSIENT ) ) {
			return;
		}

		$lease = self::acquire_lease();

		if ( '' === $lease ) {
			// Another request is migrating right now.
			return;
		}

		try {
			$steps = array(
				'options'    => self::copy_options(),
				'user_meta'  => self::rename_user_meta(),
				'post_meta'  => self::rename_post_meta(),
				'post_types' => self::rename_post_types(),
				'tables'     => self::move_points_tables(),
			);

			$failures = array();

			foreach ( $steps as $step => $status ) {
				if ( self::FAILED === $status ) {
					$failures[] = $step;
				}
			}

			if ( array() === $failures ) {
				update_option( self::DONE_OPTION, AT8MLUC_VERSION, false );

				// Verify the marker instead of trusting the return value: a
				// failed write must leave the migration retryable.
				if ( '' !== (string) get_option( self::DONE_OPTION, '' ) ) {
					delete_option( self::LAST_ERROR_OPTION );
					return;
				}

				$failures[] = 'marker';
			}

			update_option(
				self::LAST_ERROR_OPTION,
				array(
					'time'  => time(),
					'steps' => $failures,
				),
				false
			);

			set_transient( self::RETRY_TRANSIENT, 1, self::RETRY_DELAY );

			/**
			 * Fires when a migration run could not finish.
			 *
			 * Failing steps are named, no SQL and no user data is passed on,
			 * and the completion marker is deliberately left unwritten so the
			 * run happens again later.
			 *
			 * @since 2.7.1
			 *
			 * @param string[] $failures Step names that failed.
			 * @param array    $steps    Status of every step, keyed by name.
			 */
			do_action( 'at8mluc_prefix_migration_failed', $failures, $steps );
		} finally {
			self::release_lease( $lease );
		}
	}

	/**
	 * Name a key had before the rename.
	 *
	 * @param string $name Current name.
	 * @return string Previous name.
	 */
	private static function legacy( $name ) {
		return str_replace( 'at8mluc', 'at8_mluc', (string) $name );
	}

	/**
	 * Meta key prefixes that moved, as old => new.
	 *
	 * The paid add-on used the same scheme one letter further along, and the
	 * free plugin still reads two of its legacy keys, so both move together.
	 *
	 * @return array<int, array{0:string,1:string}>
	 */
	private static function meta_prefixes() {
		return array(
			array( self::legacy( 'at8mluc_' ), 'at8mluc_' ),
			array( self::legacy( 'at8mlucp_' ), 'at8mlucp_' ),
		);
	}

	/**
	 * Copy option rows to their new names.
	 *
	 * The old row is left behind on purpose: if anything still reads it there
	 * is something to read, and running this twice cannot lose a setting.
	 *
	 * @return string Step status.
	 */
	private static function copy_options() {
		$status = self::SKIPPED;

		foreach ( self::$option_suffixes as $suffix ) {
			$result = self::copy_option( 'at8mluc_' . $suffix );

			if ( self::FAILED === $result ) {
				return self::FAILED;
			}

			if ( self::OK === $result ) {
				$status = self::OK;
			}
		}

		// The add-on's settings used the same prefix; copying them keeps its
		// gateway configuration and page choices in place.
		$addon = self::copy_option( 'at8mlucp_options' );

		if ( self::FAILED === $addon ) {
			return self::FAILED;
		}

		if ( self::OK === $addon ) {
			$status = self::OK;
		}

		return $status;
	}

	/**
	 * Copy a single option row when the new name is still empty.
	 *
	 * @param string $new New option name.
	 * @return string Step status.
	 */
	private static function copy_option( $new ) {
		$absent = 'at8mluc__absent';

		// Something already wrote the new name: it wins, nothing is copied
		// over it.
		if ( $absent !== get_option( $new, $absent ) ) {
			return self::SKIPPED;
		}

		$value = get_option( self::legacy( $new ), null );

		if ( null === $value ) {
			return self::SKIPPED;
		}

		update_option( $new, $value, false );

		/*
		 * update_option() returns false both when the stored value did not
		 * change and when the write failed, so the result is read back rather
		 * than inferred from the return value.
		 */
		if ( get_option( $new, $absent ) === $value ) {
			return self::OK;
		}

		return self::FAILED;
	}

	/**
	 * Move user meta keys to the new prefix.
	 *
	 * Rows are moved per (user, key) group: one key can hold many rows (the
	 * points ledger does), and moving only the first would leave the rest
	 * behind under the old name. A group is skipped when the new key already
	 * existed before this run, so the (user_id, meta_key) index is never
	 * violated and a value the site already has is never overwritten.
	 *
	 * @return string Step status.
	 */
	private static function rename_user_meta() {
		global $wpdb;

		$status = self::SKIPPED;

		foreach ( self::meta_prefixes() as $pair ) {
			list( $old_prefix, $new_prefix ) = $pair;

			$rows = self::select_meta_rows( $wpdb->usermeta, 'umeta_id', 'user_id', $old_prefix );

			if ( is_wp_error( $rows ) ) {
				return self::FAILED;
			}

			if ( array() === $rows ) {
				continue;
			}

			$occupied = self::occupied_keys( $wpdb->usermeta, 'user_id', $new_prefix );

			if ( is_wp_error( $occupied ) ) {
				return self::FAILED;
			}

			$status = self::OK;

			foreach ( $rows as $row ) {
				$user_id = (int) $row['owner'];
				$new     = $new_prefix . substr( (string) $row['meta_key'], strlen( $old_prefix ) );

				if ( $new === $row['meta_key'] ) {
					continue;
				}

				if ( isset( $occupied[ $user_id . '|' . $new ] ) ) {
					// Both names exist: keep the row the site is already
					// reading and leave this one alone. Nothing is lost.
					$status = self::CONFLICT;
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Renaming the plugin's own meta row.
				$updated = $wpdb->update(
					$wpdb->usermeta,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Renaming the plugin's own meta row.
					array( 'meta_key' => $new ),
					array( 'umeta_id' => (int) $row['id'] ),
					array( '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					return self::FAILED;
				}

				wp_cache_delete( $user_id, 'user_meta' );
			}
		}

		return $status;
	}

	/**
	 * Move post meta keys to the new prefix (order data and paywall settings).
	 *
	 * These keys start with an underscore, so the pattern has to include it.
	 *
	 * @return string Step status.
	 */
	private static function rename_post_meta() {
		global $wpdb;

		$status = self::SKIPPED;

		foreach ( self::meta_prefixes() as $pair ) {
			list( $old_prefix, $new_prefix ) = $pair;

			$old_prefix = '_' . $old_prefix;
			$new_prefix = '_' . $new_prefix;

			$rows = self::select_meta_rows( $wpdb->postmeta, 'meta_id', 'post_id', $old_prefix );

			if ( is_wp_error( $rows ) ) {
				return self::FAILED;
			}

			if ( array() === $rows ) {
				continue;
			}

			$occupied = self::occupied_keys( $wpdb->postmeta, 'post_id', $new_prefix );

			if ( is_wp_error( $occupied ) ) {
				return self::FAILED;
			}

			$status = self::OK;

			foreach ( $rows as $row ) {
				$post_id = (int) $row['owner'];
				$new     = $new_prefix . substr( (string) $row['meta_key'], strlen( $old_prefix ) );

				if ( $new === $row['meta_key'] ) {
					continue;
				}

				if ( isset( $occupied[ $post_id . '|' . $new ] ) ) {
					$status = self::CONFLICT;
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Renaming the plugin's own meta row.
				$updated = $wpdb->update(
					$wpdb->postmeta,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Renaming the plugin's own meta row.
					array( 'meta_key' => $new ),
					array( 'meta_id' => (int) $row['id'] ),
					array( '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					return self::FAILED;
				}

				wp_cache_delete( $post_id, 'post_meta' );
			}
		}

		return $status;
	}

	/**
	 * Read the meta rows that still carry an old key prefix.
	 *
	 * @param string $table     Meta table name.
	 * @param string $id_column Primary key column to select.
	 * @param string $owner_col Owner column (user_id or post_id).
	 * @param string $prefix    Old key prefix.
	 * @return array<int, array{id:string,owner:string,meta_key:string}>|WP_Error
	 */
	private static function select_meta_rows( $table, $id_column, $owner_col, $prefix ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column list is a literal built in this class.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$id_column} AS id, {$owner_col} AS owner, meta_key FROM {$table} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are literals, the value is prepared.
				$wpdb->esc_like( $prefix ) . '%'
			),
			ARRAY_A
		);
		$error = self::clean_error();

		$wpdb->suppress_errors( $suppress );

		if ( '' !== $error ) {
			return new WP_Error( 'at8mluc_meta_read', $error );
		}

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'       => (string) ( isset( $row['id'] ) ? $row['id'] : '' ),
				'owner'    => (string) ( isset( $row['owner'] ) ? $row['owner'] : '' ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reading a row that was just returned, not a query argument.
				'meta_key' => (string) ( isset( $row['meta_key'] ) ? $row['meta_key'] : '' ),
			);
		}

		return $out;
	}

	/**
	 * Keys that already exist under the new prefix, as "owner|key".
	 *
	 * @param string $table     Meta table name.
	 * @param string $owner_col Owner column (user_id or post_id).
	 * @param string $prefix    New key prefix.
	 * @return array<string,bool>|WP_Error
	 */
	private static function occupied_keys( $table, $owner_col, $prefix ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column list is a literal built in this class.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$owner_col} AS owner, meta_key FROM {$table} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from $wpdb.
				$wpdb->esc_like( $prefix ) . '%'
			),
			ARRAY_A
		);
		$error = self::clean_error();

		$wpdb->suppress_errors( $suppress );

		if ( '' !== $error ) {
			return new WP_Error( 'at8mluc_meta_read', $error );
		}

		$occupied = array();

		foreach ( (array) $rows as $row ) {
			$occupied[ (int) $row['owner'] . '|' . $row['meta_key'] ] = true;
		}

		return $occupied;
	}

	/**
	 * Move the avatar and order post types to their new slugs.
	 *
	 * @return string Step status.
	 */
	private static function rename_post_types() {
		global $wpdb;

		$status = self::SKIPPED;

		foreach ( array( 'avatar', 'order' ) as $type ) {
			$new = 'at8mluc_' . $type;

			$suppress = $wpdb->suppress_errors( true );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the plugin's own posts by type.
			$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", self::legacy( $new ) ) );
			$error = self::clean_error();

			$wpdb->suppress_errors( $suppress );

			if ( '' !== $error || null === $ids ) {
				return self::FAILED;
			}

			foreach ( $ids as $id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Moving the plugin's own post to the new type.
				$updated = $wpdb->update(
					$wpdb->posts,
					array( 'post_type' => $new ),
					array( 'ID' => (int) $id ),
					array( '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					return self::FAILED;
				}

				$status = self::OK;

				clean_post_cache( (int) $id );
			}
		}

		return $status;
	}

	/**
	 * Move the two points tables to their new names.
	 *
	 * A rename is preferred because it moves the rows without touching them.
	 * Some database layers refuse it; the fallback creates the new table and
	 * copies the rows into it, then leaves the old table in place. In that
	 * case the copy is verified by row count, so a copy that stopped half way
	 * is finished on the next run instead of being reported as complete.
	 *
	 * @return string Step status.
	 */
	private static function move_points_tables() {
		global $wpdb;

		$status = self::SKIPPED;

		foreach ( array( 'ledger', 'account' ) as $name ) {
			$result = self::move_points_table( $wpdb->prefix . 'at8mluc_points_' . $name );

			if ( self::FAILED === $result ) {
				return self::FAILED;
			}

			if ( self::OK === $result ) {
				$status = self::OK;
			}
		}

		return $status;
	}

	/**
	 * Move one points table.
	 *
	 * @param string $new New table name.
	 * @return string Step status.
	 */
	private static function move_points_table( $new ) {
		global $wpdb;

		$old = self::legacy( $new );

		$old_exists = self::table_exists( $old );

		if ( is_wp_error( $old_exists ) ) {
			return self::FAILED;
		}

		if ( ! $old_exists ) {
			return self::SKIPPED;
		}

		$new_exists = self::table_exists( $new );

		if ( is_wp_error( $new_exists ) ) {
			return self::FAILED;
		}

		if ( $new_exists ) {
			$old_rows = self::count_rows( $old );
			$new_rows = self::count_rows( $new );

			if ( is_wp_error( $old_rows ) || is_wp_error( $new_rows ) ) {
				return self::FAILED;
			}

			// A completed rename leaves no old table behind, and a completed
			// copy holds at least as many rows as the source.
			if ( $new_rows >= $old_rows ) {
				return self::SKIPPED;
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names come from $wpdb->prefix.
			$renamed = $wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" );
			$error   = self::clean_error();

			/*
			 * A driver that cannot rename reports false with an empty message,
			 * so the copy below is attempted either way. It reaches the same
			 * result as the rename, just with more work.
			 */
			if ( false !== $renamed && '' === $error ) {
				return self::OK;
			}
		}

		// Create the target if the rename was refused, then copy the rows.
		AT8MLUC_Points::install();

		if ( ! self::copy_rows( $new, $old ) ) {
			return self::FAILED;
		}

		$old_rows = self::count_rows( $old );
		$new_rows = self::count_rows( $new );

		if ( is_wp_error( $old_rows ) || is_wp_error( $new_rows ) ) {
			return self::FAILED;
		}

		// Every source row has to be visible in the new table before this
		// step counts as done.
		if ( $new_rows < $old_rows ) {
			return self::FAILED;
		}

		return self::OK;
	}

	/**
	 * Copy every row of one table into another with the same schema.
	 *
	 * `INSERT IGNORE` keeps a run that follows a partially copied table from
	 * failing on the rows that are already there. Database layers that do not
	 * know the keyword (the SQLite drop-in, for instance) reject the statement
	 * outright, so the plain form is tried as a fallback — in that case the
	 * table was empty, which is the only state the plain form can serve.
	 *
	 * @param string $new Target table.
	 * @param string $old Source table.
	 * @return bool True when the driver reported no error.
	 */
	private static function copy_rows( $new, $old ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Copying the plugin's own rows into the table with the same schema.
		$wpdb->query( "INSERT IGNORE INTO `{$new}` SELECT * FROM `{$old}`" );

		if ( '' !== self::clean_error() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Copying the plugin's own rows into the table with the same schema.
			$wpdb->query( "INSERT INTO `{$new}` SELECT * FROM `{$old}`" );
		}

		$error = self::clean_error();

		$wpdb->suppress_errors( $suppress );

		return '' === $error;
	}

	/**
	 * Does a table exist?
	 *
	 * "Could not tell" is reported as an error rather than as "missing": a
	 * failed lookup must not be read as "there is nothing to migrate".
	 *
	 * @param string $table Full table name.
	 * @return bool|WP_Error
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check on the plugin's own table.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$error = self::clean_error();

		$wpdb->suppress_errors( $suppress );

		if ( '' !== $error ) {
			return new WP_Error( 'at8mluc_table_lookup', $error );
		}

		return ! empty( $found );
	}

	/**
	 * Row count of one of the plugin's tables.
	 *
	 * @param string $table Full table name.
	 * @return int|WP_Error
	 */
	private static function count_rows( $table ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name comes from $wpdb->prefix.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		$error = self::clean_error();

		$wpdb->suppress_errors( $suppress );

		if ( '' !== $error || null === $count ) {
			return new WP_Error( 'at8mluc_table_count', '' !== $error ? $error : __( 'The row count could not be read.', 'at8-moonlight-user-center' ) );
		}

		return (int) $count;
	}

	/**
	 * The last driver error, or an empty string.
	 *
	 * @return string
	 */
	private static function clean_error() {
		global $wpdb;

		return trim( (string) $wpdb->last_error );
	}

	/**
	 * Take the migration lease, or find out that someone else holds it.
	 *
	 * The options table has a UNIQUE index on option_name, so a plain INSERT is
	 * the test-and-set: of two requests running this at the same moment,
	 * exactly one can create the row. The row stores an expiry so a request
	 * that dies mid-migration does not block the site forever, and taking an
	 * expired lease uses a compare-and-swap on the exact value that was read —
	 * a blind overwrite is how two requests end up both believing they hold it.
	 *
	 * @return string Lease token, empty when the lease is held elsewhere.
	 */
	private static function acquire_lease() {
		global $wpdb;

		$token = wp_generate_password( 20, false );
		$value = $token . '|' . ( time() + self::LOCK_TTL );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed table, prepared values.
		$created = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
				self::LOCK_OPTION,
				$value
			)
		);

		if ( $created ) {
			wp_cache_delete( self::LOCK_OPTION, 'options' );

			return $token;
		}

		$seen    = (string) get_option( self::LOCK_OPTION, '' );
		$expires = (int) substr( (string) strrchr( '|' . $seen, '|' ), 1 );

		if ( $expires > time() ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed table, prepared values.
		$taken = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
				$value,
				self::LOCK_OPTION,
				$seen
			)
		);

		if ( ! $taken ) {
			return '';
		}

		wp_cache_delete( self::LOCK_OPTION, 'options' );

		return $token;
	}

	/**
	 * Release the lease, but only the one this request took.
	 *
	 * The DELETE matches the token, so a lease that expired and was taken over
	 * by another request is left for its new holder.
	 *
	 * @param string $token Lease token returned by acquire_lease().
	 * @return void
	 */
	private static function release_lease( $token ) {
		global $wpdb;

		if ( '' === $token ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed table, prepared values.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` LIKE %s",
				self::LOCK_OPTION,
				$wpdb->esc_like( $token ) . '|%'
			)
		);

		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}
}
