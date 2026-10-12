<?php
/**
 * WordPress privacy tool integration.
 *
 * Registers a personal data exporter and eraser for the extra user meta this
 * plugin stores, and suggests privacy policy wording.
 *
 * @package Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Privacy exporter, eraser and policy suggestions.
 */
class MLUC_Privacy {

	/**
	 * Exporter identifier.
	 *
	 * @var string
	 */
	const EXPORTER_ID = 'moonlight-user-center';

	/**
	 * Eraser identifier.
	 *
	 * @var string
	 */
	const ERASER_ID = 'moonlight-user-center';

	/**
	 * Singleton instance.
	 *
	 * @var MLUC_Privacy|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return MLUC_Privacy
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
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * Register the personal data exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters[ self::EXPORTER_ID ] = array(
			'exporter_friendly_name' => __( 'Moonlight User Center', 'moonlight-user-center' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Register the personal data eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers[ self::ERASER_ID ] = array(
			'eraser_friendly_name' => __( 'Moonlight User Center', 'moonlight-user-center' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Describe exported fields.
	 *
	 * @return array<string, string> Meta key => field label.
	 */
	private function export_fields() {
		return mluc_meta_labels();
	}

	/**
	 * Export the personal data stored by this plugin.
	 *
	 * @param string $email_address Address being exported.
	 * @param int    $page          Page number, one-based.
	 * @return array{data:array, done:bool}
	 */
	public function export_personal_data( $email_address, $page = 1 ) {
		$page  = max( 1, absint( $page ) );
		$data  = array();
		$user  = get_user_by( 'email', $email_address );
		$done  = true;

		// Everything for one user fits in a single page.
		if ( 1 !== $page || ! $user ) {
			return array(
				'data' => $data,
				'done' => true,
			);
		}

		$items = array();

		foreach ( $this->export_fields() as $meta_key => $label ) {
			$value = get_user_meta( $user->ID, $meta_key, true );

			if ( '' === $value || null === $value || false === $value ) {
				continue;
			}

			if ( 'mluc_last_login' === $meta_key && is_numeric( $value ) ) {
				$value = date_i18n( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) $value );
			}

			$items[] = array(
				'name'  => $label,
				'value' => (string) $value,
			);
		}

		if ( $items ) {
			$data[] = array(
				'group_id'    => 'moonlight-user-center',
				'group_label' => __( 'User Center', 'moonlight-user-center' ),
				'item_id'     => 'moonlight-user-center-' . $user->ID,
				'data'        => $items,
			);
		}

		return array(
			'data' => $data,
			'done' => $done,
		);
	}

	/**
	 * Erase the personal data stored by this plugin.
	 *
	 * @param string $email_address Address being erased.
	 * @param int    $page          Page number, one-based.
	 * @return array{items_removed:bool, items_retained:bool, messages:string[], done:bool}
	 */
	public function erase_personal_data( $email_address, $page = 1 ) {
		$page = max( 1, absint( $page ) );

		$response = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		if ( 1 !== $page ) {
			return $response;
		}

		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return $response;
		}

		foreach ( array_keys( $this->export_fields() ) as $meta_key ) {
			if ( '' !== (string) get_user_meta( $user->ID, $meta_key, true ) ) {
				$response['items_removed'] = true;
			}

			delete_user_meta( $user->ID, $meta_key );
		}

		return $response;
	}

	/**
	 * Suggest privacy policy wording to the site administrator.
	 *
	 * @return void
	 */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'moonlight-user-center' ) . '</p>';

		$content .= '<p>' . esc_html__( 'This site uses Moonlight User Center to provide front-end login, registration, password recovery and a personal account area.', 'moonlight-user-center' ) . '</p>';

		$content .= '<p>' . esc_html__( 'When you register, the username, email address and password you submit are stored by WordPress. Your password is stored only as a one-way hash and is never visible to this site or to the plugin. If you complete your profile, the display name, nickname, first and last name, website address, biographical information and phone number you enter are stored on this site. If you pick an avatar, the identifier of the image you selected is stored with your account.', 'moonlight-user-center' ) . '</p>';

		$content .= '<p>' . esc_html__( 'This plugin does not send any data to an external service, does not embed third-party trackers and does not use cookies of its own. Sign-in sessions use the standard WordPress cookies.', 'moonlight-user-center' ) . '</p>';

		$content .= '<p>' . esc_html__( 'You can request an export of your personal data, or the erasure of the information listed above, at any time through the privacy tools on this site.', 'moonlight-user-center' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Moonlight User Center', 'moonlight-user-center' ), $content );
	}
}
