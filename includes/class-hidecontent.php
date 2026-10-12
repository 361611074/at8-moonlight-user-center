<?php
/**
 * [at8_mluc_hide] shortcode + content filter.
 *
 * Usage: [at8_mluc_hide type="reply|logged"]...[/at8_mluc_hide]
 *   - reply   Visible after the visitor's comment is approved.
 *   - logged  Visible once the visitor is logged in.
 *
 * Pro add-on types (membership / purchase gates) are injected through the
 * `at8_mluc_hide_types` filter, so one shortcode name covers every tier.
 *
 * Nesting safety: rendering runs do_shortcode over $content, so inner
 * shortcodes are not swallowed by the outer one. Elementor compatibility:
 * the filter also hooks elementor/frontend/the_content and
 * elementor/widget/render_content (priority 5, before do_shortcode at p11).
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hidden content shortcode.
 */
class AT8_MLUC_Hidecontent {

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Hidecontent
	 */
	public static function get_instance() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_shortcode( 'at8_mluc_hide', array( $this, 'render' ) );
		/*
		 * Legacy alias from the Pro 1.0.x days. Registered here (not in Pro)
		 * so old content never leaks raw shortcode text on sites where Pro is
		 * absent or its license lapsed: unlicensed types render the generic
		 * locked card instead of revealing anything.
		 */
		add_shortcode( 'at8_mlucp_hide', array( $this, 'render' ) );
		// Standard content chain p5: ahead of the WP default do_shortcode (p11),
		// so the shortcode is consumed first. do_shortcode is idempotent — the
		// second run finds no shortcode tags left and does not re-render.
		add_filter( 'the_content', array( $this, 'maybe_filter' ), 5 );
		// Elementor compatibility: text widgets / Elementor render chain.
		add_filter( 'elementor/frontend/the_content', array( $this, 'maybe_filter' ), 5 );
		add_filter( 'elementor/widget/render_content', array( $this, 'maybe_filter' ), 5 );
	}

	/**
	 * The type registry.
	 *
	 * @return array<string, array{label:string, cta:string, icon:string}>
	 */
	public static function get_types() {
		$types = array(
			'reply'  => array(
				'label' => __( 'Comment to View', 'at8-moonlight-user-center' ),
				'cta'   => 'Comment to View',
				'icon'  => '💬',
			),
			'logged' => array(
				'label' => __( 'Log in to View', 'at8-moonlight-user-center' ),
				'cta'   => 'Log in to View',
				'icon'  => '🔒',
			),
		);

		/**
		 * Filters the hidden-content type registry.
		 *
		 * Pro add-ons register their commercial types (membership, purchase)
		 * here; the values follow the same shape as the built-in ones.
		 *
		 * @since 2.1.0
		 *
		 * @param array $types Type registry.
		 */
		return (array) apply_filters( 'at8_mluc_hide_types', $types );
	}

	/**
	 * the_content filter: run do_shortcode once on content that contains the shortcode.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function maybe_filter( $content ) {
		if ( ! is_string( $content ) ) {
			return $content;
		}

		if ( false === strpos( $content, '[at8_mluc_hide' ) && false === strpos( $content, '[at8_mlucp_hide' ) ) {
			return $content;
		}

		return do_shortcode( $content );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Wrapped content.
	 * @return string
	 */
	public function render( $atts, $content = '' ) {
		$atts = shortcode_atts(
			array( 'type' => 'logged' ),
			$atts,
			'at8_mluc_hide'
		);

		$type = isset( $atts['type'] ) ? (string) $atts['type'] : 'logged';
		$type = sanitize_key( $type );

		$types = self::get_types();

		if ( ! isset( $types[ $type ] ) ) {
			$type = 'logged';
		}

		// Nesting safety: run inner shortcodes too.
		$inner = do_shortcode( $content );

		if ( self::user_can_view( $type ) ) {
			return $inner;
		}

		return self::render_cta( $type );
	}

	/**
	 * Whether the current visitor may see the given type of hidden content.
	 *
	 * @param string $type Type key.
	 * @return bool
	 */
	public static function user_can_view( $type ) {
		/*
		 * The author and roles that can edit others' posts always see the
		 * content, for preview and debugging. edit_posts is deliberately not
		 * enough: contributors and authors could then read every hidden
		 * block on the site.
		 */
		if ( is_user_logged_in()
			&& ( current_user_can( 'edit_others_posts' ) || current_user_can( 'manage_options' ) || self::is_current_post_author() ) ) {
			return true;
		}

		switch ( $type ) {
			case 'logged':
				return is_user_logged_in();

			case 'reply':
				return self::user_has_approved_comment();

			default:
				/**
				 * Filters whether the visitor may see a Pro-registered hidden-content type.
				 *
				 * @since 2.1.0
				 *
				 * @param bool   $can_view Default false.
				 * @param string $type     Type key.
				 */
				return (bool) apply_filters( 'at8_mluc_hide_user_can_view', false, $type );
		}
	}

	/**
	 * The author of the current post.
	 *
	 * @return bool
	 */
	private static function is_current_post_author() {
		if ( ! is_singular() || ! is_user_logged_in() ) {
			return false;
		}

		$post_id = (int) get_queried_object_id();

		if ( ! $post_id ) {
			return false;
		}

		$post = get_post( $post_id );

		return $post && (int) $post->post_author === (int) get_current_user_id();
	}

	/**
	 * Whether the current user has an approved comment on the current post.
	 *
	 * @return bool
	 */
	private static function user_has_approved_comment() {
		if ( ! is_user_logged_in() || ! is_singular() ) {
			return false;
		}

		$post_id = (int) get_queried_object_id();

		if ( ! $post_id ) {
			return false;
		}

		$user  = wp_get_current_user();
		$email = (string) $user->user_email;

		if ( '' === $email ) {
			return false;
		}

		$query = new WP_Comment_Query(
			array(
				'post_id'      => $post_id,
				'status'       => 'approve',
				'author_email' => $email,
				'count'        => true,
			)
		);

		return ( (int) $query->get_found_posts() ) > 0;
	}

	/**
	 * Render the locked-content CTA card.
	 *
	 * @param string $type Type key.
	 * @return string
	 */
	private static function render_cta( $type ) {
		$types = self::get_types();
		$info  = isset( $types[ $type ] ) ? $types[ $type ] : array(
			'label' => __( 'Locked Content', 'at8-moonlight-user-center' ),
			'cta'   => 'Locked Content',
			'icon'  => '🔒',
		);

		switch ( $type ) {
			case 'logged':
				$action = sprintf(
					'<a class="at8-mluc-hc-btn" href="%s">%s</a>',
					esc_url( at8_mluc_get_login_url( get_permalink() ) ),
					esc_html__( 'Log In', 'at8-moonlight-user-center' )
				);
				$tip = esc_html__( 'Log in to view the content below.', 'at8-moonlight-user-center' );
				break;

			case 'reply':
				$action = sprintf(
					'<a class="at8-mluc-hc-btn" href="%s#respond">%s</a>',
					esc_url( is_singular() ? get_permalink() : home_url( '/' ) ),
					esc_html__( 'Post a Comment', 'at8-moonlight-user-center' )
				);
				$tip = esc_html__( 'Your comment must be approved before the content is revealed.', 'at8-moonlight-user-center' );
				break;

			default:
				/**
				 * Filters the CTA markup for a Pro-registered hidden-content type.
				 *
				 * Return a non-empty string to replace the default card.
				 *
				 * @since 2.1.0
				 *
				 * @param string $html Default empty.
				 * @param string $type Type key.
				 */
				$custom = (string) apply_filters( 'at8_mluc_hide_cta_html', '', $type );

				if ( '' !== $custom ) {
					return $custom;
				}

				$action = '';
				$tip    = esc_html__( 'This content is locked.', 'at8-moonlight-user-center' );
				break;
		}

		// Titles go through the label filter (English default, admin-overridable).
		$title = at8_mluc_ui_label(
			'hc_title_' . $type,
			isset( $info['cta'] ) ? $info['cta'] : __( 'Locked Content', 'at8-moonlight-user-center' )
		);

		$type_esc = esc_attr( $type );

		return sprintf(
			'<div class="at8-mluc-hidecontent at8-mluc-hc-%1$s" data-type="%1$s">'
				. '<div class="at8-mluc-hc-inner">'
				. '<span class="at8-mluc-hc-icon" aria-hidden="true">%2$s</span>'
				. '<div class="at8-mluc-hc-text">'
				. '<div class="at8-mluc-hc-title">%3$s</div>'
				. '<div class="at8-mluc-hc-tip">%4$s</div>'
				. '</div>'
				. '<div class="at8-mluc-hc-action">%5$s</div>'
				. '</div></div>',
			$type_esc,
			esc_html( $info['icon'] ),
			esc_html( $title ),
			$tip,
			$action
		);
	}
}
