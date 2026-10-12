<?php
/**
 * Admin settings screen.
 *
 * Uses the WordPress Settings API, so nonce handling and the capability check
 * are performed by WordPress itself before `sanitize_callback` runs.
 *
 * @package Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen and option sanitisation.
 */
class MLUC_Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'mluc_options';

	/**
	 * Settings group.
	 *
	 * @var string
	 */
	const GROUP = 'mluc_settings';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE = 'mluc-settings';

	/**
	 * Singleton instance.
	 *
	 * @var MLUC_Settings|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return MLUC_Settings
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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . MLUC_PLUGIN_BASENAME, array( $this, 'add_action_link' ) );
	}

	/**
	 * Add the settings screen under the Settings menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Moonlight User Center', 'moonlight-user-center' ),
			__( 'User Center', 'moonlight-user-center' ),
			MLUC_Security::manage_capability(),
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add a settings shortcut on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_action_link( $links ) {
		if ( ! current_user_can( MLUC_Security::manage_capability() ) ) {
			return $links;
		}

		$url = add_query_arg( 'page', self::PAGE, admin_url( 'options-general.php' ) );

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'moonlight-user-center' ) )
		);

		return $links;
	}

	/**
	 * Register the option and its sections.
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
				'default'           => MLUC_Activator::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'mluc_pages',
			__( 'Pages', 'moonlight-user-center' ),
			array( $this, 'section_pages' ),
			self::PAGE
		);

		$this->add_field( 'account_page_id', __( 'Account page', 'moonlight-user-center' ), 'pages', 'page_select' );
		$this->add_field( 'login_page_id', __( 'Login page', 'moonlight-user-center' ), 'pages', 'page_select' );
		$this->add_field( 'register_page_id', __( 'Registration page', 'moonlight-user-center' ), 'pages', 'page_select' );
		$this->add_field( 'lostpassword_page_id', __( 'Password recovery page', 'moonlight-user-center' ), 'pages', 'page_select' );

		add_settings_section(
			'mluc_behaviour',
			__( 'Behaviour', 'moonlight-user-center' ),
			array( $this, 'section_behaviour' ),
			self::PAGE
		);

		$this->add_field( 'enable_registration', __( 'Front-end registration', 'moonlight-user-center' ), 'behaviour', 'checkbox' );
		$this->add_field( 'auto_login', __( 'Log in after registration', 'moonlight-user-center' ), 'behaviour', 'checkbox' );
		$this->add_field( 'enable_avatar', __( 'Avatar library', 'moonlight-user-center' ), 'behaviour', 'checkbox' );
		$this->add_field( 'redirect_after_login', __( 'Redirect after login', 'moonlight-user-center' ), 'behaviour', 'url' );

		add_settings_section(
			'mluc_uninstall',
			__( 'Uninstall', 'moonlight-user-center' ),
			array( $this, 'section_uninstall' ),
			self::PAGE
		);

		$this->add_field( 'delete_data', __( 'Delete data on uninstall', 'moonlight-user-center' ), 'uninstall', 'checkbox' );
	}

	/**
	 * Register a single field.
	 *
	 * @param string $key      Setting key.
	 * @param string $label    Field label.
	 * @param string $section  Section key.
	 * @param string $type     Control type.
	 * @return void
	 */
	private function add_field( $key, $label, $section, $type ) {
		add_settings_field(
			'mluc_field_' . $key,
			$label,
			array( $this, 'render_field' ),
			self::PAGE,
			'mluc_' . $section,
			array(
				'key'         => $key,
				'type'        => $type,
				'label_for'   => 'mluc_field_' . $key,
			)
		);
	}

	/**
	 * Pages section description.
	 *
	 * @return void
	 */
	public function section_pages() {
		echo '<p>' . esc_html__( 'Choose which pages hold the plugin shortcodes. Leave a page unset to fall back to the WordPress login screens.', 'moonlight-user-center' ) . '</p>';
	}

	/**
	 * Behaviour section description.
	 *
	 * @return void
	 */
	public function section_behaviour() {
		echo '<p>' . esc_html__( 'Control what visitors can do on the front end.', 'moonlight-user-center' ) . '</p>';
	}

	/**
	 * Uninstall section description.
	 *
	 * @return void
	 */
	public function section_uninstall() {
		echo '<p>' . esc_html__( 'Choose what happens to the data this plugin stored if it is deleted.', 'moonlight-user-center' ) . '</p>';
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
		$id    = 'mluc_field_' . $key;
		$name  = self::OPTION . '[' . $key . ']';
		$value = mluc_get_option( $key, '' );

		switch ( $type ) {
			case 'page_select':
				$pages = get_pages(
					array(
						'sort_column' => 'post_title',
						'number'      => 300,
					)
				);

				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				printf( '<option value="0">%s</option>', esc_html__( '— Not set —', 'moonlight-user-center' ) );

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
					esc_html__( 'Enabled', 'moonlight-user-center' )
				);
				break;

			case 'url':
				printf(
					'<input type="url" class="regular-text" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_url( (string) $value ),
					esc_attr( mluc_get_account_url() )
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
			echo '<p class="description">' . esc_html__( 'When enabled, deleting the plugin removes its settings and the profile fields it added. WordPress user accounts, posts and comments are never removed.', 'moonlight-user-center' ) . '</p>';
		}

		if ( 'enable_registration' === $key && ! get_option( 'users_can_register' ) ) {
			$settings_url = admin_url( 'options-general.php' );

			printf(
				'<p class="description">%s</p>',
				sprintf(
					/* translators: %s: link to the WordPress general settings screen. */
					esc_html__( 'Registration also requires the “Anyone can register” option in %s.', 'moonlight-user-center' ),
					'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'General Settings', 'moonlight-user-center' ) . '</a>'
				)
			);
		}

		if ( 'enable_avatar' === $key ) {
			$library_url = admin_url( 'edit.php?post_type=' . MLUC_Avatar::POST_TYPE );

			printf(
				'<p class="description">%s</p>',
				sprintf(
					/* translators: %s: link to the avatar library admin screen. */
					esc_html__( 'Manage the selectable avatars in the %s.', 'moonlight-user-center' ),
					'<a href="' . esc_url( $library_url ) . '">' . esc_html__( 'avatar library', 'moonlight-user-center' ) . '</a>'
				)
			);
		}
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( MLUC_Security::manage_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'moonlight-user-center' ), '', array( 'response' => 403 ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<p>
				<?php esc_html_e( 'Place these shortcodes in the pages you selected below:', 'moonlight-user-center' ); ?>
			</p>
			<table class="widefat striped" style="max-width:640px;margin-bottom:1.5em;">
				<tbody>
					<tr>
						<td><code>[mluc_login]</code></td>
						<td><?php esc_html_e( 'Login form', 'moonlight-user-center' ); ?></td>
					</tr>
					<tr>
						<td><code>[mluc_register]</code></td>
						<td><?php esc_html_e( 'Registration form', 'moonlight-user-center' ); ?></td>
					</tr>
					<tr>
						<td><code>[mluc_lostpassword]</code></td>
						<td><?php esc_html_e( 'Password recovery form', 'moonlight-user-center' ); ?></td>
					</tr>
					<tr>
						<td><code>[mluc_account]</code></td>
						<td><?php esc_html_e( 'Account dashboard', 'moonlight-user-center' ); ?></td>
					</tr>
				</tbody>
			</table>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sanitise the submitted settings.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ) {
		$defaults = MLUC_Activator::defaults();
		$current  = get_option( self::OPTION, array() );
		$current  = is_array( $current ) ? $current : array();
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

		foreach ( array( 'enable_registration', 'auto_login', 'enable_avatar', 'delete_data' ) as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$redirect = isset( $input['redirect_after_login'] ) ? esc_url_raw( trim( (string) $input['redirect_after_login'] ) ) : '';

		if ( '' !== $redirect && ! wp_http_validate_url( $redirect ) ) {
			$redirect = '';
			add_settings_error(
				self::OPTION,
				'mluc_redirect_invalid',
				__( 'The redirect address was not a valid URL, so it was cleared.', 'moonlight-user-center' ),
				'warning'
			);
		}

		$clean['redirect_after_login'] = $redirect;

		// Anything not handled above is dropped rather than stored unvalidated.
		return array_merge( $defaults, $clean );
	}
}
