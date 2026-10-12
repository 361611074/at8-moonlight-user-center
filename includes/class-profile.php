<?php
/**
 * Front-end profile editing.
 *
 * Only the fields WordPress already exposes on the user record are written.
 * The email address and the user role are deliberately not editable here: both
 * require confirmation through the WordPress admin, and exposing them on the
 * front end would be a privilege-escalation risk.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Profile update handling.
 */
class AT8_MLUC_Profile {

	/**
	 * Nonce action for the profile form.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'at8_mluc_update_profile';

	/**
	 * Maximum length accepted for the phone number.
	 *
	 * @var int
	 */
	const PHONE_MAX_LENGTH = 40;

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Profile|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Profile
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
		add_action( 'wp_ajax_at8_mluc_update_profile', array( $this, 'handle_update' ) );
	}

	/**
	 * Handle the AJAX profile update.
	 *
	 * @return void
	 */
	public function handle_update() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, AT8_MLUC_Security::NONCE_FIELD, false ) ) {
			at8_mluc_send_json( false, __( 'The security check failed. Please reload the page and try again.', 'at8-moonlight-user-center' ) );
		}
		AT8_MLUC_Security::require_login();

		if ( ! AT8_MLUC_Security::allow( 'profile' ) ) {
			AT8_MLUC_Security::reject_throttled();
		}

		$user_id = get_current_user_id();

		$display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
		$nickname     = isset( $_POST['nickname'] ) ? sanitize_text_field( wp_unslash( $_POST['nickname'] ) ) : '';
		$first_name   = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name    = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$description  = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$user_url     = isset( $_POST['user_url'] ) ? esc_url_raw( wp_unslash( $_POST['user_url'] ) ) : '';
		$phone        = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		$display_name = trim( $display_name );
		$nickname     = trim( $nickname );

		if ( '' === $display_name ) {
			at8_mluc_send_json( false, __( 'Please enter a display name.', 'at8-moonlight-user-center' ) );
		}

		// Mirror the validation WordPress core applies to comment author names.
		if ( strlen( $display_name ) > 100 ) {
			at8_mluc_send_json( false, __( 'That display name is too long.', 'at8-moonlight-user-center' ) );
		}

		if ( preg_match( '/[<>%\{\}\[\]]/', $display_name ) ) {
			at8_mluc_send_json( false, __( 'That display name contains characters that are not allowed.', 'at8-moonlight-user-center' ) );
		}

		if ( '' === $nickname ) {
			$nickname = $display_name;
		}

		if ( strlen( $nickname ) > 100 || preg_match( '/[<>%\{\}\[\]]/', $nickname ) ) {
			at8_mluc_send_json( false, __( 'That nickname contains characters that are not allowed.', 'at8-moonlight-user-center' ) );
		}

		if ( '' !== $user_url && ! wp_http_validate_url( $user_url ) ) {
			at8_mluc_send_json( false, __( 'That website address is not valid.', 'at8-moonlight-user-center' ) );
		}

		if ( '' !== $phone && strlen( $phone ) > self::PHONE_MAX_LENGTH ) {
			at8_mluc_send_json( false, __( 'That phone number is too long.', 'at8-moonlight-user-center' ) );
		}

		$result = wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $display_name,
				'nickname'     => $nickname,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'description'  => $description,
				'user_url'     => $user_url,
			)
		);

		if ( is_wp_error( $result ) ) {
			at8_mluc_send_json( false, at8_mluc_translate_wp_error( $result ) );
		}

		if ( '' === $phone ) {
			delete_user_meta( $user_id, 'at8_mluc_phone' );
		} else {
			update_user_meta( $user_id, 'at8_mluc_phone', $phone );
		}

		/**
		 * Fires after a user saved their front-end profile.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $user_id Updated user ID.
		 * @param array $data    Fields that were submitted.
		 */
		do_action(
			'at8_mluc_after_profile_update',
			$user_id,
			array(
				'display_name' => $display_name,
				'nickname'     => $nickname,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'description'  => $description,
				'user_url'     => $user_url,
				'phone'        => $phone,
			)
		);

		at8_mluc_send_json(
			true,
			__( 'Your profile has been updated.', 'at8-moonlight-user-center' ),
			array(
				'display_name' => $display_name,
				'avatar_url'   => get_avatar_url( $user_id, array( 'size' => 96 ) ),
			)
		);
	}
}
