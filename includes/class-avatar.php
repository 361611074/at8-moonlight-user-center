<?php
/**
 * Avatar library.
 *
 * Site administrators publish a set of avatars as a private post type; users
 * pick one from the account dashboard. Users never upload files themselves,
 * which keeps arbitrary file upload out of the front end entirely.
 *
 * @package Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin-curated avatar selection.
 */
class MLUC_Avatar {

	/**
	 * Post type holding the avatar library.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mluc_avatar';

	/**
	 * User meta: how the avatar was chosen (`library`).
	 *
	 * @var string
	 */
	const META_TYPE = 'mluc_avatar_type';

	/**
	 * User meta: attachment ID of the chosen avatar.
	 *
	 * @var string
	 */
	const META_ATTACHMENT = 'mluc_avatar_attachment_id';

	/**
	 * User meta: absolute URL of the chosen avatar.
	 *
	 * @var string
	 */
	const META_URL = 'mluc_avatar_url';

	/**
	 * Nonce action for the avatar form.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'mluc_select_avatar';

	/**
	 * Singleton instance.
	 *
	 * @var MLUC_Avatar|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return MLUC_Avatar
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
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'wp_ajax_mluc_select_avatar', array( $this, 'handle_select' ) );
		add_filter( 'get_avatar_url', array( $this, 'filter_avatar_url' ), 10, 3 );
	}

	/**
	 * Whether the avatar library is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) mluc_get_option( 'enable_avatar', 1 );
	}

	/**
	 * Register the private avatar library post type.
	 *
	 * @return void
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Avatar Library', 'moonlight-user-center' ),
					'singular_name'      => __( 'Avatar', 'moonlight-user-center' ),
					'add_new_item'       => __( 'Add New Avatar', 'moonlight-user-center' ),
					'edit_item'          => __( 'Edit Avatar', 'moonlight-user-center' ),
					'new_item'           => __( 'New Avatar', 'moonlight-user-center' ),
					'view_item'          => __( 'View Avatar', 'moonlight-user-center' ),
					'search_items'       => __( 'Search Avatars', 'moonlight-user-center' ),
					'not_found'          => __( 'No avatars found.', 'moonlight-user-center' ),
					'not_found_in_trash' => __( 'No avatars found in Trash.', 'moonlight-user-center' ),
					'all_items'          => __( 'Avatar Library', 'moonlight-user-center' ),
					'menu_name'          => __( 'Avatar Library', 'moonlight-user-center' ),
				),
				'description'         => __( 'Avatars that users can pick from in their account dashboard.', 'moonlight-user-center' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'thumbnail', 'page-attributes' ),
				'menu_icon'           => 'dashicons-format-image',
			)
		);
	}

	/**
	 * Read the published avatar library.
	 *
	 * @return array<int, array{id:int, attachment_id:int, url:string, title:string}>
	 */
	public static function get_library() {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => 100,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'date'       => 'DESC',
				),
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		$library = array();

		foreach ( $posts as $post ) {
			$attachment_id = (int) get_post_thumbnail_id( $post->ID );

			if ( ! $attachment_id ) {
				continue;
			}

			$url = wp_get_attachment_image_url( $attachment_id, 'medium' );

			if ( ! $url ) {
				continue;
			}

			$library[] = array(
				'id'            => (int) $post->ID,
				'attachment_id' => $attachment_id,
				'url'           => $url,
				'title'         => get_the_title( $post ),
			);
		}

