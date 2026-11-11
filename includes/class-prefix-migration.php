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

		self::copy_options();
		self::rename_user_meta();
		self::rename_post_meta();
		self::rename_post_types();
		self::move_points_tables();

		update_option( self::DONE_OPTION, AT8MLUC_VERSION, false );
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
	 * @return void
	 */
	private static function copy_options() {
		$flag = 'at8mluc__absent';

		foreach ( self::$option_suffixes as $suffix ) {
			$new = 'at8mluc_' . $suffix;

			if ( $flag !== get_option( $new, $flag ) ) {
				continue;
			}

			$value = get_option( self::legacy( $new ), null );

			if ( null === $value ) {
				continue;
			}

			update_option( $new, $value, false );
		}

		// The add-on's settings used the same prefix; copying them keeps its
		// gateway configuration and page choices in place.
		$addon = 'at8mlucp_options';

		if ( $flag === get_option( $addon, $flag ) ) {
			$legacy = get_option( self::legacy( $addon ), null );

			if ( null !== $legacy ) {
				update_option( $addon, $legacy, false );
			}
		}
	}

	/**
	 * Move user meta keys to the new prefix.
	 *
	 * Rows are moved per (user, key) group: one key can hold many rows (the
	 * points ledger does), and moving only the first would leave the rest
	 * behind under the old name. A group is skipped when the new key already
	 * existed before this run, so the (user_id, meta_key) index is never
	 * violated.
	 *
	 * @return void
	 */
	private static function rename_user_meta() {
		global $wpdb;

		foreach ( self::meta_prefixes() as $pair ) {
			list( $old_prefix, $new_prefix ) = $pair;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the plugin's own meta rows by key prefix.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT umeta_id, user_id, meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( $old_prefix ) . '%'
				)
			);

			if ( empty( $rows ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Which new keys already exist, before anything is moved.
			$taken = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( $new_prefix ) . '%'
				),
				ARRAY_A
			);

			$occupied = array();

			foreach ( (array) $taken as $row ) {
				$occupied[ (int) $row['user_id'] . '|' . $row['meta_key'] ] = true;
			}

			foreach ( (array) $rows as $row ) {
				$user_id = (int) $row->user_id;
				$new     = $new_prefix . substr( (string) $row->meta_key, strlen( $old_prefix ) );

				if ( $new === $row->meta_key || isset( $occupied[ $user_id . '|' . $new ] ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Renaming the plugin's own meta row.
				$wpdb->update(
					$wpdb->usermeta,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Renaming the plugin's own meta row.
					array( 'meta_key' => $new ),
					array( 'umeta_id' => (int) $row->umeta_id ),
					array( '%s' ),
					array( '%d' )
				);

				wp_cache_delete( $user_id, 'user_meta' );
			}
		}
	}

	/**
	 * Move post meta keys to the new prefix (order data and paywall settings).
	 *
	 * These keys start with an underscore, so the pattern has to include it.
	 *
	 * @return void
	 */
	private static function rename_post_meta() {
		global $wpdb;

		foreach ( self::meta_prefixes() as $pair ) {
			list( $old_prefix, $new_prefix ) = $pair;

			$old_prefix = '_' . $old_prefix;
			$new_prefix = '_' . $new_prefix;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the plugin's own meta rows by key prefix.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( $old_prefix ) . '%'
				)
			);

			if ( empty( $rows ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Which new keys already exist, before anything is moved.
			$taken = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( $new_prefix ) . '%'
				),
				ARRAY_A
			);

			$occupied = array();

			foreach ( (array) $taken as $row ) {
				$occupied[ (int) $row['post_id'] . '|' . $row['meta_key'] ] = true;
			}

			foreach ( (array) $rows as $row ) {
				$post_id = (int) $row->post_id;
				$new     = $new_prefix . substr( (string) $row->meta_key, strlen( $old_prefix ) );

				if ( $new === $row->meta_key || isset( $occupied[ $post_id . '|' . $new ] ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Renaming the plugin's own meta row.
				$wpdb->update(
					$wpdb->postmeta,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Renaming the plugin's own meta row.
					array( 'meta_key' => $new ),
					array( 'meta_id' => (int) $row->meta_id ),
					array( '%s' ),
					array( '%d' )
				);

				wp_cache_delete( $post_id, 'post_meta' );
			}
		}
	}

	/**
	 * Move the avatar and order post types to their new slugs.
	 *
	 * @return void
	 */
	private static function rename_post_types() {
		global $wpdb;

		foreach ( array( 'avatar', 'order' ) as $type ) {
			$new = 'at8mluc_' . $type;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the plugin's own posts by type.
			$ids = $wpdb->get_col(
				$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", self::legacy( $new ) )
			);

			foreach ( (array) $ids as $id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Moving the plugin's own post to the new type.
				$wpdb->update(
					$wpdb->posts,
					array( 'post_type' => $new ),
					array( 'ID' => (int) $id ),
					array( '%s' ),
					array( '%d' )
				);

				clean_post_cache( (int) $id );
			}
		}
	}

	/**
	 * Does a table exist?
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check on the plugin's own table.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return ! empty( $found );
	}

	/**
	 * Move the two points tables to their new names.
	 *
	 * A rename is preferred because it moves the rows without touching them.
	 * Some database layers refuse it; the fallback creates the new table and
	 * copies every row into it, then leaves the old table in place.
	 *
	 * @return void
	 */
	private static function move_points_tables() {
		global $wpdb;

		foreach ( array( 'ledger', 'account' ) as $table ) {
			$new = $wpdb->prefix . 'at8mluc_points_' . $table;
			$old = self::legacy( $new );

			if ( ! self::table_exists( $old ) ) {
				continue;
			}

			if ( self::table_exists( $new ) ) {
				// Creating one of the pair creates both, so a new table only
				// counts as done when it actually holds rows.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name comes from $wpdb->prefix.
				if ( 0 < (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$new}`" ) ) {
					continue;
				}
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names come from $wpdb->prefix.
				$renamed = $wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" );

				if ( $renamed ) {
					continue;
				}
			}

			// The driver cannot rename: create the new table and copy the rows.
			AT8MLUC_Points::install();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Copying the plugin's own rows into the table with the same schema.
			$copied = $wpdb->query( "INSERT INTO `{$new}` SELECT * FROM `{$old}`" );

			if ( ! $copied ) {
				// Try again on the next request instead of marking the
				// migration as done with the rows still unreachable.
				delete_option( self::DONE_OPTION );
			}
		}
	}
}
