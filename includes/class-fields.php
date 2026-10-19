<?php
/**
 * Custom registration and profile fields.
 *
 * The free plugin ships the whole feature: define as many extra fields as the
 * registration form and the account profile form need, and they are stored as
 * plain user meta. Nothing here is gated behind a paid add-on.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Configurable user fields.
 */
class AT8_MLUC_Fields {

	/**
	 * Option key holding the field definitions.
	 *
	 * @var string
	 */
	const OPTION = 'at8_mluc_custom_fields';

	/**
	 * User meta prefix.
	 *
	 * @var string
	 */
	const META_PREFIX = 'at8_mluc_field_';

	/**
	 * Supported control types.
	 *
	 * @var array<string, string>
	 */
	const TYPES = array(
		'text'     => 'text',
		'textarea' => 'textarea',
		'number'   => 'number',
		'date'     => 'date',
		'url'      => 'url',
		'email'    => 'email',
		'select'   => 'select',
		'radio'    => 'radio',
		'checkbox' => 'checkbox',
	);

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Fields|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Fields
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
		add_action( 'at8_mluc_after_register', array( $this, 'save_after_register' ), 10, 1 );
		add_action( 'at8_mluc_after_profile_update', array( $this, 'save_after_profile' ), 10, 2 );
		add_action( 'at8_mluc_account_profile_fields', array( $this, 'render_profile_fields' ), 10, 1 );
		add_action( 'at8_mluc_register_form_fields', array( $this, 'render_register_fields' ), 10, 0 );
		add_action( 'wp_ajax_at8_mluc_save_fields', array( $this, 'ajax_save' ) );
		add_action( 'show_user_profile', array( $this, 'render_admin_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_admin_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_admin_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_admin_fields' ) );
	}

	/**
	 * Field definitions, normalised and safe to render.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all() {
		$raw = get_option( self::OPTION, array() );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$fields = array();

		foreach ( $raw as $entry ) {
			$field = self::normalize( $entry );

			if ( null !== $field ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Normalise one raw definition.
	 *
	 * @param mixed $entry Raw value.
	 * @return array<string, mixed>|null
	 */
	private static function normalize( $entry ) {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$id = isset( $entry['id'] ) ? sanitize_key( (string) $entry['id'] ) : '';

		if ( '' === $id ) {
			return null;
		}

		$type = isset( $entry['type'] ) && isset( self::TYPES[ $entry['type'] ] ) ? $entry['type'] : 'text';

		$options = array();
		if ( ! empty( $entry['options'] ) && is_string( $entry['options'] ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $entry['options'] ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$options[] = $line;
				}
			}
		}

		return array(
			'id'          => $id,
			'label'       => isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : $id,
			'type'        => $type,
			'required'    => empty( $entry['required'] ) ? 0 : 1,
			'on_register' => empty( $entry['on_register'] ) ? 0 : 1,
			'on_profile'  => empty( $entry['on_profile'] ) ? 0 : 1,
			'options'     => $options,
			'placeholder' => isset( $entry['placeholder'] ) ? sanitize_text_field( (string) $entry['placeholder'] ) : '',
			'description' => isset( $entry['description'] ) ? sanitize_text_field( (string) $entry['description'] ) : '',
		);
	}

	/**
	 * Fields shown on the registration form.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_register() {
		return self::filtered( 'on_register' );
	}

	/**
	 * Fields shown on the profile form.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_profile() {
		return self::filtered( 'on_profile' );
	}

	/**
	 * Filter definitions by a visibility flag.
	 *
	 * @param string $flag Flag key.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filtered( $flag ) {
		$out = array();

		foreach ( self::all() as $field ) {
			if ( ! empty( $field[ $flag ] ) ) {
				$out[] = $field;
			}
		}

		return $out;
	}

	/**
	 * User meta key for a field id.
	 *
	 * @param string $id Field id.
	 * @return string
	 */
	public static function meta_key( $id ) {
		return self::META_PREFIX . $id;
	}

	/**
	 * Sanitise a submitted value for one field.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_value( $field, $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		switch ( $field['type'] ) {
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'number':
				return '' === trim( $value ) ? '' : (string) (float) $value;
			case 'url':
				return '' === $value ? '' : esc_url_raw( $value );
			case 'email':
				return sanitize_email( $value );
			case 'checkbox':
				return '1';
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Whether a submitted value satisfies the field rules.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Submitted value.
	 * @return true|WP_Error
	 */
	public static function validate( $field, $value ) {
		$checked = in_array( (string) $value, array( '1', 'on', 'yes', 'true' ), true );

		if ( 'checkbox' === $field['type'] ) {
			if ( $checked || empty( $field['required'] ) ) {
				return true;
			}

			return new WP_Error( 'at8_mluc_field_required', $field['label'] );
		}

		if ( ! empty( $field['required'] ) && '' === trim( (string) $value ) ) {
			return new WP_Error( 'at8_mluc_field_required', $field['label'] );
		}

		if ( 'email' === $field['type'] && '' !== trim( (string) $value ) && ! is_email( (string) $value ) ) {
			return new WP_Error( 'at8_mluc_field_invalid', $field['label'] );
		}

		return true;
	}

	/**
	 * Read one stored value.
	 *
	 * @param int    $user_id User ID.
	 * @param string $id      Field id.
	 * @return string
	 */
	public static function get_value( $user_id, $id ) {
		return (string) get_user_meta( $user_id, self::meta_key( $id ), true );
	}

	/**
	 * Persist one field value.
	 *
	 * @param int    $user_id User ID.
	 * @param array  $field   Field definition.
	 * @param string $value   Sanitised value.
	 * @return void
	 */
	public static function set_value( $user_id, $field, $value ) {
		if ( 'checkbox' === $field['type'] && '' === $value ) {
			delete_user_meta( $user_id, self::meta_key( $field['id'] ) );

			return;
		}

		if ( '' === $value ) {
			delete_user_meta( $user_id, self::meta_key( $field['id'] ) );

			return;
		}

		update_user_meta( $user_id, self::meta_key( $field['id'] ), $value );
	}

	/**
	 * First validation failure for a set of fields, as a user-facing message.
	 *
	 * @param array $fields Field definitions.
	 * @param array $source Superglobal to read from.
	 * @return string|null Message, or null when everything is valid.
	 */
	public static function first_request_error( $fields, $source ) {
		foreach ( $fields as $field ) {
			$key  = self::META_PREFIX . $field['id'];
			$raw  = isset( $source[ $key ] ) ? $source[ $key ] : '';
			$type = isset( $field['type'] ) ? $field['type'] : 'text';

			if ( 'checkbox' === $type && empty( $field['required'] ) ) {
				continue;
			}

			$check = self::validate( $field, $raw );

			if ( is_wp_error( $check ) ) {
				if ( 'at8_mluc_field_required' === $check->get_error_code() ) {
					return sprintf(
						/* translators: %s: field label. */
						__( 'The field "%s" is required.', 'at8-moonlight-user-center' ),
						$field['label']
					);
				}

				return sprintf(
					/* translators: %s: field label. */
					__( 'The value entered for "%s" is not valid.', 'at8-moonlight-user-center' ),
					$field['label']
				);
			}
		}

		return null;
	}

	/**
	 * Save submitted values for a list of fields.
	 *
	 * @param int   $user_id User ID.
	 * @param array $fields  Field definitions.
	 * @param array $source  Superglobal to read from.
	 * @return true|WP_Error
	 */
	public static function save_from_request( $user_id, $fields, $source ) {
		foreach ( $fields as $field ) {
			$key = self::META_PREFIX . $field['id'];
			$raw = isset( $source[ $key ] ) ? $source[ $key ] : '';

			if ( 'checkbox' === $field['type'] ) {
				$raw = isset( $source[ $key ] ) ? '1' : '';
			}

			$check = self::validate( $field, $raw );

			if ( is_wp_error( $check ) ) {
				return $check;
			}

			self::set_value( $user_id, $field, self::sanitize_value( $field, $raw ) );
		}

		return true;
	}

	/**
	 * Render the control for one field.
	 *
	 * @param array  $field     Field definition.
	 * @param string $value     Current value.
	 * @param string $name_attr Name attribute suffix.
	 * @return void
	 */
	public static function render_control( $field, $value, $name_attr ) {
		$name  = self::META_PREFIX . $field['id'];
		$id    = 'at8-mluc-field-' . $field['id'] . '-' . $name_attr;
		$req   = ! empty( $field['required'] ) ? ' required' : '';
		$ph    = '' !== $field['placeholder'] ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '';
		$descr = '' !== $field['description'] ? '<span class="at8-mluc-field-hint">' . esc_html( $field['description'] ) . '</span>' : '';
		?>
		<p class="at8-mluc-field">
			<label for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $field['label'] ); ?>
				<?php if ( ! empty( $field['required'] ) ) : ?>
					<span class="at8-mluc-req" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<?php
			switch ( $field['type'] ) {
				case 'textarea':
					?>
					<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="4"<?php echo esc_attr( $req ); ?><?php echo $ph; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>><?php echo esc_textarea( $value ); ?></textarea>
					<?php
					break;
				case 'select':
					?>
					<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"<?php echo esc_attr( $req ); ?>>
						<option value=""><?php esc_html_e( '— Select —', 'at8-moonlight-user-center' ); ?></option>
						<?php foreach ( $field['options'] as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $value, $option ); ?>>
								<?php echo esc_html( $option ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php
					break;
				case 'radio':
					foreach ( $field['options'] as $index => $option ) :
						?>
						<span class="at8-mluc-choice">
							<input type="radio" id="<?php echo esc_attr( $id . '-' . $index ); ?>"
								name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $option ); ?>"
								<?php checked( $value, $option ); ?> />
							<label for="<?php echo esc_attr( $id . '-' . $index ); ?>"><?php echo esc_html( $option ); ?></label>
						</span>
						<?php
					endforeach;
					break;
				case 'checkbox':
					?>
					<span class="at8-mluc-choice">
						<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
							value="1" <?php checked( '1', $value ); ?><?php echo esc_attr( $req ); ?> />
						<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( '' !== $field['description'] ? $field['description'] : $field['label'] ); ?></label>
					</span>
					<?php
					$descr = '';
					break;
				default:
					$type = 'number' === $field['type'] ? 'number' : ( 'date' === $field['type'] ? 'date' : ( 'url' === $field['type'] ? 'url' : ( 'email' === $field['type'] ? 'email' : 'text' ) ) );
					?>
					<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"<?php echo esc_attr( $req ); ?><?php echo $ph; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?> />
					<?php
			}
			echo wp_kses_post( $descr );
			?>
		</p>
		<?php
	}

	/**
	 * Render the extra fields on the registration form.
	 *
	 * @return void
	 */
	public function render_register_fields() {
		$fields = self::for_register();

		if ( empty( $fields ) ) {
			return;
		}

		echo '<fieldset class="at8-mluc-fields">';

		foreach ( $fields as $field ) {
			self::render_control( $field, '', 'reg' );
		}

		echo '</fieldset>';
	}

	/**
	 * Render the extra fields on the profile form.
	 *
	 * @param WP_User $user Current user.
	 * @return void
	 */
	public function render_profile_fields( $user ) {
		$fields = self::for_profile();

		if ( empty( $fields ) || ! $user instanceof WP_User ) {
			return;
		}

		echo '<fieldset class="at8-mluc-fields">';

		foreach ( $fields as $field ) {
			self::render_control( $field, self::get_value( $user->ID, $field['id'] ), 'profile' );
		}

		echo '</fieldset>';
	}

	/**
	 * Persist the definitions submitted by the settings editor.
	 *
	 * @return void
	 */
	public function ajax_save() {
		if ( ! check_ajax_referer( 'at8_mluc_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'The link has expired. Please refresh the page.', 'at8-moonlight-user-center' ) ) );
		}

		if ( ! current_user_can( AT8_MLUC_Security::manage_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'at8-moonlight-user-center' ) ) );
		}

		$raw = isset( $_POST['fields'] ) ? (array) wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each entry is normalised below.

		$clean = array();
		$ids   = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$field = self::normalize( $entry );

			if ( null === $field ) {
				continue;
			}

			if ( in_array( $field['id'], $ids, true ) ) {
				continue;
			}

			$ids[]            = $field['id'];
			$clean[]          = array(
				'id'          => $field['id'],
				'label'       => $field['label'],
				'type'        => $field['type'],
				'required'    => $field['required'],
				'on_register' => $field['on_register'],
				'on_profile'  => $field['on_profile'],
				'options'     => implode( "\n", $field['options'] ),
				'placeholder' => $field['placeholder'],
				'description' => $field['description'],
			);
		}

		if ( count( $clean ) > 30 ) {
			wp_send_json_error( array( 'message' => __( 'Too many fields. Keep it under 30 so the forms stay usable.', 'at8-moonlight-user-center' ) ) );
		}

		update_option( self::OPTION, $clean );

		wp_send_json_success( array( 'count' => count( $clean ) ) );
	}

	/**
	 * Render the field editor card for the settings screen.
	 *
	 * Saved through AJAX rather than the settings form: the definitions live in
	 * their own option, so a second submit button on the shared form would
	 * only confuse people.
	 *
	 * @return void
	 */
	public static function render_editor() {
		if ( ! current_user_can( AT8_MLUC_Security::manage_capability() ) ) {
			return;
		}

		$fields = self::all();
		$types  = array(
			'text'     => __( 'Text', 'at8-moonlight-user-center' ),
			'textarea' => __( 'Multi-line text', 'at8-moonlight-user-center' ),
			'number'   => __( 'Number', 'at8-moonlight-user-center' ),
			'date'     => __( 'Date', 'at8-moonlight-user-center' ),
			'url'      => __( 'Website', 'at8-moonlight-user-center' ),
			'email'    => __( 'Email', 'at8-moonlight-user-center' ),
			'select'   => __( 'Dropdown', 'at8-moonlight-user-center' ),
			'radio'    => __( 'Radio group', 'at8-moonlight-user-center' ),
			'checkbox' => __( 'Single checkbox', 'at8-moonlight-user-center' ),
		);
		?>
		<div class="at8-mluc-fields-editor" id="at8-mluc-fields-editor"
			data-at8-mluc-fields-editor="1"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'at8_mluc_nonce' ) ); ?>">

			<h3>
				<span class="dashicons dashicons-feedback" aria-hidden="true"></span>
				<?php esc_html_e( 'Registration &amp; Profile Fields', 'at8-moonlight-user-center' ); ?>
			</h3>
			<p class="description">
				<?php esc_html_e( 'Extra fields you add here appear on the front-end registration and profile forms, and are editable by administrators on the user profile screen.', 'at8-moonlight-user-center' ); ?>
			</p>

			<div class="at8-mluc-fields-rows">
				<?php foreach ( $fields as $index => $field ) : ?>
					<?php self::render_editor_row( $field, $index, $types ); ?>
				<?php endforeach; ?>
			</div>

			<p class="at8-mluc-fields-actions">
				<button type="button" class="button" data-at8-mluc-fields-add="1">
					<?php esc_html_e( 'Add field', 'at8-moonlight-user-center' ); ?>
				</button>
				<button type="button" class="button button-primary" data-at8-mluc-fields-save="1">
					<?php esc_html_e( 'Save fields', 'at8-moonlight-user-center' ); ?>
				</button>
				<span class="at8-mluc-fields-status" role="status" aria-live="polite"></span>
			</p>

			<template data-at8-mluc-fields-template="1">
				<?php
				self::render_editor_row(
					array(
						'id'          => '',
						'label'       => '',
						'type'        => 'text',
						'required'    => 0,
						'on_register' => 1,
						'on_profile'  => 1,
						'options'     => array(),
						'placeholder' => '',
						'description' => '',
					),
					'__index__',
					$types
				);
				?>
			</template>
		</div>
		<?php
	}

	/**
	 * Render one editor row.
	 *
	 * @param array $field Field definition.
	 * @param mixed $index Row index.
	 * @param array $types Type labels.
	 * @return void
	 */
	private static function render_editor_row( $field, $index, $types ) {
		?>
		<div class="at8-mluc-field-row" data-at8-mluc-field-row="<?php echo esc_attr( (string) $index ); ?>">
			<span class="at8-mluc-field-row-handle dashicons dashicons-menu" aria-hidden="true"></span>

			<label class="at8-mluc-field-row-cell at8-mluc-field-row-cell--label">
				<span><?php esc_html_e( 'Label', 'at8-moonlight-user-center' ); ?></span>
				<input type="text" data-field="label" value="<?php echo esc_attr( $field['label'] ); ?>" />
			</label>

			<label class="at8-mluc-field-row-cell at8-mluc-field-row-cell--id">
				<span><?php esc_html_e( 'Key', 'at8-moonlight-user-center' ); ?></span>
				<input type="text" data-field="id" value="<?php echo esc_attr( $field['id'] ); ?>" placeholder="company" />
			</label>

			<label class="at8-mluc-field-row-cell at8-mluc-field-row-cell--type">
				<span><?php esc_html_e( 'Type', 'at8-moonlight-user-center' ); ?></span>
				<select data-field="type">
					<?php foreach ( $types as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $field['type'], $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="at8-mluc-field-row-cell at8-mluc-field-row-cell--options">
				<span><?php esc_html_e( 'Choices (one per line)', 'at8-moonlight-user-center' ); ?></span>
				<textarea data-field="options" rows="2"><?php echo esc_textarea( implode( "\n", $field['options'] ) ); ?></textarea>
			</label>

			<span class="at8-mluc-field-row-flags">
				<label><input type="checkbox" data-field="required" value="1" <?php checked( ! empty( $field['required'] ) ); ?> /> <?php esc_html_e( 'Required', 'at8-moonlight-user-center' ); ?></label>
				<label><input type="checkbox" data-field="on_register" value="1" <?php checked( ! empty( $field['on_register'] ) ); ?> /> <?php esc_html_e( 'Registration', 'at8-moonlight-user-center' ); ?></label>
				<label><input type="checkbox" data-field="on_profile" value="1" <?php checked( ! empty( $field['on_profile'] ) ); ?> /> <?php esc_html_e( 'Profile', 'at8-moonlight-user-center' ); ?></label>
			</span>

			<button type="button" class="button-link at8-mluc-field-row-remove" data-at8-mluc-fields-remove="1">
				<span class="dashicons dashicons-trash" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Remove this field', 'at8-moonlight-user-center' ); ?></span>
			</button>
		</div>
		<?php
	}

	/**
	 * Save the registration values.
	 *
	 * @param int $user_id New user ID.
	 * @return void
	 */
	public function save_after_register( $user_id ) {
		self::save_from_request( $user_id, self::for_register(), $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- the registration handler already verified its own nonce.
	}

	/**
	 * Save the profile values.
	 *
	 * @param int   $user_id Updated user ID.
	 * @param array $data    Submitted profile data.
	 * @return void
	 */
	public function save_after_profile( $user_id, $data ) {
		self::save_from_request( $user_id, self::for_profile(), $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- the profile handler already verified its own nonce.
	}

	/**
	 * Render the admin fields on the user profile screen.
	 *
	 * @param WP_User $user User being edited.
	 * @return void
	 */
	public function render_admin_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$fields = self::all();

		if ( empty( $fields ) ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'User Center Fields', 'at8-moonlight-user-center' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( $fields as $field ) : ?>
				<tr>
					<th><label for="<?php echo esc_attr( 'at8-mluc-admin-field-' . $field['id'] ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
					<td>
						<?php
						$name = self::META_PREFIX . $field['id'];

						if ( 'textarea' === $field['type'] ) {
							printf(
								'<textarea id="%1$s" name="%2$s" rows="3" class="regular-text">%3$s</textarea>',
								esc_attr( 'at8-mluc-admin-field-' . $field['id'] ),
								esc_attr( $name ),
								esc_textarea( self::get_value( $user->ID, $field['id'] ) )
							);
						} elseif ( 'select' === $field['type'] || 'radio' === $field['type'] ) {
							printf( '<select id="%1$s" name="%2$s">', esc_attr( 'at8-mluc-admin-field-' . $field['id'] ), esc_attr( $name ) );
							echo '<option value="">' . esc_html__( '— None —', 'at8-moonlight-user-center' ) . '</option>';
							foreach ( $field['options'] as $option ) {
								printf(
									'<option value="%1$s"%2$s>%3$s</option>',
									esc_attr( $option ),
									selected( self::get_value( $user->ID, $field['id'] ), $option, false ),
									esc_html( $option )
								);
							}
							echo '</select>';
						} elseif ( 'checkbox' === $field['type'] ) {
							printf(
								'<label><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /> %4$s</label>',
								esc_attr( 'at8-mluc-admin-field-' . $field['id'] ),
								esc_attr( $name ),
								checked( '1', self::get_value( $user->ID, $field['id'] ), false ),
								esc_html( __( 'Yes', 'at8-moonlight-user-center' ) )
							);
						} else {
							printf(
								'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
								esc_attr( 'at8-mluc-admin-field-' . $field['id'] ),
								esc_attr( $name ),
								esc_attr( self::get_value( $user->ID, $field['id'] ) )
							);
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Save the admin fields.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function save_admin_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// Core verifies this nonce before firing the profile-save hooks; the
		// re-check is free and keeps the intent visible right where the input
		// is read.
		check_admin_referer( 'update-user_' . $user_id );

		$fields = self::all();

		foreach ( $fields as $field ) {
			$name = self::META_PREFIX . $field['id'];

			/*
			 * The sanitiser is applied right at the read, per control type, so
			 * no raw input ever travels through this method; sanitize_value()
			 * below normalises again (idempotent for every type).
			 */
			switch ( $field['type'] ) {
				case 'checkbox':
					$value = isset( $_POST[ $name ] ) ? '1' : '';
					break;
				case 'textarea':
					$value = isset( $_POST[ $name ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) ) : '';
					break;
				case 'url':
					$value = isset( $_POST[ $name ] ) ? esc_url_raw( wp_unslash( $_POST[ $name ] ) ) : '';
					break;
				case 'email':
					$value = isset( $_POST[ $name ] ) ? sanitize_email( wp_unslash( $_POST[ $name ] ) ) : '';
					break;
				default: // text, number, tel, select, radio — single-line values.
					$value = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
			}

			if ( 'checkbox' === $field['type'] && '' === $value ) {
				delete_user_meta( $user_id, self::meta_key( $field['id'] ) );
				continue;
			}

			self::set_value( $user_id, $field, self::sanitize_value( $field, $value ) );
		}
	}
}
