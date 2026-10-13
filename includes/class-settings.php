<?php
/**
 * Admin settings screen.
 *
 * The screen lives under the top-level "User Center" menu and is organised as
 * one page with card sections: a small script wraps every settings section in
 * a card and builds the sticky side navigation from the section titles. The
 * Pro add-on appends its own sections through the `at8_mluc_register_settings`
 * action, so the whole plugin family shares one settings page.
 *
 * Uses the WordPress Settings API, so nonce handling and the capability check
 * are performed by WordPress itself before `sanitize_callback` runs.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen and option sanitisation.
 */
class AT8_MLUC_Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'at8_mluc_options';

	/**
	 * Settings group.
	 *
	 * @var string
	 */
	const GROUP = 'at8_mluc_settings';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE = 'at8-mluc-settings';

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Settings|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Settings
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
		/*
		 * Priority 5 (before the default 10): add_menu_page() populates
		 * $admin_page_hooks, and every add_submenu_page() call that targets
		 * this menu has to run afterwards, otherwise WordPress derives a
		 * different page hook at registration time than it does when the
		 * page is opened and rejects the request with a 403. Pro and the
		 * license SDK register their submenus on the default priority.
		 */
		add_action( 'admin_menu', array( $this, 'add_menu' ), 5 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
		add_filter( 'plugin_action_links_' . AT8_MLUC_PLUGIN_BASENAME, array( $this, 'add_action_link' ) );
	}

	/**
	 * Add the top-level User Center menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'User Center', 'at8-moonlight-user-center' ),
			__( 'User Center', 'at8-moonlight-user-center' ),
			AT8_MLUC_Security::manage_capability(),
			self::PAGE,
			array( $this, 'render_page' ),
			'dashicons-buddicons-buddypress-logo',
			56
		);

		// Rename the auto-generated first submenu item.
		add_submenu_page(
			self::PAGE,
			__( 'Settings', 'at8-moonlight-user-center' ),
			__( 'Settings', 'at8-moonlight-user-center' ),
			AT8_MLUC_Security::manage_capability(),
			self::PAGE
		);

		add_submenu_page(
			self::PAGE,
			__( 'Avatar Library', 'at8-moonlight-user-center' ),
			__( 'Avatar Library', 'at8-moonlight-user-center' ),
			AT8_MLUC_Security::manage_capability(),
			'edit.php?post_type=' . AT8_MLUC_Avatar::POST_TYPE
		);

		/**
		 * Fires right after the User Center menu has been built.
		 *
		 * Extensions should add their own entries here rather than on
		 * `admin_menu`: this action runs after add_menu_page() has populated
		 * the core menu hooks, so add_submenu_page() derives the same page
		 * hook here as WordPress does when the page is opened. Registering
		 * on the default `admin_menu` priority can produce a mismatched
		 * hook and an unexplained 403 instead.
		 *
		 * @since 2.2.1
		 */
		do_action( 'at8_mluc_admin_menu' );
	}

	/**
	 * Enqueue the settings screen assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'at8-mluc-admin',
			AT8_MLUC_PLUGIN_URL . 'assets/css/at8-mluc-admin.css',
			array(),
			AT8_MLUC_VERSION
		);

		wp_enqueue_script(
			'at8-mluc-admin',
			AT8_MLUC_PLUGIN_URL . 'assets/js/at8-mluc-admin.js',
			array( 'jquery' ),
			AT8_MLUC_VERSION,
			true
		);

		wp_localize_script(
			'at8-mluc-admin',
			'at8MlucAdmin',
			array(
				'navTitle'   => __( 'Sections', 'at8-moonlight-user-center' ),
				'frameTitle' => __( 'Select or upload an icon', 'at8-moonlight-user-center' ),
				'useIcon'    => __( 'Use this icon', 'at8-moonlight-user-center' ),
				'saving'     => __( 'Saving…', 'at8-moonlight-user-center' ),
				'saved'      => __( 'Fields saved.', 'at8-moonlight-user-center' ),
				'ajax'       => array(
					'url'   => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'at8_mluc_nonce' ),
				),
			)
		);
	}

	/**
	 * Add a settings shortcut on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_action_link( $links ) {
		if ( ! current_user_can( AT8_MLUC_Security::manage_capability() ) ) {
			return $links;
		}

		$url = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'at8-moonlight-user-center' ) )
		);

		return $links;
	}

	/**
	 * Register the option, the sections and their fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => AT8_MLUC_Activator::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'at8_mluc_general',
			__( 'General Settings', 'at8-moonlight-user-center' ),
			array( $this, 'section_general' ),
			self::PAGE
		);

		$this->add_field( 'account_page_id', __( 'Account page', 'at8-moonlight-user-center' ), 'general', 'page_select' );
		$this->add_field( 'login_page_id', __( 'Login page', 'at8-moonlight-user-center' ), 'general', 'page_select' );
		$this->add_field( 'register_page_id', __( 'Registration page', 'at8-moonlight-user-center' ), 'general', 'page_select' );
		$this->add_field( 'lostpassword_page_id', __( 'Password recovery page', 'at8-moonlight-user-center' ), 'general', 'page_select' );
		$this->add_field( 'redirect_after_login', __( 'Redirect after login', 'at8-moonlight-user-center' ), 'general', 'url' );
		$this->add_field( 'enable_registration', __( 'Front-end registration', 'at8-moonlight-user-center' ), 'general', 'checkbox' );
		$this->add_field( 'auto_login', __( 'Log in after registration', 'at8-moonlight-user-center' ), 'general', 'checkbox' );
		$this->add_field( 'enable_avatar', __( 'Avatar library', 'at8-moonlight-user-center' ), 'general', 'checkbox' );
		$this->add_field( 'accent_color', __( 'Accent colour', 'at8-moonlight-user-center' ), 'general', 'color' );
		$this->add_field( 'terms_enabled', __( 'Require terms acceptance on registration', 'at8-moonlight-user-center' ), 'general', 'checkbox' );
		$this->add_field( 'terms_page_id', __( 'Terms page', 'at8-moonlight-user-center' ), 'general', 'page_select' );
		$this->add_field( 'terms_text', __( 'Terms checkbox text', 'at8-moonlight-user-center' ), 'general', 'text' );

		add_settings_section(
			'at8_mluc_membership',
			__( 'Membership Levels', 'at8-moonlight-user-center' ),
			array( $this, 'section_membership' ),
			self::PAGE
		);

		add_settings_field(
			'at8_mluc_field_membership_levels',
			__( 'Level configuration', 'at8-moonlight-user-center' ),
			array( $this, 'render_membership_field' ),
			self::PAGE,
			'at8_mluc_membership'
		);

		add_settings_section(
			'at8_mluc_payment',
			__( 'Offline Payments', 'at8-moonlight-user-center' ),
			array( $this, 'section_payment' ),
			self::PAGE
		);

		$this->add_field( 'manual_enabled', __( 'Bank transfer (admin confirms)', 'at8-moonlight-user-center' ), 'payment', 'checkbox' );
		$this->add_field( 'pay_currency_symbol', __( 'Currency symbol', 'at8-moonlight-user-center' ), 'payment', 'text' );
		$this->add_field( 'pay_currency_code', __( 'Currency code (ISO, for online gateways)', 'at8-moonlight-user-center' ), 'payment', 'text' );
		$this->add_field( 'pay_manual_instructions', __( 'Transfer instructions', 'at8-moonlight-user-center' ), 'payment', 'textarea' );
		$this->add_field( 'order_auto_close_hours', __( 'Auto-close unpaid orders after (hours)', 'at8-moonlight-user-center' ), 'payment', 'number' );

		add_settings_section(
			'at8_mluc_points',
			__( 'Points', 'at8-moonlight-user-center' ),
			'__return_false',
			self::PAGE
		);

		$this->add_field( 'points_label', __( 'Unit name', 'at8-moonlight-user-center' ), 'points', 'text' );
		$this->add_field( 'points_per_currency', __( 'Points earned per currency unit spent', 'at8-moonlight-user-center' ), 'points', 'number' );
		$this->add_field( 'points_signup_bonus', __( 'Bonus for a new account', 'at8-moonlight-user-center' ), 'points', 'number' );

		add_settings_section(
			'at8_mluc_nav_icons',
			__( 'Sidebar Menu Icons', 'at8-moonlight-user-center' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			'at8_mluc_field_nav_icons',
			__( 'Tab icons', 'at8-moonlight-user-center' ),
			array( $this, 'render_nav_icons_field' ),
			self::PAGE,
			'at8_mluc_nav_icons'
		);

		add_settings_section(
			'at8_mluc_ui_labels',
			__( 'Interface Text (User Center)', 'at8-moonlight-user-center' ),
			array( $this, 'section_ui_labels' ),
			self::PAGE
		);

		add_settings_field(
			'at8_mluc_field_ui_labels',
			__( 'Labels', 'at8-moonlight-user-center' ),
			array( $this, 'render_ui_labels_field' ),
			self::PAGE,
			'at8_mluc_ui_labels'
		);

		add_settings_section(
			'at8_mluc_oauth',
			__( 'Social Login', 'at8-moonlight-user-center' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			'at8_mluc_field_oauth',
			__( 'OAuth credentials', 'at8-moonlight-user-center' ),
			array( $this, 'render_oauth_field' ),
			self::PAGE,
			'at8_mluc_oauth'
		);

		add_settings_section(
			'at8_mluc_avatar_library',
			__( 'Avatar Library', 'at8-moonlight-user-center' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			'at8_mluc_field_avatar_library',
			__( 'Manage avatars', 'at8-moonlight-user-center' ),
			array( $this, 'render_avatar_library_field' ),
			self::PAGE,
			'at8_mluc_avatar_library'
		);

		/**
		 * Fires after the free plugin registered its settings sections.
		 *
		 * Add-ons register additional sections and fields on the same page
		 * through `add_settings_section( ..., AT8_MLUC_Settings::PAGE )`.
		 *
		 * @since 2.2.0
		 */
		do_action( 'at8_mluc_register_settings' );
	}

	/**
	 * Register a single field.
	 *
	 * @param string $key     Setting key.
	 * @param string $label   Field label.
	 * @param string $section Section key.
	 * @param string $type    Control type.
	 * @return void
	 */
	private function add_field( $key, $label, $section, $type ) {
		add_settings_field(
			'at8_mluc_field_' . $key,
			$label,
			array( $this, 'render_field' ),
			self::PAGE,
			'at8_mluc_' . $section,
			array(
				'key'       => $key,
				'type'      => $type,
				'label_for' => 'at8_mluc_field_' . $key,
			)
		);
	}

	/**
	 * General section description.
	 *
	 * @return void
	 */
	public function section_general() {
		echo '<p>' . esc_html__( 'Choose which pages hold the plugin shortcodes. Leave a page unset to fall back to the WordPress login screens.', 'at8-moonlight-user-center' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'The accent colour tints buttons, active states and membership cards. Leave it empty to inherit a neutral indigo.', 'at8-moonlight-user-center' ) . '</p>';
	}

	/**
	 * Membership section description.
	 *
	 * @return void
	 */
	public function section_membership() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Define the membership levels members can buy. There is no limit on the number of levels.', 'at8-moonlight-user-center' )
		);
	}

	/**
	 * Offline payments section description.
	 *
	 * @return void
	 */
	public function section_payment() {
		echo '<p>' . esc_html__( 'Members bought through bank transfer: the member transfers, then you confirm the payment on the order screen and the level is granted automatically.', 'at8-moonlight-user-center' ) . '</p>';

		AT8_MLUC_Upgrade::render_gateway_panel();
	}

	/**
	 * Interface text section description.
	 *
	 * @return void
	 */
	public function section_ui_labels() {
		echo '<p>' . esc_html__( 'Leave a field empty to use the built-in default text.', 'at8-moonlight-user-center' ) . '</p>';
	}

	/**
	 * Render a settings control.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_field( $args ) {
		$key   = $args['key'];
		$type  = $args['type'];
		$id    = 'at8_mluc_field_' . $key;
		$name  = self::OPTION . '[' . $key . ']';
		$value = at8_mluc_get_option( $key, '' );

		switch ( $type ) {
			case 'page_select':
				$pages = get_pages(
					array(
						'sort_column' => 'post_title',
						'number'      => 300,
					)
				);

				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				printf( '<option value="0">%s</option>', esc_html__( '— Not set —', 'at8-moonlight-user-center' ) );

				foreach ( $pages as $page ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( (string) $page->ID ),
						selected( (int) $value, (int) $page->ID, false ),
						esc_html( $page->post_title )
					);
				}

				echo '</select>';
				break;

			case 'checkbox':
				printf(
					'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html__( 'Enabled', 'at8-moonlight-user-center' )
				);
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" class="large-text" rows="4">%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( (string) $value )
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" class="small-text" value="%3$s" min="0" max="8760" step="1" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;

			case 'color':
				printf(
					'<input type="color" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);

				echo '<button type="button" class="button button-small at8-mluc-color-reset" data-target="' . esc_attr( $id ) . '">' . esc_html__( 'Reset', 'at8-moonlight-user-center' ) . '</button>';
				break;

			case 'url':
				printf(
					'<input type="url" class="regular-text" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_url( (string) $value ),
					esc_attr( at8_mluc_get_account_url() )
				);
				break;

			default:
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;
		}

		if ( 'delete_data' === $key ) {
			echo '<p class="description">' . esc_html__( 'When enabled, deleting the plugin removes its settings and the profile fields it added. WordPress user accounts, posts and comments are never removed.', 'at8-moonlight-user-center' ) . '</p>';
		}

		if ( 'enable_registration' === $key && ! get_option( 'users_can_register' ) ) {
			$settings_url = admin_url( 'options-general.php' );

			printf(
				'<p class="description">%s</p>',
				sprintf(
					/* translators: %s: link to the WordPress general settings screen. */
					esc_html__( 'Registration also requires the “Anyone can register” option in %s.', 'at8-moonlight-user-center' ),
					'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'General Settings', 'at8-moonlight-user-center' ) . '</a>'
				)
			);
		}

		if ( 'enable_avatar' === $key ) {
			$library_url = admin_url( 'edit.php?post_type=' . AT8_MLUC_Avatar::POST_TYPE );

			printf(
				'<p class="description">%s</p>',
				sprintf(
					/* translators: %s: link to the avatar library admin screen. */
					esc_html__( 'Manage the selectable avatars in the %s.', 'at8-moonlight-user-center' ),
					'<a href="' . esc_url( $library_url ) . '">' . esc_html__( 'avatar library', 'at8-moonlight-user-center' ) . '</a>'
				)
			);
		}

		if ( 'order_auto_close_hours' === $key ) {
			echo '<p class="description">' . esc_html__( 'Unpaid orders older than this are cancelled automatically. Set 0 to keep them open. Keep it at 24 or more so online checkout sessions are never cut short.', 'at8-moonlight-user-center' ) . '</p>';
		}

		if ( 'pay_currency_symbol' === $key ) {
			echo '<p class="description">' . esc_html__( 'Shown before prices in the purchase flow, for example $ or ¥.', 'at8-moonlight-user-center' ) . '</p>';
		}
	}

	/**
	 * Render the membership levels editor.
	 *
	 * @return void
	 */
	public function render_membership_field() {
		$levels  = AT8_MLUC_Membership::get_levels();
		$free_on = AT8_MLUC_Membership::free_enabled();
		$used    = count( $levels );

		// Derived from the membership registry so the editor and the front-end
		// badges always agree on a level's fallback colour.
		$default_colors = array();

		foreach ( AT8_MLUC_Membership::factory_levels() as $key => $level ) {
			$default_colors[ $key ] = isset( $level['color'] ) ? $level['color'] : '#2f6fed';
		}
		?>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[free_level_enabled]" value="1"<?php checked( $free_on ); ?>>
				<strong><?php esc_html_e( 'Enable the Basic (free base) level', 'at8-moonlight-user-center' ); ?></strong>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'When off, only paid levels remain: members without a purchase hold no level at all. Content marked as free stays visible to everyone.', 'at8-moonlight-user-center' ); ?>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %d: number of levels defined. */
				esc_html__( 'Levels defined: %d. Add as many tiers as you need.', 'at8-moonlight-user-center' ),
				(int) $used
			);
			?>
		</p>
		<table class="at8-mluc-membership-table widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Key', 'at8-moonlight-user-center' ); ?></th>
					<th><?php esc_html_e( 'Name', 'at8-moonlight-user-center' ); ?></th>
					<th><?php esc_html_e( 'Price', 'at8-moonlight-user-center' ); ?></th>
					<th><?php esc_html_e( 'Validity (days)', 'at8-moonlight-user-center' ); ?></th>
					<th><?php esc_html_e( 'Points cost', 'at8-moonlight-user-center' ); ?></th>
					<th><?php esc_html_e( 'Colour', 'at8-moonlight-user-center' ); ?></th>
					<th><?php esc_html_e( 'Sort', 'at8-moonlight-user-center' ); ?></th>
					<th><?php esc_html_e( 'Action', 'at8-moonlight-user-center' ); ?></th>
				</tr>
			</thead>
			<tbody id="at8-mluc-levels-tbody">
				<?php foreach ( $levels as $key => $level ) : ?>
					<?php
					$is_free  = ( 'free' === $key );
					$label    = isset( $level['label'] ) ? $level['label'] : $key;
					$price    = isset( $level['price'] ) ? $level['price'] : 0;
					$validity = isset( $level['validity'] ) ? (int) $level['validity'] : 0;
					$color    = ! empty( $level['color'] ) ? $level['color'] : ( isset( $default_colors[ $key ] ) ? $default_colors[ $key ] : '#2f6fed' );
					$sort     = isset( $level['sort_order'] ) ? (int) $level['sort_order'] : 50;
					$prefix   = self::OPTION . '[membership_levels][' . $key . ']';
					?>
					<tr>
						<td>
							<code><?php echo esc_html( $key ); ?></code>
							<?php if ( $is_free ) : ?><span class="at8-mluc-fixed-tag"><?php esc_html_e( 'base', 'at8-moonlight-user-center' ); ?></span><?php endif; ?>
						</td>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $label ); ?>"></td>
						<td>
							<?php if ( $is_free ) : ?>
								—
							<?php else : ?>
								<input type="number" step="0.01" min="0" class="small-text" name="<?php echo esc_attr( $prefix ); ?>[price]" value="<?php echo esc_attr( $price ); ?>">
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $is_free ) : ?>
								<?php esc_html_e( 'permanent', 'at8-moonlight-user-center' ); ?>
							<?php else : ?>
								<input type="number" min="0" class="small-text" name="<?php echo esc_attr( $prefix ); ?>[validity]" value="<?php echo esc_attr( $validity ); ?>">
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $is_free ) : ?>
								—
							<?php else : ?>
								<input type="number" min="0" class="small-text" name="<?php echo esc_attr( $prefix ); ?>[points]" value="<?php echo esc_attr( isset( $level['points'] ) ? (int) $level['points'] : 0 ); ?>">
							<?php endif; ?>
						</td>
						<td><input type="color" name="<?php echo esc_attr( $prefix ); ?>[color]" value="<?php echo esc_attr( $color ); ?>"></td>
						<td><input type="number" min="0" class="small-text" name="<?php echo esc_attr( $prefix ); ?>[sort_order]" value="<?php echo esc_attr( $sort ); ?>"></td>
						<td>
							<button type="button" class="at8-mluc-del-level"><?php esc_html_e( 'Delete', 'at8-moonlight-user-center' ); ?></button>
							<label class="at8-mluc-del-check"><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[delete]" value="1"> <?php esc_html_e( 'Delete', 'at8-moonlight-user-center' ); ?></label>
							<?php if ( $is_free ) : ?><span class="description"><?php esc_html_e( 'deleting the base level turns Basic members off', 'at8-moonlight-user-center' ); ?></span><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="at8-mluc-add-level"><?php esc_html_e( '+ Add level', 'at8-moonlight-user-center' ); ?></button></p>

		<template id="at8-mluc-level-row-tpl">
			<tr>
				<td><input type="text" class="regular-text" data-name="<?php echo esc_attr( self::OPTION ); ?>[membership_levels][__NEWKEY__][key]" placeholder="level_key"></td>
				<td><input type="text" class="regular-text" data-name="<?php echo esc_attr( self::OPTION ); ?>[membership_levels][__NEWKEY__][label]" placeholder="<?php esc_attr_e( 'Name', 'at8-moonlight-user-center' ); ?>"></td>
				<td><input type="number" step="0.01" min="0" class="small-text" data-name="<?php echo esc_attr( self::OPTION ); ?>[membership_levels][__NEWKEY__][price]" value="0"></td>
				<td><input type="number" min="0" class="small-text" data-name="<?php echo esc_attr( self::OPTION ); ?>[membership_levels][__NEWKEY__][validity]" value="30"></td>
				<td><input type="number" min="0" class="small-text" data-name="<?php echo esc_attr( self::OPTION ); ?>[membership_levels][__NEWKEY__][points]" value="0"></td>
				<td><input type="color" data-name="<?php echo esc_attr( self::OPTION ); ?>[membership_levels][__NEWKEY__][color]" value="#2f6fed"></td>
				<td><input type="number" min="0" class="small-text" data-name="<?php echo esc_attr( self::OPTION ); ?>[membership_levels][__NEWKEY__][sort_order]" value="50"></td>
				<td><button type="button" class="at8-mluc-del-level"><?php esc_html_e( 'Delete', 'at8-moonlight-user-center' ); ?></button></td>
			</tr>
		</template>
		<?php
	}

	/**
	 * Render the sidebar menu icon editor.
	 *
	 * @return void
	 */
	public function render_nav_icons_field() {
		if ( ! class_exists( 'AT8_MLUC_Shortcodes' ) ) {
			echo '<p class="description">' . esc_html__( 'The account dashboard module is not loaded.', 'at8-moonlight-user-center' ) . '</p>';
			return;
		}

		$tabs  = AT8_MLUC_Shortcodes::get_instance()->get_tabs();
		$saved = at8_mluc_get_option( 'nav_icons', array() );
		$saved = is_array( $saved ) ? $saved : array();

		$suggestions = array(
			'dashicons-dashboard',
			'dashicons-admin-home',
			'dashicons-admin-users',
			'dashicons-groups',
			'dashicons-edit',
			'dashicons-id',
			'dashicons-id-alt',
			'dashicons-star-filled',
			'dashicons-awards',
			'dashicons-cart',
			'dashicons-store',
			'dashicons-clipboard',
			'dashicons-download',
			'dashicons-upload',
			'dashicons-media-default',
			'dashicons-tickets-alt',
			'dashicons-money-alt',
			'dashicons-book',
			'dashicons-book-alt',
			'dashicons-lock',
			'dashicons-migrate',
			'dashicons-exit',
		);
		?>
		<table class="at8-mluc-nav-icons-table widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Menu item', 'at8-moonlight-user-center' ); ?></th>
					<th><?php esc_html_e( 'Icon', 'at8-moonlight-user-center' ); ?></th>
					<th><?php esc_html_e( 'Preview', 'at8-moonlight-user-center' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tabs as $key => $tab ) : ?>
					<?php
					$tab_key = sanitize_key( $key );
					$current = isset( $saved[ $tab_key ] ) && is_array( $saved[ $tab_key ] ) ? $saved[ $tab_key ] : array();
					$type    = isset( $current['type'] ) && 'image' === $current['type'] ? 'image' : 'dashicon';
					$value   = isset( $current['value'] ) ? (string) $current['value'] : '';
					$prefix  = self::OPTION . '[nav_icons][' . $tab_key . ']';
					$title   = isset( $tab['title'] ) ? $tab['title'] : $tab_key;

					if ( 'image' === $type && $value ) {
						$preview_class = 'at8-mluc-preview-img';
						$preview_html  = wp_get_attachment_image( (int) $value, 'thumbnail' );
					} else {
						$preview_class = 'dashicons ' . ( $value ? $value : 'dashicons-minus' );
						$preview_html  = '';
					}
					?>
					<tr>
						<td><strong><?php echo esc_html( $title ); ?></strong></td>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php echo esc_html( $title ); ?></legend>
								<label style="display:block;">
									<input type="radio" class="at8-mluc-icon-type" name="<?php echo esc_attr( $prefix ); ?>[type]" value="dashicon" <?php checked( 'dashicon', $type ); ?>>
									<?php esc_html_e( 'Dashicon', 'at8-moonlight-user-center' ); ?>
								</label>
								<input type="text" class="regular-text at8-mluc-icon-input" name="<?php echo esc_attr( $prefix ); ?>[value]" value="<?php echo esc_attr( 'dashicon' === $type ? $value : '' ); ?>" list="at8-mluc-common-icons" placeholder="dashicons-admin-users">
								<label style="display:block;margin-top:6px;">
									<input type="radio" class="at8-mluc-icon-type" name="<?php echo esc_attr( $prefix ); ?>[type]" value="image" <?php checked( 'image', $type ); ?>>
									<?php esc_html_e( 'Image', 'at8-moonlight-user-center' ); ?>
								</label>
								<span class="at8-mluc-icon-image">
									<input type="hidden" class="at8-mluc-icon-image-id" name="<?php echo esc_attr( $prefix ); ?>[value]" value="<?php echo esc_attr( 'image' === $type ? $value : '' ); ?>">
									<button type="button" class="button button-small at8-mluc-icon-upload"><?php esc_html_e( 'Select image', 'at8-moonlight-user-center' ); ?></button>
									<span class="at8-mluc-icon-image-preview"><?php echo 'image' === $type ? wp_get_attachment_image( (int) $value, 'thumbnail' ) : ''; ?></span>
									<button type="button" class="button-link at8-mluc-icon-remove"><?php esc_html_e( 'Remove', 'at8-moonlight-user-center' ); ?></button>
								</span>
							</fieldset>
						</td>
						<td>
							<span class="<?php echo esc_attr( $preview_class ); ?>" data-at8-mluc-icon-preview><?php echo $preview_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image returns safe HTML. ?></span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<datalist id="at8-mluc-common-icons">
			<?php foreach ( $suggestions as $suggestion ) : ?>
				<option value="<?php echo esc_attr( $suggestion ); ?>"></option>
			<?php endforeach; ?>
		</datalist>
		<p class="description">
			<?php esc_html_e( 'Pick a built-in Dashicon class or upload a custom image for each menu item. Empty fields use the plugin default icon.', 'at8-moonlight-user-center' ); ?>
		</p>
		<?php
	}

	/**
	 * Label definitions editable in the Interface Text section.
	 *
	 * @return array<string, array<string, array<string, string>>>
	 */
	private function label_fields() {
		return array(
			'sidebar'  => array(
				'heading_login'          => array(
					't' => __( 'Login heading', 'at8-moonlight-user-center' ),
					'd' => __( 'Login', 'at8-moonlight-user-center' ),
				),
				'heading_register'       => array(
					't' => __( 'Registration heading', 'at8-moonlight-user-center' ),
					'd' => __( 'Register', 'at8-moonlight-user-center' ),
				),
				'heading_lostpassword'   => array(
					't' => __( 'Password recovery heading', 'at8-moonlight-user-center' ),
					'd' => __( 'Lost password', 'at8-moonlight-user-center' ),
				),
				'tab_overview'           => array(
					't' => __( 'Overview tab', 'at8-moonlight-user-center' ),
					'd' => __( 'Overview', 'at8-moonlight-user-center' ),
				),
				'tab_profile'            => array(
					't' => __( 'Profile tab', 'at8-moonlight-user-center' ),
					'd' => __( 'Profile', 'at8-moonlight-user-center' ),
				),
				'tab_avatar'             => array(
					't' => __( 'Avatar tab', 'at8-moonlight-user-center' ),
					'd' => __( 'Avatar', 'at8-moonlight-user-center' ),
				),
				'tab_security'           => array(
					't' => __( 'Password tab', 'at8-moonlight-user-center' ),
					'd' => __( 'Password', 'at8-moonlight-user-center' ),
				),
				'tab_membership'         => array(
					't' => __( 'Membership tab', 'at8-moonlight-user-center' ),
					'd' => __( 'Membership', 'at8-moonlight-user-center' ),
				),
				'tab_logout'             => array(
					't' => __( 'Log out menu item', 'at8-moonlight-user-center' ),
					'd' => __( 'Log Out', 'at8-moonlight-user-center' ),
				),
				'no_member'              => array(
					't' => __( 'No membership', 'at8-moonlight-user-center' ),
					'd' => __( 'Non-member', 'at8-moonlight-user-center' ),
				),
				'purchases_login'        => array(
					't' => __( 'Login prompt', 'at8-moonlight-user-center' ),
					'd' => __( 'Please log in first to view your purchased content.', 'at8-moonlight-user-center' ),
				),
			),
			'membership' => array(
				'mb_current_title'        => array(
					't' => __( 'Current membership title', 'at8-moonlight-user-center' ),
					'd' => __( 'Current Membership', 'at8-moonlight-user-center' ),
				),
				'mb_free_tip'             => array(
					't' => __( 'Basic member tip', 'at8-moonlight-user-center' ),
					'd' => __( 'You are currently a Basic member. Upgrade to unlock more content.', 'at8-moonlight-user-center' ),
				),
				'mb_none_tip'             => array(
					't' => __( 'No membership tip', 'at8-moonlight-user-center' ),
					'd' => __( 'You do not have a membership yet. Upgrade to unlock more content.', 'at8-moonlight-user-center' ),
				),
				'mb_expired_tip'          => array(
					't' => __( 'Expired tip', 'at8-moonlight-user-center' ),
					'd' => __( 'Your membership has expired. Please renew to continue.', 'at8-moonlight-user-center' ),
				),
				'mb_permanent'            => array(
					't' => __( 'Lifetime label', 'at8-moonlight-user-center' ),
					'd' => __( 'Lifetime member', 'at8-moonlight-user-center' ),
				),
				'mb_table_title'          => array(
					't' => __( 'Level table title', 'at8-moonlight-user-center' ),
					'd' => __( 'Membership Levels', 'at8-moonlight-user-center' ),
				),
				'mb_table_sub'            => array(
					't' => __( 'Level table subtitle', 'at8-moonlight-user-center' ),
					'd' => __( 'Compare the benefits and your current status of each level.', 'at8-moonlight-user-center' ),
				),
				'mb_th_desc'              => array(
					't' => __( 'Description column', 'at8-moonlight-user-center' ),
					'd' => __( 'Description', 'at8-moonlight-user-center' ),
				),
				'mb_st_current'           => array(
					't' => __( 'Current status', 'at8-moonlight-user-center' ),
					'd' => __( 'Current', 'at8-moonlight-user-center' ),
				),
				'mb_st_included'          => array(
					't' => __( 'Included status', 'at8-moonlight-user-center' ),
					'd' => __( 'Included', 'at8-moonlight-user-center' ),
				),
				'mb_st_locked'            => array(
					't' => __( 'Locked status', 'at8-moonlight-user-center' ),
					'd' => __( 'Locked', 'at8-moonlight-user-center' ),
				),
				'th_level'                => array(
					't' => __( 'Level column', 'at8-moonlight-user-center' ),
					'd' => __( 'Level', 'at8-moonlight-user-center' ),
				),
				'th_status'               => array(
					't' => __( 'Status column', 'at8-moonlight-user-center' ),
					'd' => __( 'Status', 'at8-moonlight-user-center' ),
				),
				'permanent'               => array(
					't' => __( 'Permanent label', 'at8-moonlight-user-center' ),
					'd' => __( 'Permanent', 'at8-moonlight-user-center' ),
				),
			),
			'purchase' => array(
				'buy_btn'                 => array(
					't' => __( 'Buy button', 'at8-moonlight-user-center' ),
					'd' => __( 'Buy', 'at8-moonlight-user-center' ),
				),
				'buy_gateway_manual'      => array(
					't' => __( 'Bank transfer gateway', 'at8-moonlight-user-center' ),
					'd' => __( 'Bank Transfer (admin confirms payment)', 'at8-moonlight-user-center' ),
				),
				'buy_instructions'        => array(
					't' => __( 'Instructions title', 'at8-moonlight-user-center' ),
					'd' => __( 'Payment Instructions', 'at8-moonlight-user-center' ),
				),
				'buy_instructions_body'   => array(
					't' => __( 'Instructions body', 'at8-moonlight-user-center' ),
					'd' => __( 'Please complete the transfer as instructed below. Your membership will be activated once the administrator confirms your payment.', 'at8-moonlight-user-center' ),
				),
				'buy_history'             => array(
					't' => __( 'Order history title', 'at8-moonlight-user-center' ),
					'd' => __( 'My Purchase History', 'at8-moonlight-user-center' ),
				),
				'buy_th_order'            => array(
					't' => __( 'Order column', 'at8-moonlight-user-center' ),
					'd' => __( 'Order', 'at8-moonlight-user-center' ),
				),
				'buy_th_amount'           => array(
					't' => __( 'Amount column', 'at8-moonlight-user-center' ),
					'd' => __( 'Amount', 'at8-moonlight-user-center' ),
				),
				'buy_th_date'             => array(
					't' => __( 'Date column', 'at8-moonlight-user-center' ),
					'd' => __( 'Date', 'at8-moonlight-user-center' ),
				),
				'buy_st_pending'          => array(
					't' => __( 'Pending status', 'at8-moonlight-user-center' ),
					'd' => __( 'Pending', 'at8-moonlight-user-center' ),
				),
				'buy_st_paid'             => array(
					't' => __( 'Paid status', 'at8-moonlight-user-center' ),
					'd' => __( 'Paid', 'at8-moonlight-user-center' ),
				),
				'buy_st_cancelled'        => array(
					't' => __( 'Cancelled status', 'at8-moonlight-user-center' ),
					'd' => __( 'Cancelled', 'at8-moonlight-user-center' ),
				),
				'buy_validity_days'       => array(
					/* translators: %d is the membership validity in days. */
					't' => __( 'Validity (%d = days)', 'at8-moonlight-user-center' ),
					/* translators: %d is the membership validity in days. */
					'd' => __( 'Valid for %d days', 'at8-moonlight-user-center' ),
				),
				'buy_login_required'      => array(
					't' => __( 'Login required', 'at8-moonlight-user-center' ),
					'd' => __( 'Please log in first.', 'at8-moonlight-user-center' ),
				),
				'buy_invalid_level'       => array(
					't' => __( 'Invalid level', 'at8-moonlight-user-center' ),
					'd' => __( 'Invalid membership level.', 'at8-moonlight-user-center' ),
				),
				'buy_no_price'            => array(
					't' => __( 'No price set', 'at8-moonlight-user-center' ),
					'd' => __( 'This level has no price set and cannot be purchased yet.', 'at8-moonlight-user-center' ),
				),
				'buy_level_too_low'       => array(
					't' => __( 'Level not lower', 'at8-moonlight-user-center' ),
					'd' => __( 'Your current membership level is not lower than the selected one. No need to purchase.', 'at8-moonlight-user-center' ),
				),
				'buy_invalid_gateway'     => array(
					't' => __( 'Invalid gateway', 'at8-moonlight-user-center' ),
					'd' => __( 'Invalid payment method.', 'at8-moonlight-user-center' ),
				),
				'buy_too_many'            => array(
					't' => __( 'Too many orders', 'at8-moonlight-user-center' ),
					'd' => __( 'Too many orders in a short period. Please try again later.', 'at8-moonlight-user-center' ),
				),
				'buy_order_failed'        => array(
					't' => __( 'Order failed', 'at8-moonlight-user-center' ),
					'd' => __( 'Failed to create the order. Please try again later.', 'at8-moonlight-user-center' ),
				),
				'buy_order_manual'        => array(
					't' => __( 'Order created (offline)', 'at8-moonlight-user-center' ),
					'd' => __( 'Order created. Please complete the transfer as instructed. Your membership will be activated once the administrator confirms your payment.', 'at8-moonlight-user-center' ),
				),
				'buy_no_gateway'          => array(
					't' => __( 'No gateway available', 'at8-moonlight-user-center' ),
					'd' => __( 'No payment method is available. Please contact the administrator.', 'at8-moonlight-user-center' ),
				),
			),
		);
	}

	/**
	 * Render the interface text editor.
	 *
	 * @return void
	 */
	public function render_ui_labels_field() {
		$saved = at8_mluc_get_option( 'ui_labels', array() );
		$saved = is_array( $saved ) ? $saved : array();

		foreach ( $this->label_fields() as $group => $fields ) {
			printf( '<h3>%s</h3>', esc_html( $this->label_group_title( $group ) ) );

			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( $fields as $key => $field ) {
				$value = isset( $saved[ $key ] ) ? (string) $saved[ $key ] : '';
				$id    = 'at8_mluc_label_' . $key;
				$name  = self::OPTION . '[ui_labels][' . $key . ']';

				printf(
					'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="%1$s" name="%3$s" value="%4$s" placeholder="%5$s"></td></tr>',
					esc_attr( $id ),
					esc_html( $field['t'] ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( $field['d'] )
				);
			}

			echo '</tbody></table>';
		}
	}

	/**
	 * Human-readable title of a label group.
	 *
	 * @param string $group Group key.
	 * @return string
	 */
	private function label_group_title( $group ) {
		$titles = array(
			'sidebar'    => __( 'Sidebar & headings', 'at8-moonlight-user-center' ),
			'membership' => __( 'Membership tab', 'at8-moonlight-user-center' ),
			'purchase'   => __( 'Purchase flow', 'at8-moonlight-user-center' ),
		);

		return isset( $titles[ $group ] ) ? $titles[ $group ] : $group;
	}

	/**
	 * Render the OAuth credentials table.
	 *
	 * @return void
	 */
	public function render_oauth_field() {
		if ( ! class_exists( 'AT8_MLUC_OAuth' ) ) {
			echo '<p class="description">' . esc_html__( 'The social login module is not loaded.', 'at8-moonlight-user-center' ) . '</p>';
			return;
		}

		$providers = AT8_MLUC_OAuth::providers();
		$saved     = at8_mluc_get_option( 'oauth', array() );
		$saved     = is_array( $saved ) ? $saved : array();
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: example callback URL */
				esc_html__( 'Register the callback URL (example: %s) on the provider platform. Only providers with both a client ID and a secret appear as front-end login buttons.', 'at8-moonlight-user-center' ),
				'<code>' . esc_html( home_url( '/?at8_mluc_oauth=wechat' ) ) . '</code>'
			);
			?>
		</p>
		<table class="at8-mluc-oauth-table widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Provider', 'at8-moonlight-user-center' ); ?></th>
					<th><?php esc_html_e( 'Client ID / App ID', 'at8-moonlight-user-center' ); ?></th>
					<th><?php esc_html_e( 'Client Secret', 'at8-moonlight-user-center' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $providers as $id => $provider ) : ?>
					<?php
					$credentials = isset( $saved[ $id ] ) && is_array( $saved[ $id ] ) ? $saved[ $id ] : array();
					$client_id   = isset( $credentials['client_id'] ) ? $credentials['client_id'] : '';
					$secret      = isset( $credentials['client_secret'] ) ? $credentials['client_secret'] : '';
					?>
					<tr>
						<td><strong><?php echo esc_html( $provider['label'] ); ?></strong></td>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[oauth][<?php echo esc_attr( $id ); ?>][client_id]" value="<?php echo esc_attr( $client_id ); ?>"></td>
						<td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[oauth][<?php echo esc_attr( $id ); ?>][client_secret]" value="<?php echo esc_attr( $secret ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the avatar library entry.
	 *
	 * @return void
	 */
	public function render_avatar_library_field() {
		if ( ! class_exists( 'AT8_MLUC_Avatar' ) ) {
			echo '<p class="description">' . esc_html__( 'The avatar library module is not loaded.', 'at8-moonlight-user-center' ) . '</p>';
			return;
		}

		$avatars    = AT8_MLUC_Avatar::get_library();
		$manage_url = admin_url( 'edit.php?post_type=' . AT8_MLUC_Avatar::POST_TYPE );
		$count      = is_array( $avatars ) ? count( $avatars ) : 0;
		$preview    = is_array( $avatars ) ? array_slice( $avatars, 0, 8 ) : array();
		?>
		<div class="at8-mluc-avatar-library-box">
			<p class="description" style="margin-top:0;">
				<?php
				printf(
					/* translators: %d: number of avatars in the library. */
					esc_html__( 'The library holds %d selectable avatars. Members pick one of them on the Profile tab of their account dashboard.', 'at8-moonlight-user-center' ),
					(int) $count
				);
				?>
			</p>
			<?php if ( $preview ) : ?>
				<ul class="at8-mluc-avatar-library-preview">
					<?php foreach ( $preview as $avatar ) : ?>
						<li><img src="<?php echo esc_url( $avatar['url'] ); ?>" alt="<?php echo esc_attr( $avatar['title'] ); ?>"></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'The library is empty. Use the button below to upload avatars.', 'at8-moonlight-user-center' ); ?></p>
			<?php endif; ?>
			<p>
				<a href="<?php echo esc_url( $manage_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Manage the avatar library →', 'at8-moonlight-user-center' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( AT8_MLUC_Security::manage_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'at8-moonlight-user-center' ), '', array( 'response' => 403 ) );
		}
		?>
		<div class="wrap at8-mluc-settings-wrap">
			<header class="at8-mluc-hero">
				<span class="dashicons dashicons-buddicons-buddypress-logo at8-mluc-hero-logo" aria-hidden="true"></span>

				<div class="at8-mluc-hero-text">
					<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
					<p class="at8-mluc-hero-tagline">
						<?php esc_html_e( 'Front-end accounts, membership levels and payments for WordPress.', 'at8-moonlight-user-center' ); ?>
					</p>
				</div>

				<div class="at8-mluc-hero-meta">
					<span class="at8-mluc-chip"><?php echo esc_html( 'v' . AT8_MLUC_VERSION ); ?></span>
					<?php if ( at8_mluc_is_pro() ) : ?>
						<span class="at8-mluc-chip at8-mluc-chip-pro"><?php esc_html_e( 'Pro active', 'at8-moonlight-user-center' ); ?></span>
					<?php else : ?>
						<span class="at8-mluc-chip at8-mluc-chip-free"><?php esc_html_e( 'Free', 'at8-moonlight-user-center' ); ?></span>
					<?php endif; ?>
				</div>
			</header>

			<?php AT8_MLUC_Upgrade::render_banner(); ?>

			<details class="at8-mluc-shortcode-ref">
				<summary>
					<span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
					<?php esc_html_e( 'Shortcode reference', 'at8-moonlight-user-center' ); ?>
				</summary>

				<div class="at8-mluc-shortcode-grid">
					<?php foreach ( $this->shortcode_reference() as $row ) : ?>
						<div class="at8-mluc-shortcode-item">
							<code><?php echo esc_html( $row['code'] ); ?></code>
							<span><?php echo esc_html( $row['label'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</details>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );

				/**
				 * Fires after the free plugin printed its settings nonce fields.
				 *
				 * Add-ons with their own option group print their nonce fields
				 * here so a single form submission saves both option blobs.
				 *
				 * @since 2.2.0
				 */
				do_action( 'at8_mluc_settings_extra_fields' );

				do_settings_sections( self::PAGE );

				echo '<div class="at8-mluc-save-bar">';
				submit_button( esc_html__( 'Save changes', 'at8-moonlight-user-center' ), 'primary', 'submit', false );
				echo '<span class="at8-mluc-save-hint">' . esc_html__( 'Need a hand? Every setting below falls back to a sensible default.', 'at8-moonlight-user-center' ) . '</span>';
				echo '</div>';
				?>
			</form>

			<?php
			/*
			 * Field definitions live in their own option and save through their
			 * own action, so the editor sits outside the settings form: nesting
			 * forms is invalid HTML and would swallow the submit button.
			 */
			AT8_MLUC_Fields::render_editor();
			?>
		</div>
		<?php
	}

	/**
	 * Shortcode cheat sheet shown above the settings form.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function shortcode_reference() {
		$rows = array(
			array(
				'code'  => '[at8_mluc_login]',
				'label' => __( 'Login form', 'at8-moonlight-user-center' ),
			),
			array(
				'code'  => '[at8_mluc_register]',
				'label' => __( 'Registration form', 'at8-moonlight-user-center' ),
			),
			array(
				'code'  => '[at8_mluc_lostpassword]',
				'label' => __( 'Password recovery form', 'at8-moonlight-user-center' ),
			),
			array(
				'code'  => '[at8_mluc_account]',
				'label' => __( 'Account dashboard', 'at8-moonlight-user-center' ),
			),
			array(
				'code'  => '[at8_mluc_hide type="logged"]',
				'label' => __( 'Hidden content until login', 'at8-moonlight-user-center' ),
			),
			array(
				'code'  => '[at8_mluc_hide type="reply"]',
				'label' => __( 'Hidden content until comment', 'at8-moonlight-user-center' ),
			),
			array(
				'code'  => '[at8_mluc_oauth_buttons]',
				'label' => __( 'Social login buttons', 'at8-moonlight-user-center' ),
			),
		);

		/**
		 * Filter the shortcode cheat sheet.
		 *
		 * The Pro add-on appends its own shortcodes here so every snippet the
		 * family supports lives in one place.
		 *
		 * @since 2.3.0
		 *
		 * @param array $rows Rows of code / label pairs.
		 */
		return (array) apply_filters( 'at8_mluc_shortcode_reference', $rows );
	}

	/**
	 * Show the transient admin notice set by order actions.
	 *
	 * @return void
	 */
	public function render_admin_notice() {
		$notice = get_transient( 'at8_mluc_admin_notice_' . get_current_user_id() );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			! empty( $notice['success'] ) ? 'success' : 'error',
			esc_html( $notice['message'] )
		);

		delete_transient( 'at8_mluc_admin_notice_' . get_current_user_id() );
	}

	/**
	 * Sanitise the submitted settings.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ) {
		$defaults = AT8_MLUC_Activator::defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		foreach ( array( 'account_page_id', 'login_page_id', 'register_page_id', 'lostpassword_page_id' ) as $key ) {
			$page_id = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;

			// Only accept a page that exists and is publicly viewable.
			if ( $page_id && 'page' !== get_post_type( $page_id ) ) {
				$page_id = 0;
			}

			$clean[ $key ] = $page_id;
		}

		foreach ( array( 'enable_registration', 'auto_login', 'enable_avatar', 'free_level_enabled', 'manual_enabled', 'terms_enabled' ) as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		// Terms page: same existence check as the other page pickers.
		$terms_page = isset( $input['terms_page_id'] ) ? absint( $input['terms_page_id'] ) : 0;
		if ( $terms_page && 'page' !== get_post_type( $terms_page ) ) {
			$terms_page = 0;
		}
		$clean['terms_page_id'] = $terms_page;

		$terms_text = isset( $input['terms_text'] ) ? sanitize_text_field( (string) $input['terms_text'] ) : '';
		$clean['terms_text'] = mb_substr( $terms_text, 0, 200 );

		// The unit name is a display label, so it may be any language.
		$clean['points_label'] = isset( $input['points_label'] )
			? mb_substr( sanitize_text_field( (string) $input['points_label'] ), 0, 32 )
			: 'points';
		$clean['points_label'] = '' === trim( $clean['points_label'] ) ? 'points' : trim( $clean['points_label'] );

		$clean['points_per_currency'] = isset( $input['points_per_currency'] )
			? max( 0, (float) $input['points_per_currency'] )
			: 0;
		$clean['points_signup_bonus'] = isset( $input['points_signup_bonus'] )
			? max( 0, (int) $input['points_signup_bonus'] )
			: 0;

		$clean['accent_color'] = isset( $input['accent_color'] ) ? sanitize_hex_color( trim( (string) $input['accent_color'] ) ) : '';
		$clean['accent_color'] = (string) $clean['accent_color'];

		$redirect = isset( $input['redirect_after_login'] ) ? esc_url_raw( trim( (string) $input['redirect_after_login'] ) ) : '';

		if ( '' !== $redirect && ! wp_http_validate_url( $redirect ) ) {
			$redirect = '';
			add_settings_error(
				self::OPTION,
				'at8_mluc_redirect_invalid',
				__( 'The redirect address was not a valid URL, so it was cleared.', 'at8-moonlight-user-center' ),
				'warning'
			);
		}

		$clean['redirect_after_login'] = $redirect;

		$clean['pay_currency_symbol']       = isset( $input['pay_currency_symbol'] ) ? sanitize_text_field( wp_unslash( $input['pay_currency_symbol'] ) ) : '';
		$clean['pay_manual_instructions']   = isset( $input['pay_manual_instructions'] ) ? sanitize_textarea_field( wp_unslash( $input['pay_manual_instructions'] ) ) : '';
		$clean['order_auto_close_hours']    = isset( $input['order_auto_close_hours'] ) ? max( 0, min( 8760, (int) $input['order_auto_close_hours'] ) ) : 72;

		$currency_code = strtoupper( sanitize_text_field( wp_unslash( $input['pay_currency_code'] ?? '' ) ) );
		$clean['pay_currency_code'] = preg_match( '/^[A-Z]{3}$/', $currency_code ) ? $currency_code : 'USD';

		$clean['oauth'] = array();
		if ( isset( $input['oauth'] ) && is_array( $input['oauth'] ) ) {
			foreach ( $input['oauth'] as $provider => $credentials ) {
				$provider = sanitize_key( $provider );

				if ( '' === $provider || ! is_array( $credentials ) ) {
					continue;
				}

				$client_id = isset( $credentials['client_id'] ) ? sanitize_text_field( wp_unslash( $credentials['client_id'] ) ) : '';
				$secret    = isset( $credentials['client_secret'] ) ? sanitize_text_field( wp_unslash( $credentials['client_secret'] ) ) : '';

				if ( '' !== $client_id || '' !== $secret ) {
					$clean['oauth'][ $provider ] = array(
						'client_id'     => $client_id,
						'client_secret' => $secret,
					);
				}
			}
		}

		$clean['ui_labels'] = array();
		if ( isset( $input['ui_labels'] ) && is_array( $input['ui_labels'] ) ) {
			foreach ( $input['ui_labels'] as $key => $value ) {
				$key = sanitize_key( $key );

				if ( '' !== $key ) {
					$clean['ui_labels'][ $key ] = sanitize_text_field( wp_unslash( $value ) );
				}
			}
		}

		$clean['nav_icons'] = $this->sanitize_nav_icons( isset( $input['nav_icons'] ) ? $input['nav_icons'] : array() );

		$clean['membership_levels'] = $this->sanitize_levels( isset( $input['membership_levels'] ) ? $input['membership_levels'] : array() );

		// Deleting the base level disables the Basic member flag, otherwise
		// get_levels() would add the base level straight back.
		if ( ! isset( $clean['membership_levels']['free'] ) ) {
			$clean['free_level_enabled'] = 0;
		}

		// Anything not handled above is dropped rather than stored unvalidated.
		return array_merge( $defaults, $clean );
	}

	/**
	 * Sanitise the sidebar icon definitions.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, array<string, string>>
	 */
	private function sanitize_nav_icons( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$clean = array();

		foreach ( $input as $key => $value ) {
			$key = sanitize_key( $key );

			if ( '' === $key || ! is_array( $value ) ) {
				continue;
			}

			$type = isset( $value['type'] ) && 'image' === $value['type'] ? 'image' : 'dashicon';

			if ( 'image' === $type ) {
				$attachment_id = isset( $value['value'] ) ? absint( $value['value'] ) : 0;

				if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
					$clean[ $key ] = array(
						'type'  => 'image',
						'value' => (string) $attachment_id,
					);
				} else {
					$clean[ $key ] = array(
						'type'  => 'dashicon',
						'value' => '',
					);
				}
			} else {
				$class = isset( $value['value'] ) ? sanitize_text_field( wp_unslash( $value['value'] ) ) : '';

				$clean[ $key ] = array(
					'type'  => 'dashicon',
					'value' => ( 0 === strpos( $class, 'dashicons-' ) ) ? $class : '',
				);
			}
		}

		return $clean;
	}

	/**
	 * Sanitise the membership level definitions, enforcing the level limit.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, array<string, mixed>>
	 */
	private function sanitize_levels( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$levels = array();

		foreach ( $input as $raw_key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			// No-JS fallback: rows whose delete box was ticked are skipped.
			if ( ! empty( $value['delete'] ) && 0 !== strpos( (string) $raw_key, '__new__' ) ) {
				continue;
			}

			$is_new = ( 0 === strpos( (string) $raw_key, '__new__' ) );

			if ( $is_new ) {
				$desired = isset( $value['key'] ) ? sanitize_key( $value['key'] ) : '';

				if ( '' === $desired && isset( $value['label'] ) ) {
					$desired = sanitize_key( $value['label'] );
				}

				if ( '' === $desired || 'free' === $desired ) {
					continue;
				}

				$key = self::unique_level_key( $desired, $levels );
			} else {
				$key = sanitize_key( $raw_key );
			}

			if ( 'free' === $key ) {
				// The base level is a system level: always permanent and free.
				$levels['free'] = array(
					'label'      => isset( $value['label'] ) ? sanitize_text_field( wp_unslash( $value['label'] ) ) : 'Basic',
					'color'      => isset( $value['color'] ) ? sanitize_hex_color( wp_unslash( $value['color'] ) ) : '#95a5a6',
					'price'      => 0,
					'validity'   => 0,
					'sort_order' => 10,
				);

				continue;
			}

			$validity = isset( $value['validity'] ) ? (int) $value['validity'] : 0;

			$levels[ $key ] = array(
				'label'      => isset( $value['label'] ) && '' !== (string) $value['label'] ? sanitize_text_field( wp_unslash( $value['label'] ) ) : $key,
				'color'      => isset( $value['color'] ) ? sanitize_hex_color( wp_unslash( $value['color'] ) ) : '#2f6fed',
				'price'      => isset( $value['price'] ) ? (float) $value['price'] : 0,
				'points'     => isset( $value['points'] ) ? max( 0, (int) $value['points'] ) : 0,
				'validity'   => $validity,
				'sort_order' => isset( $value['sort_order'] ) ? (int) $value['sort_order'] : 50,
			);
		}

		return $levels;
	}

	/**
	 * Generate a level key that does not collide with an existing one.
	 *
	 * @param string $desired  Desired key.
	 * @param array  $existing Already accepted levels.
	 * @return string
	 */
	private static function unique_level_key( $desired, $existing ) {
		if ( ! isset( $existing[ $desired ] ) ) {
			return $desired;
		}

		$suffix = 2;

		while ( isset( $existing[ $desired . '_' . $suffix ] ) ) {
			++$suffix;
		}

		return $desired . '_' . $suffix;
	}
}