		return $library;
	}

	/**
	 * Resolve the avatar URL chosen by a user.
	 *
	 * @param int $user_id User ID.
	 * @return string Empty string when the user has no library avatar.
	 */
	public static function get_user_avatar_url( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return '';
		}

		$attachment_id = absint( get_user_meta( $user_id, self::META_ATTACHMENT, true ) );

		if ( $attachment_id ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'medium' );

			if ( $url ) {
				return $url;
			}
		}

		$url = (string) get_user_meta( $user_id, self::META_URL, true );

		return $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * Replace the generated avatar URL with the user's chosen avatar.
	 *
	 * @param string            $url          Generated avatar URL.
	 * @param int|string|object $id_or_email  User ID, email address, user object or comment.
	 * @param array             $args         Avatar arguments.
	 * @return string Filtered avatar URL.
	 */
	public function filter_avatar_url( $url, $id_or_email, $args ) {
		if ( ! self::is_enabled() ) {
			return $url;
		}

		$user_id = 0;

		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( $id_or_email instanceof WP_User ) {
			$user_id = (int) $id_or_email->ID;
		} elseif ( $id_or_email instanceof WP_Comment ) {
			$user_id = (int) $id_or_email->user_id;
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			if ( $user ) {
				$user_id = (int) $user->ID;
			}
		}

		if ( ! $user_id ) {
			return $url;
		}

		$custom = self::get_user_avatar_url( $user_id );

		return $custom ? $custom : $url;
	}

	/**
	 * Handle the AJAX avatar selection.
	 *
	 * @return void
	 */
	public function handle_select() {
		MLUC_Security::require_ajax_nonce( self::NONCE_ACTION );
		MLUC_Security::require_login();

		if ( ! self::is_enabled() ) {
			mluc_send_json( false, __( 'The avatar library is disabled.', 'moonlight-user-center' ) );
		}

		if ( ! MLUC_Security::allow( 'avatar' ) ) {
			MLUC_Security::reject_throttled();
		}

		$user_id = get_current_user_id();

		// An explicit reset request restores the WordPress default avatar.
		$reset = isset( $_POST['mluc_reset'] ) ? sanitize_text_field( wp_unslash( $_POST['mluc_reset'] ) ) : '';

		if ( '1' === $reset ) {
			delete_user_meta( $user_id, self::META_TYPE );
			delete_user_meta( $user_id, self::META_ATTACHMENT );
			delete_user_meta( $user_id, self::META_URL );

			mluc_send_json(
				true,
				__( 'Your avatar has been reset.', 'moonlight-user-center' ),
				array( 'avatar_url' => get_avatar_url( $user_id, array( 'size' => 96 ) ) )
			);
		}

		$avatar_id = isset( $_POST['avatar_id'] ) ? absint( wp_unslash( $_POST['avatar_id'] ) ) : 0;

		if ( ! $avatar_id ) {
			mluc_send_json( false, __( 'Please choose an avatar.', 'moonlight-user-center' ) );
		}

		if ( self::POST_TYPE !== get_post_type( $avatar_id ) || 'publish' !== get_post_status( $avatar_id ) ) {
			mluc_send_json( false, __( 'That avatar is not available.', 'moonlight-user-center' ) );
		}

		$attachment_id = (int) get_post_thumbnail_id( $avatar_id );

		if ( ! $attachment_id ) {
			mluc_send_json( false, __( 'That avatar has no image attached.', 'moonlight-user-center' ) );
		}

		$url = wp_get_attachment_image_url( $attachment_id, 'medium' );

		if ( ! $url ) {
			mluc_send_json( false, __( 'That avatar image could not be loaded.', 'moonlight-user-center' ) );
		}

		update_user_meta( $user_id, self::META_TYPE, 'library' );
		update_user_meta( $user_id, self::META_ATTACHMENT, $attachment_id );
		update_user_meta( $user_id, self::META_URL, esc_url_raw( $url ) );

		/**
		 * Fires after a user changed their avatar.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id       User ID.
		 * @param int $attachment_id Attachment ID of the chosen avatar.
		 */
		do_action( 'mluc_after_avatar_update', $user_id, $attachment_id );

		mluc_send_json(
			true,
			__( 'Your avatar has been updated.', 'moonlight-user-center' ),
			array(
				'avatar_url' => $url,
				'avatar_id'  => $avatar_id,
			)
		);
	}
}
