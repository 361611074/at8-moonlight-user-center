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
	 * Option holding the row-by-row verification report of the last run.
	 */
	const REPORT_OPTION = 'at8mluc_prefix_migration_report';

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
		// Runs during plugins_loaded, before admin_notices is fired.
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );

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
				// CONFLICT means something different depending on the step:
				// for meta/options it is "both names already exist, do not
				// overwrite" and the run may finish. For the points tables it
				// is a data disagreement, which must hold back the marker.
				if ( self::FAILED === $status || ( 'tables' === $step && self::CONFLICT === $status ) ) {
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
					'time'    => time(),
					'steps'   => $failures,
					'reports' => (array) get_option( self::REPORT_OPTION, array() ),
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
	 * Move the two points tables from their pre-2.7.0 names.
	 *
	 * A conflict is returned as such and stops the run: `maybe_run()` treats
	 * it exactly like a failure, so the completion marker stays unwritten and
	 * the old tables stay in place.
	 *
	 * @return string Step status.
	 */
	private static function move_points_tables() {
		global $wpdb;

		$status = self::SKIPPED;

		foreach ( array( 'ledger', 'account' ) as $name ) {
			$result = self::move_points_table( $wpdb->prefix . 'at8mluc_points_' . $name );

			if ( self::FAILED === $result || self::CONFLICT === $result ) {
				return $result;
			}

			if ( self::OK === $result ) {
				$status = self::OK;
			}
		}

		return $status;
	}

	/**
	 * Move one points table, verifying every source row against the target.
	 *
	 * A row count is never used as proof of success. The real columns and the
	 * primary key are read from the database first, then the source is walked
	 * in bounded batches and every row is compared column by column with the
	 * row carrying the same primary key in the target.
	 *
	 * Three outcomes are possible for a row: identical (nothing to do),
	 * missing (inserted on the second pass, never overwritten) or different
	 * (a conflict — the run stops, nothing is overwritten, the old table is
	 * kept). A final read-only pass must find zero missing and zero conflicting
	 * rows before this step may report success.
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

		$schema = self::table_columns( $old );

		if ( is_wp_error( $schema ) || '' === $schema['primary'] ) {
			return self::FAILED;
		}

		$new_exists = self::table_exists( $new );

		if ( is_wp_error( $new_exists ) ) {
			return self::FAILED;
		}

		$report = array(
			'table'     => $new,
			'source'    => $old,
			'primary'   => $schema['primary'],
			'columns'   => $schema['columns'],
			'checked'   => 0,
			'identical' => 0,
			'missing'   => 0,
			'conflicts' => 0,
			'copied'    => 0,
			'samples'   => array(),
			'method'    => '',
			'verified'  => false,
		);

		if ( ! $new_exists ) {
			/*
			 * An atomic rename is a physical move, not a copy: no row can be
			 * lost or altered, so a row-by-row comparison would compare the
			 * same table with itself. The report records that distinction.
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names come from $wpdb->prefix.
			$renamed = $wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" );
			$error   = self::clean_error();

			if ( false !== $renamed && '' === $error ) {
				$report['method']   = 'atomic-rename';
				$report['verified'] = true;
				$report['note']     = 'Rows were moved, not copied; no row comparison applies.';
				self::publish_report( $report );

				return self::OK;
			}

			// The driver refused the rename (SQLite drop-in, restricted grants).
			$installed = AT8MLUC_Points::install();

			if ( is_wp_error( $installed ) ) {
				return self::FAILED;
			}

			$new_exists = self::table_exists( $new );

			if ( is_wp_error( $new_exists ) || ! $new_exists ) {
				return self::FAILED;
			}

			$report['method'] = 'copy-and-verify';
		} else {
			$report['method'] = 'verify-existing';
		}

		$new_schema = self::table_columns( $new );

		if ( is_wp_error( $new_schema ) ) {
			return self::FAILED;
		}

		$missing_columns = array_diff( $schema['columns'], $new_schema['columns'] );

		if ( array() !== $missing_columns ) {
			$report['conflicts'] = count( $missing_columns );
			$report['samples'][] = 'target is missing columns: ' . implode( ', ', $missing_columns );
			self::publish_report( $report );

			return self::CONFLICT;
		}

		// Pass 1: read-only. Never write into a target that disagrees.
		if ( ! self::inspect_rows( $old, $new, $schema, $report ) ) {
			self::publish_report( $report );

			return self::FAILED;
		}

		if ( $report['conflicts'] > 0 ) {
			self::publish_report( $report );

			return self::CONFLICT;
		}

		// Pass 2: insert only rows whose primary key is absent. No UPDATE.
		if ( $report['missing'] > 0 && ! self::copy_missing_rows( $old, $new, $schema, $report ) ) {
			self::publish_report( $report );

			return self::FAILED;
		}

		// Pass 3: verify the result again before any completion marker.
		$final            = $report;
		$final['checked']   = 0;
		$final['identical'] = 0;
		$final['missing']   = 0;
		$final['conflicts'] = 0;
		$final['samples']   = array();

		if ( ! self::inspect_rows( $old, $new, $schema, $final ) ) {
			self::publish_report( $report );

			return self::FAILED;
		}

		$report['checked']   = $final['checked'];
		$report['identical'] = $final['identical'];
		$report['missing']   = $final['missing'];
		$report['conflicts'] = $final['conflicts'];

		if ( $final['conflicts'] > 0 || $final['missing'] > 0 ) {
			$report['samples'] = $final['samples'];
			self::publish_report( $report );

			return self::FAILED;
		}

		$report['verified'] = true;
		self::publish_report( $report );

		return $report['copied'] > 0 ? self::OK : self::SKIPPED;
	}

	/**
	 * Read the real columns and primary key of one table.
	 *
	 * Column names are validated against an identifier allow-list before they
	 * are ever interpolated into a query. Nothing here assumes that a column
	 * exists: the schema of this release is not a safe description of a table
	 * that may have been created by an older build.
	 *
	 * @param string $table Full table name.
	 * @return array{columns:string[],primary:string}|WP_Error
	 */
	private static function table_columns( $table ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name comes from $wpdb->prefix.
		$rows  = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
		$error = self::clean_error();

		$wpdb->suppress_errors( $suppress );

		if ( '' !== $error || ! is_array( $rows ) ) {
			return new WP_Error( 'at8mluc_schema_read', '' !== $error ? $error : __( 'The table structure could not be read.', 'at8-moonlight-user-center' ) );
		}

		$columns = array();
		$primary = '';

		foreach ( $rows as $row ) {
			$name = isset( $row['Field'] ) ? (string) $row['Field'] : '';

			if ( '' === $name || ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
				return new WP_Error( 'at8mluc_schema_column', __( 'An unexpected column name was found.', 'at8-moonlight-user-center' ) );
			}

			$columns[] = $name;

			if ( isset( $row['Key'] ) && 'PRI' === (string) $row['Key'] && '' === $primary ) {
				$primary = $name;
			}
		}

		if ( array() === $columns ) {
			return new WP_Error( 'at8mluc_schema_empty', __( 'The table has no columns.', 'at8-moonlight-user-center' ) );
		}

		return array(
			'columns' => $columns,
			'primary' => $primary,
		);
	}

	/**
	 * Read one bounded batch of source rows.
	 *
	 * Keeping the batch small means a site with a very large ledger never
	 * loads the whole table into PHP memory.
	 *
	 * @param string $table  Source table.
	 * @param string $pk     Whitelisted primary key column.
	 * @param int    $offset Batch offset.
	 * @param int    $limit  Batch size.
	 * @return array<int, array<string,mixed>>|WP_Error
	 */
	private static function read_batch( $table, $pk, $offset, $limit ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from $wpdb->prefix and SHOW COLUMNS.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` ORDER BY `{$pk}` ASC LIMIT %d, %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers come from $wpdb->prefix and SHOW COLUMNS.
				(int) $offset,
				(int) $limit
			),
			ARRAY_A
		);
		$error = self::clean_error();

		$wpdb->suppress_errors( $suppress );

		if ( '' !== $error || ! is_array( $rows ) ) {
			return new WP_Error( 'at8mluc_batch_read', '' !== $error ? $error : __( 'A batch of rows could not be read.', 'at8-moonlight-user-center' ) );
		}

		return $rows;
	}

	/**
	 * Read the target row with a given primary key.
	 *
	 * @param string $table Target table.
	 * @param string $pk    Whitelisted primary key column.
	 * @param string $key   Primary key value.
	 * @return array<string,mixed>|null|WP_Error Null when the row is absent.
	 */
	private static function read_row( $table, $pk, $key ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from $wpdb->prefix and SHOW COLUMNS; the value is prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$pk}` = %s LIMIT 1", (string) $key ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers come from $wpdb->prefix and SHOW COLUMNS.
			ARRAY_A
		);
		$error = self::clean_error();

		$wpdb->suppress_errors( $suppress );

		if ( '' !== $error ) {
			return new WP_Error( 'at8mluc_row_read', $error );
		}

		return null === $row ? null : $row;
	}

	/**
	 * Name the first column whose value differs between two rows.
	 *
	 * NULL, a missing column and an empty string are deliberately distinct:
	 * collapsing them would hide a real schema or data difference.
	 *
	 * @param string[]             $columns Columns to compare.
	 * @param array<string,mixed>  $a       Source row.
	 * @param array<string,mixed>  $b       Target row.
	 * @return string Column name, or empty string when identical.
	 */
	private static function row_diff( $columns, $a, $b ) {
		foreach ( $columns as $column ) {
			$av = ! array_key_exists( $column, $a ) ? '<absent>' : ( null === $a[ $column ] ? '<null>' : (string) $a[ $column ] );
			$bv = ! array_key_exists( $column, $b ) ? '<absent>' : ( null === $b[ $column ] ? '<null>' : (string) $b[ $column ] );

			if ( $av !== $bv ) {
				return $column;
			}
		}

		return '';
	}

	/**
	 * Pass 1 / pass 3: compare every source row with the target, read-only.
	 *
	 * @param string $old    Source table.
	 * @param string $new    Target table.
	 * @param array  $schema Real table structure.
	 * @param array  $report Report to accumulate into, by reference.
	 * @return bool False on a database error.
	 */
	private static function inspect_rows( $old, $new, $schema, &$report ) {
		$pk     = $schema['primary'];
		$cols   = $schema['columns'];
		$batch  = 200;
		$offset = 0;

		while ( true ) {
			$rows = self::read_batch( $old, $pk, $offset, $batch );

			if ( is_wp_error( $rows ) ) {
				$report['samples'][] = 'source read failed: ' . $rows->get_error_message();

				return false;
			}

			if ( array() === $rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				++$report['checked'];

				$key    = isset( $row[ $pk ] ) ? (string) $row[ $pk ] : '';
				$target = self::read_row( $new, $pk, $key );

				if ( is_wp_error( $target ) ) {
					$report['samples'][] = 'target read failed for pk=' . $key;

					return false;
				}

				if ( null === $target ) {
					++$report['missing'];

					continue;
				}

				$diff = self::row_diff( $cols, $row, $target );

				if ( '' !== $diff ) {
					++$report['conflicts'];

					if ( count( $report['samples'] ) < 5 ) {
						$report['samples'][] = 'pk=' . $key . ' differs in column ' . $diff;
					}
				} else {
					++$report['identical'];
				}
			}

			$offset += $batch;
		}

		return true;
	}

	/**
	 * Pass 2: insert rows whose primary key is absent from the target.
	 *
	 * Existing rows are never updated, even if another request inserted them
	 * between the two passes. Each insert is read back and compared before it
	 * counts as copied.
	 *
	 * @param string $old    Source table.
	 * @param string $new    Target table.
	 * @param array  $schema Real table structure.
	 * @param array  $report Report to accumulate into, by reference.
	 * @return bool False on a database error or a read-back mismatch.
	 */
	private static function copy_missing_rows( $old, $new, $schema, &$report ) {
		global $wpdb;

		$pk     = $schema['primary'];
		$cols   = $schema['columns'];
		$batch  = 200;
		$offset = 0;

		while ( true ) {
			$rows = self::read_batch( $old, $pk, $offset, $batch );

			if ( is_wp_error( $rows ) ) {
				return false;
			}

			if ( array() === $rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				$key    = isset( $row[ $pk ] ) ? (string) $row[ $pk ] : '';
				$target = self::read_row( $new, $pk, $key );

				if ( is_wp_error( $target ) ) {
					return false;
				}

				if ( null !== $target ) {
					// Already there. Never overwrite.
					continue;
				}

				$payload = array();

				foreach ( $cols as $column ) {
					$payload[ $column ] = array_key_exists( $column, $row ) ? $row[ $column ] : null;
				}

				$suppress = $wpdb->suppress_errors( true );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Inserting a source row whose key is absent from the target.
				$inserted = $wpdb->insert( $new, $payload );
				$error    = self::clean_error();

				$wpdb->suppress_errors( $suppress );

				if ( false === $inserted || '' !== $error ) {
					if ( count( $report['samples'] ) < 5 ) {
						$report['samples'][] = 'insert refused for pk=' . $key;
					}

					return false;
				}

				$back = self::read_row( $new, $pk, $key );

				if ( is_wp_error( $back ) || null === $back || '' !== self::row_diff( $cols, $row, $back ) ) {
					if ( count( $report['samples'] ) < 5 ) {
						$report['samples'][] = 'read-back mismatch for pk=' . $key;
					}

					return false;
				}

				++$report['copied'];
			}

			$offset += $batch;
		}

		return true;
	}

	/**
	 * Store and broadcast the verification report.
	 *
	 * The report contains table names, counts and column names only — never
	 * user data or SQL text.
	 *
	 * @param array $report One table's verification result.
	 * @return void
	 */
	private static function publish_report( $report ) {
		$all   = (array) get_option( self::REPORT_OPTION, array() );
		$all[] = $report;

		// Keep the option small: only the last two tables of the last run.
		$all = array_slice( $all, -2 );

		update_option( self::REPORT_OPTION, $all, false );

		/**
		 * Fires with the row-by-row verification result of one points table.
		 *
		 * @since 2.7.2
		 *
		 * @param array $report Counts, column names and conflict samples.
		 */
		do_action( 'at8mluc_prefix_migration_verified', $report );
	}

	/**
	 * Tell an administrator that the rename migration did not finish.
	 *
	 * The notice names the failing steps and the conflicting primary keys,
	 * and states explicitly that the old data is still intact.
	 */
	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$error = get_option( self::LAST_ERROR_OPTION, array() );

		if ( ! is_array( $error ) || empty( $error['steps'] ) ) {
			return;
		}

		$steps = implode( ', ', array_map( 'strval', (array) $error['steps'] ) );

		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'AT8 Moonlight User Center: the 2.7.0 identifier migration did not finish. Your existing options, meta and points data are still intact and have not been overwritten.',
			'at8-moonlight-user-center'
		);
		echo '</p><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: comma separated list of failing steps. */
				__( 'Steps needing attention: %s. The migration will be retried automatically; check the database if it keeps failing.', 'at8-moonlight-user-center' ),
				$steps
			)
		);
		echo '</p></div>';
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
