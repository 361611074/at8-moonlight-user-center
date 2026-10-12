<?php
/**
 * Membership levels.
 *
 * Levels are defined by the admin under User Center → Settings → Membership
 * Levels and stored in `at8_mluc_options['membership_levels']`. A user's level
 * lives in user meta (`at8_mluc_membership_level` + `at8_mluc_membership_expires`).
 *
 * The number of levels a site may define is capped by the
 * `at8_mluc_membership_level_limit` filter: the free plugin allows three
 * (including the free base level); the Pro add-on raises the cap.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Membership level registry and per-user level state.
 */
class AT8_MLUC_Membership {

	/**
	 * Built-in fallback levels, used until the admin defines their own.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $factory = array(
		'free'     => array(
			'label'       => 'Basic',
			'sort_order'  => 10,
			'expireable'  => false,
			'description' => 'Browse basic content.',
		),
		'monthly'  => array(
			'label'       => 'Monthly',
			'sort_order'  => 20,
			'expireable'  => true,
			'description' => 'Unlock monthly materials and demo videos.',
		),
		'gold'     => array(
			'label'       => 'Gold Member',
			'sort_order'  => 25,
			'expireable'  => true,
			'description' => 'Access advanced materials and worksheet downloads.',
		),
		'premium'  => array(
			'label'       => 'Premium',
			'sort_order'  => 30,
			'expireable'  => true,
			'description' => 'Unlock all premium materials, demo videos and worksheets.',
		),
		'diamond'  => array(
			'label'       => 'Diamond Member',
			'sort_order'  => 35,
			'expireable'  => true,
			'description' => 'Unlimited access to all site resources, including new content.',
		),
	);

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Membership|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Membership
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
		add_action( 'show_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );
	}

	/**
	 * Whether the free base level ("Basic") is enabled.
	 *
	 * When disabled, users without a paid level hold no level at all; content
	 * marked as free stays visible to everyone regardless.
	 *
	 * @return bool
	 */
	public static function free_enabled() {
		return ! empty( at8_mluc_get_option( 'free_level_enabled', 1 ) );
	}

	/**
	 * Maximum number of levels this installation may define.
	 *
	 * @return int
	 */
	public static function level_limit() {
		/**
		 * Filters the membership level limit.
		 *
		 * @since 2.2.0
		 *
		 * @param int $limit Maximum number of levels, including the free base level.
		 */
		return max( 1, (int) apply_filters( 'at8_mluc_membership_level_limit', 3 ) );
	}

	/**
	 * All level definitions, ordered by sort weight.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_levels() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$saved = at8_mluc_get_option( 'membership_levels', array() );

		// One-time import for sites that ran the Pro 1.x line, which kept its
		// level definitions in its own option blob.
		if ( ( ! is_array( $saved ) || empty( $saved ) ) && is_array( get_option( 'at8_mlucp_options', array() ) ) ) {
			$legacy = get_option( 'at8_mlucp_options', array() );

			if ( ! empty( $legacy['membership_levels'] ) && is_array( $legacy['membership_levels'] ) ) {
				$saved = $legacy['membership_levels'];

				$options              = get_option( 'at8_mluc_options', array() );
				$options['membership_levels'] = $saved;
				update_option( 'at8_mluc_options', $options );
			}
		}

		if ( ! is_array( $saved ) || empty( $saved ) ) {
			$levels = self::$factory;
		} else {
			$levels = array();

			foreach ( $saved as $key => $level ) {
				if ( ! is_array( $level ) ) {
					continue;
				}

				$key = sanitize_key( $key );

				if ( '' === $key ) {
					continue;
				}

				$validity = isset( $level['validity'] ) ? (int) $level['validity'] : 0;

				$levels[ $key ] = array(
					'label'       => isset( $level['label'] ) && '' !== (string) $level['label'] ? $level['label'] : $key,
					'color'       => isset( $level['color'] ) ? $level['color'] : ( isset( self::$factory[ $key ]['color'] ) ? self::$factory[ $key ]['color'] : '#2f6fed' ),
					'price'       => isset( $level['price'] ) ? (float) $level['price'] : 0,
					'validity'    => $validity,
					'sort_order'  => isset( $level['sort_order'] ) ? (int) $level['sort_order'] : ( isset( self::$factory[ $key ]['sort_order'] ) ? (int) self::$factory[ $key ]['sort_order'] : 50 ),
					'expireable'  => ( 'free' === $key ) ? false : ( $validity > 0 ),
					'description' => isset( $level['description'] ) ? (string) $level['description'] : '',
				);
			}

			foreach ( $levels as $key => $level ) {
				if ( '' === (string) $level['description'] && isset( self::$factory[ $key ]['description'] ) ) {
					$levels[ $key ]['description'] = self::$factory[ $key ]['description'];
				}
			}
		}

		if ( self::free_enabled() ) {
			if ( ! isset( $levels['free'] ) ) {
				$levels['free'] = self::$factory['free'];
			}
		} else {
			unset( $levels['free'] );
		}

		/**
		 * Filters the membership level definitions.
		 *
		 * @since 2.2.0
		 *
		 * @param array $levels Level definitions keyed by level slug.
		 */
		$levels = apply_filters( 'at8_mluc_membership_levels', $levels );

		// Final guard: the level limit also applies to factory defaults, to
		// imported legacy definitions and to levels injected by filters.
		$limit = self::level_limit();

		if ( count( $levels ) > $limit ) {
			uasort(
				$levels,
				function ( $a, $b ) {
					return ( $a['sort_order'] ?? 0 ) - ( $b['sort_order'] ?? 0 );
				}
			);

			$levels = array_slice( $levels, 0, $limit, true );
		}

		$cache = $levels;

		return $levels;
	}

	/**
	 * Label of a single level.
	 *
	 * @param string $key Level slug.
	 * @return string
	 */
	public static function get_level_label( $key ) {
		$levels = self::get_levels();

		return isset( $levels[ $key ] ) ? $levels[ $key ]['label'] : $key;
	}

	/**
	 * Price of a single level.
	 *
	 * @param string $key Level slug.
	 * @return float
	 */
	public static function get_level_price( $key ) {
		$levels = self::get_levels();

		return isset( $levels[ $key ]['price'] ) ? (float) $levels[ $key ]['price'] : 0;
	}

	/**
	 * Validity of a single level in days (0 = permanent).
	 *
	 * @param string $key Level slug.
	 * @return int
	 */
	public static function get_level_validity( $key ) {
		$levels = self::get_levels();

		return isset( $levels[ $key ]['validity'] ) ? (int) $levels[ $key ]['validity'] : 0;
	}

	/**
	 * Sort weight of a single level.
	 *
	 * @param string $level Level slug.
	 * @return int
	 */
	public static function get_level_sort_order( $level ) {
		$levels = self::get_levels();

		return isset( $levels[ $level ] ) ? (int) $levels[ $level ]['sort_order'] : 0;
	}

	/**
	 * Level badge HTML, tinted with the level colour.
	 *
	 * @param string $key Level slug.
	 * @return string
	 */
	public static function get_level_badge( $key ) {
		$levels = self::get_levels();
		$label  = isset( $levels[ $key ]['label'] ) ? $levels[ $key ]['label'] : $key;
		$color  = isset( $levels[ $key ]['color'] ) ? $levels[ $key ]['color'] : '';
		$style  = '';

		if ( $color && preg_match( '/^#[0-9a-fA-F]{3,8}$/', $color ) ) {
			$style = ' style="background:' . esc_attr( $color ) . '1a;color:' . esc_attr( $color ) . ';border:1px solid ' . esc_attr( $color ) . '33;"';
		}

		return '<span class="at8-mluc-level-badge at8-mluc-level-' . esc_attr( $key ) . '"' . $style . '>' . esc_html( $label ) . '</span>';
	}

	/**
	 * Current level of a user; empty string when they hold none.
	 *
	 * @param int $user_id Optional user ID, defaults to the current user.
	 * @return string
	 */
	public static function get_user_level( $user_id = 0 ) {
		$default = self::free_enabled() ? 'free' : '';
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return $default;
		}

		$level = get_user_meta( $user_id, 'at8_mluc_membership_level', true );

		// Legacy Pro 1.x meta key, read once for sites that migrate.
		if ( ! $level ) {
			$level = get_user_meta( $user_id, 'at8_mlucp_membership_level', true );
		}

		if ( ! $level || ! isset( self::get_levels()[ $level ] ) ) {
			$level = $default;
		}

		if ( $level !== $default && self::is_user_expired( $user_id ) ) {
			if ( '' === $default ) {
				delete_user_meta( $user_id, 'at8_mluc_membership_level' );
				delete_user_meta( $user_id, 'at8_mluc_membership_expires' );
			} else {
				self::set_user_level( $user_id, $default, 0 );
			}

			$level = $default;
		}

		return $level;
	}

	/**
	 * Whether a user's level has expired (no expiry set = never).
	 *
	 * @param int $user_id Optional user ID, defaults to the current user.
	 * @return bool
	 */
	public static function is_user_expired( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return true;
		}

		$expires = (int) get_user_meta( $user_id, 'at8_mluc_membership_expires', true );

		if ( ! $expires ) {
			$expires = (int) get_user_meta( $user_id, 'at8_mlucp_membership_expires', true );
		}

		if ( ! $expires ) {
			return false;
		}

		return $expires < time();
	}

	/**
	 * Expiry timestamp of a user's level (0 = permanent).
	 *
	 * @param int $user_id Optional user ID, defaults to the current user.
	 * @return int
	 */
	public static function get_user_expires( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return 0;
		}

		$expires = (int) get_user_meta( $user_id, 'at8_mluc_membership_expires', true );

		if ( ! $expires ) {
			$expires = (int) get_user_meta( $user_id, 'at8_mlucp_membership_expires', true );
		}

		return $expires;
	}

	/**
	 * Set a user's level and expiry. An empty level clears it.
	 *
	 * @param int    $user_id User ID.
	 * @param string $level   Level slug, or '' to clear.
	 * @param int    $expires Unix timestamp, 0 for permanent.
	 * @return bool
	 */
	public static function set_user_level( $user_id, $level, $expires = 0 ) {
		$user_id = (int) $user_id;

		if ( '' !== $level && ! isset( self::get_levels()[ $level ] ) ) {
			return false;
		}

		if ( '' === $level ) {
			delete_user_meta( $user_id, 'at8_mluc_membership_level' );
			delete_user_meta( $user_id, 'at8_mluc_membership_expires' );

			/**
			 * Fires after a user's membership level changed.
			 *
			 * @since 2.2.0
			 *
			 * @param int    $user_id User ID.
			 * @param string $level   New level slug, empty when cleared.
			 * @param int    $expires New expiry timestamp, 0 for permanent.
			 */
			do_action( 'at8_mluc_membership_changed', $user_id, '', 0 );

			return true;
		}

		update_user_meta( $user_id, 'at8_mluc_membership_level', $level );
		update_user_meta( $user_id, 'at8_mluc_membership_expires', (int) $expires );

		do_action( 'at8_mluc_membership_changed', $user_id, $level, (int) $expires );

		return true;
	}

	/**
	 * Grant a level (upgrades only, never downgrades).
	 *
	 * Timed levels extend an existing, still-valid expiry; otherwise they
	 * count from now.
	 *
	 * @param int    $user_id User ID.
	 * @param string $level   Level slug.
	 * @return bool
	 */
	public function grant_level( $user_id, $level ) {
		$user_id = (int) $user_id;
		$levels  = self::get_levels();

		if ( ! isset( $levels[ $level ] ) ) {
			return false;
		}

		$current = self::get_user_level( $user_id );

		if ( self::get_level_sort_order( $current ) >= self::get_level_sort_order( $level ) ) {
			return false;
		}

		$validity = (int) ( $levels[ $level ]['validity'] ?? 0 );

		if ( $validity <= 0 ) {
			self::set_user_level( $user_id, $level, 0 );

			return true;
		}

		$expires     = (int) get_user_meta( $user_id, 'at8_mluc_membership_expires', true );
		$base        = ( $expires && $expires > time() ) ? $expires : time();
		$new_expires = (int) strtotime( '+' . $validity . ' days', $base );

		self::set_user_level( $user_id, $level, $new_expires );

		return true;
	}

	/**
	 * Whether a user may access content gated at a level.
	 *
	 * @param string $required_level Required level slug.
	 * @param int    $user_id        Optional user ID, defaults to the current user.
	 * @return bool
	 */
	public static function user_can_access( $required_level, $user_id = 0 ) {
		if ( 'free' === $required_level ) {
			return true;
		}

		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		$current = self::get_user_level( $user_id );

		return self::get_level_sort_order( $current ) >= self::get_level_sort_order( $required_level );
	}

	/**
	 * Render the level fields on the user profile screen.
	 *
	 * @param WP_User $user User being edited.
	 * @return void
	 */
	public function render_profile_fields( $user ) {
		// Only administrators may see or change levels: edit_user is true for
		// the user themselves, which would allow self-promotion.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_level   = get_user_meta( $user->ID, 'at8_mluc_membership_level', true );
		$current_expires = (int) get_user_meta( $user->ID, 'at8_mluc_membership_expires', true );
		$default_level   = self::free_enabled() ? 'free' : '';

		if ( ! $current_level || ! isset( self::get_levels()[ $current_level ] ) ) {
			$current_level = $default_level;
		}

		$expires_value = $current_expires ? wp_date( 'Y-m-d', $current_expires ) : '';
		$expires_never = ! $current_expires ? ' checked="checked"' : '';
		?>
		<h2><?php esc_html_e( 'Membership', 'at8-moonlight-user-center' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="at8_mluc_membership_level"><?php esc_html_e( 'Level', 'at8-moonlight-user-center' ); ?></label></th>
				<td>
					<select name="at8_mluc_membership_level" id="at8_mluc_membership_level">
						<?php if ( ! self::free_enabled() ) : ?>
							<option value=""<?php selected( $current_level, '' ); ?>><?php esc_html_e( '(no level)', 'at8-moonlight-user-center' ); ?></option>
						<?php endif; ?>
						<?php foreach ( self::get_levels() as $key => $level ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $current_level, $key ); ?>>
								<?php echo esc_html( $level['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Levels are configured under User Center → Settings → Membership Levels.', 'at8-moonlight-user-center' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="at8_mluc_membership_expires_date"><?php esc_html_e( 'Expires', 'at8-moonlight-user-center' ); ?></label></th>
				<td>
					<input type="date" name="at8_mluc_membership_expires_date" id="at8_mluc_membership_expires_date" value="<?php echo esc_attr( $expires_value ); ?>" />
					<label style="margin-left:1em;">
						<input type="checkbox" name="at8_mluc_membership_expires_never" value="1"<?php echo $expires_never; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string. ?> />
						<?php esc_html_e( 'Never expires', 'at8-moonlight-user-center' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'With “Never expires” checked the date is ignored; otherwise the level ends at 23:59 site time on that day.', 'at8-moonlight-user-center' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the level fields from the user profile screen.
	 *
	 * @param int $user_id User being saved.
	 * @return void
	 */
	public function save_profile_fields( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Nothing submitted means nothing to change: other plugins may fire
		// the profile save hooks without rendering these fields.
		if ( ! isset( $_POST['at8_mluc_membership_level'] ) ) {
			return;
		}

		$level  = sanitize_key( wp_unslash( $_POST['at8_mluc_membership_level'] ) );
		$never  = ! empty( $_POST['at8_mluc_membership_expires_never'] );
		$date   = isset( $_POST['at8_mluc_membership_expires_date'] ) ? sanitize_text_field( wp_unslash( $_POST['at8_mluc_membership_expires_date'] ) ) : '';
		$expires = 0;

		if ( ! $never && $date ) {
			$date_time = date_create_from_format( 'Y-m-d H:i:s', $date . ' 23:59:59', wp_timezone() );

			if ( $date_time instanceof DateTime ) {
				$expires = (int) $date_time->getTimestamp();
			}
		}

		self::set_user_level( $user_id, $level, $expires );
	}
}

/**
 * Current level of a user.
 *
 * @param int $user_id Optional user ID, defaults to the current user.
 * @return string
 */
function at8_mluc_user_level( $user_id = 0 ) {
	return AT8_MLUC_Membership::get_user_level( $user_id );
}

/**
 * Whether a user may access content gated at a level.
 *
 * @param string $required_level Required level slug.
 * @param int    $user_id        Optional user ID, defaults to the current user.
 * @return bool
 */
function at8_mluc_user_can_access( $required_level, $user_id = 0 ) {
	return AT8_MLUC_Membership::user_can_access( $required_level, $user_id );
}

/**
 * Whether a user's level has expired.
 *
 * @param int $user_id Optional user ID, defaults to the current user.
 * @return bool
 */
function at8_mluc_user_is_expired( $user_id = 0 ) {
	return AT8_MLUC_Membership::is_user_expired( $user_id );
}
