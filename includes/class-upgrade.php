<?php
/**
 * Free / Pro boundary and upgrade affordances.
 *
 * This class is the single source of truth for what the free plugin can do
 * and what the Pro add-on unlocks. Every limit is expressed as a *quantity*
 * rather than a missing feature: the free plugin ships a complete, working
 * monetisation loop, and it simply has a lower ceiling. Crossing that ceiling
 * is what makes Pro worth buying.
 *
 * Nothing here degrades the free experience with nag screens elsewhere in
 * wp-admin: every affordance renders on a User Center screen only, is
 * dismissible, and disappears entirely once Pro is active.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capability catalogue and upgrade UI.
 */
class AT8_MLUC_Upgrade {

	/**
	 * Dismissed-state user meta key.
	 *
	 * @var string
	 */
	const DISMISS_META = 'at8_mluc_upgrade_banner_dismissed';

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Upgrade|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Upgrade
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
		add_action( 'wp_ajax_at8_mluc_dismiss_upgrade', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Whether the Pro add-on is active with a valid license.
	 *
	 * @return bool
	 */
	public static function is_pro() {
		return (bool) at8_mluc_is_pro();
	}

	/**
	 * Where to send someone who wants to upgrade.
	 *
	 * @return string
	 */
	public static function upgrade_url() {
		return (string) apply_filters( 'at8_mluc_upgrade_url', 'https://www.at8.fun/' );
	}

	/**
	 * Free-tier ceilings for every limited resource.
	 *
	 * `0` means unlimited. Ceilings are filterable so the Pro add-on can raise
	 * them without this class knowing it exists.
	 *
	 * @param string $key Resource key.
	 * @param int    $default Free-tier value used when nothing filters it.
	 * @return int
	 */
	public static function limit( $key, $default ) {
		return (int) apply_filters( 'at8_mluc_limit_' . $key, (int) $default );
	}

	/**
	 * Membership level ceiling (including the free base level).
	 *
	 * @return int
	 */
	public static function level_limit() {
		return max( 1, AT8_MLUC_Membership::level_limit() );
	}

	/**
	 * Avatar library ceiling. Zero means unlimited.
	 *
	 * @return int
	 */
	public static function avatar_limit() {
		return self::limit( 'avatar_count', 12 );
	}

	/**
	 * Payment gateway ceiling.
	 *
	 * @return int
	 */
	public static function gateway_limit() {
		return self::limit( 'gateway_count', 1 );
	}

	/**
	 * The Pro feature catalogue.
	 *
	 * Each entry: slug => array( title, free, pro, note ). `free` and `pro`
	 * are short human-readable values used in the comparison table; all of
	 * them are translated on render so the file stays translation-free.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function features() {
		$catalogue = array(
			'membership_levels' => array(
				'title' => __( 'Membership levels', 'at8-moonlight-user-center' ),
				'free'  => sprintf(
					/* translators: %d: number of membership levels included in the free plugin. */
					__( 'Up to %d', 'at8-moonlight-user-center' ),
					3
				),
				'pro'   => sprintf(
					/* translators: %d: number of membership levels included in the Pro add-on. */
					__( 'Up to %d', 'at8-moonlight-user-center' ),
					8
				),
				'note'  => __( 'Split your audience into as many tiers as your funnel needs.', 'at8-moonlight-user-center' ),
			),
			'upgrade_paths'    => array(
				'title' => __( 'Ways to upgrade a member', 'at8-moonlight-user-center' ),
				'free'  => __( 'Redeem points', 'at8-moonlight-user-center' ),
				'pro'   => __( 'Points, direct payment, tier ladders', 'at8-moonlight-user-center' ),
				'note'  => __( 'Let members pay with a card, climb a tier ladder or spend points — whichever converts best.', 'at8-moonlight-user-center' ),
			),
			'gateways'          => array(
				'title' => __( 'Payment gateways', 'at8-moonlight-user-center' ),
				'free'  => __( 'Bank transfer only', 'at8-moonlight-user-center' ),
				'pro'   => __( 'Bank transfer, PayPal, Stripe', 'at8-moonlight-user-center' ),
				'note'  => __( 'Stop confirming transfers by hand — card payments settle themselves.', 'at8-moonlight-user-center' ),
			),
			'paywall'           => array(
				'title' => __( 'Paywall for single posts', 'at8-moonlight-user-center' ),
				'free'  => __( 'One level per post', 'at8-moonlight-user-center' ),
				'pro'   => __( 'Any levels, plus videos and downloads', 'at8-moonlight-user-center' ),
				'note'  => __( 'Sell one article at a time without a membership.', 'at8-moonlight-user-center' ),
			),
			'media'             => array(
				'title' => __( 'Demo videos and downloads', 'at8-moonlight-user-center' ),
				'free'  => __( 'Not included', 'at8-moonlight-user-center' ),
				'pro'   => __( 'Included', 'at8-moonlight-user-center' ),
				'note'  => __( 'Gate video libraries and downloadable materials by level.', 'at8-moonlight-user-center' ),
			),
			'content_gates'     => array(
				'title' => __( 'Content gates', 'at8-moonlight-user-center' ),
				'free'  => __( 'Login, comment', 'at8-moonlight-user-center' ),
				'pro'   => __( 'Login, comment, level, purchase', 'at8-moonlight-user-center' ),
				'note'  => __( 'Reveal teaser content only to paying members.', 'at8-moonlight-user-center' ),
			),
			'avatars'           => array(
				'title' => __( 'Avatar library', 'at8-moonlight-user-center' ),
				'free'  => sprintf(
					/* translators: %d: number of avatars allowed in the free plugin. */
					__( 'Up to %d', 'at8-moonlight-user-center' ),
					12
				),
				'pro'   => __( 'Unlimited', 'at8-moonlight-user-center' ),
				'note'  => __( 'Offer a full avatar gallery instead of a token handful.', 'at8-moonlight-user-center' ),
			),
			'purchases'         => array(
				'title' => __( 'Purchase history placement', 'at8-moonlight-user-center' ),
				'free'  => __( 'Account tab', 'at8-moonlight-user-center' ),
				'pro'   => __( 'Any page, via shortcode', 'at8-moonlight-user-center' ),
				'note'  => __( 'Put receipts where your funnel needs them.', 'at8-moonlight-user-center' ),
			),
			'reports'           => array(
				'title' => __( 'Revenue reporting', 'at8-moonlight-user-center' ),
				'free'  => __( 'Order list', 'at8-moonlight-user-center' ),
				'pro'   => __( 'Sales insights', 'at8-moonlight-user-center' ),
				'note'  => __( 'See what sells before deciding what to write next.', 'at8-moonlight-user-center' ),
			),
		);

		/**
		 * Filter the Pro comparison catalogue.
		 *
		 * The Pro add-on can correct the "free" column to reflect the limits
		 * it has actually raised, keeping one table honest for both plugins.
		 *
		 * @param array $catalogue Feature rows.
		 */
		return (array) apply_filters( 'at8_mluc_upgrade_features', $catalogue );
	}

	/**
	 * Inline "Pro" badge markup for a limited control.
	 *
	 * @return string
	 */
	public static function badge() {
		if ( self::is_pro() ) {
			return '';
		}

		return '<span class="at8-mluc-pro-badge">' . esc_html__( 'Pro', 'at8-moonlight-user-center' ) . '</span>';
	}

	/**
	 * Whether the admin dismissed the comparison banner.
	 *
	 * @return bool
	 */
	private static function banner_dismissed() {
		return (bool) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
	}

	/**
	 * Render the upgrade comparison table.
	 *
	 * Shown above the settings form when Pro is not active. Deliberately
	 * static-looking: no colours pulled from the theme, no animation that
	 * would fight the WordPress admin, and a real dismiss button.
	 *
	 * @return void
	 */
	public static function render_banner() {
		if ( self::is_pro() || self::banner_dismissed() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$features = self::features();

		if ( empty( $features ) ) {
			return;
		}
		?>
		<div class="at8-mluc-upgrade" id="at8-mluc-upgrade">
			<button type="button" class="at8-mluc-upgrade-dismiss" aria-expanded="true" aria-controls="at8-mluc-upgrade-body">
				<span class="dashicons dashicons-arrow-down-alt2"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Toggle or hide the Pro comparison', 'at8-moonlight-user-center' ); ?></span>
			</button>

			<div class="at8-mluc-upgrade-head">
				<span class="at8-mluc-upgrade-icon dashicons dashicons-superhero" aria-hidden="true"></span>
				<div>
					<h2 class="at8-mluc-upgrade-title"><?php esc_html_e( 'AT8 Moonlight User Center Pro', 'at8-moonlight-user-center' ); ?></h2>
					<p class="at8-mluc-upgrade-tagline">
						<?php esc_html_e( 'Everything here works today. Pro raises the ceiling.', 'at8-moonlight-user-center' ); ?>
					</p>
				</div>
			</div>

			<div class="at8-mluc-upgrade-body" id="at8-mluc-upgrade-body">
				<table class="at8-mluc-upgrade-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Feature', 'at8-moonlight-user-center' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Free', 'at8-moonlight-user-center' ); ?></th>
							<th scope="col" class="at8-mluc-upgrade-col-pro"><?php esc_html_e( 'Pro', 'at8-moonlight-user-center' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $features as $row ) : ?>
							<tr>
								<th scope="row">
									<?php echo esc_html( $row['title'] ); ?>
									<span class="at8-mluc-upgrade-note"><?php echo esc_html( $row['note'] ); ?></span>
								</th>
								<td><?php echo esc_html( $row['free'] ); ?></td>
								<td class="at8-mluc-upgrade-col-pro"><?php echo esc_html( $row['pro'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="at8-mluc-upgrade-actions">
					<a class="button button-primary" href="<?php echo esc_url( self::upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Compare Pro plans', 'at8-moonlight-user-center' ); ?>
					</a>
					<button type="button" class="button link" data-at8-mluc-upgrade-hide="1">
						<?php esc_html_e( 'Hide this panel', 'at8-moonlight-user-center' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Persist the dismissed state.
	 *
	 * @return void
	 */
	public function ajax_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You are not allowed to do this.', 'at8-moonlight-user-center' ) ) );
		}

		if ( ! check_ajax_referer( 'at8_mluc_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'The link has expired. Please refresh the page.', 'at8-moonlight-user-center' ) ) );
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );

		wp_send_json_success();
	}

	/**
	 * Gateway shelf shown above the offline payment settings.
	 *
	 * This is the free plugin's strongest upgrade hook, so it is stated plainly
	 * rather than hidden: bank transfer works right now, the card gateways are
	 * what Pro adds. It renders on the free plugin's own screen only.
	 *
	 * @return void
	 */
	public static function render_gateway_panel() {
		if ( self::is_pro() ) {
			return;
		}

		$gateways = array(
			array(
				'label' => __( 'Bank transfer', 'at8-moonlight-user-center' ),
				'note'  => __( 'Included — you confirm each payment by hand', 'at8-moonlight-user-center' ),
				'icon'  => 'dashicons-money-alt',
				'state' => 'free',
			),
			array(
				'label' => __( 'PayPal', 'at8-moonlight-user-center' ),
				'note'  => __( 'Pro — instant, self-settling payments', 'at8-moonlight-user-center' ),
				'icon'  => 'dashicons-money',
				'state' => 'pro',
			),
			array(
				'label' => __( 'Stripe', 'at8-moonlight-user-center' ),
				'note'  => __( 'Pro — cards and wallets, self-settling', 'at8-moonlight-user-center' ),
				'icon'  => 'dashicons-cart',
				'state' => 'pro',
			),
		);
		?>
		<div class="at8-mluc-gateways">
			<?php foreach ( $gateways as $gateway ) : ?>
				<div class="at8-mluc-gateway at8-mluc-gateway-<?php echo esc_attr( $gateway['state'] ); ?>">
					<span class="dashicons <?php echo esc_attr( $gateway['icon'] ); ?>" aria-hidden="true"></span>
					<div class="at8-mluc-gateway-body">
						<strong><?php echo esc_html( $gateway['label'] ); ?></strong>
						<span><?php echo esc_html( $gateway['note'] ); ?></span>
					</div>
					<?php if ( 'pro' === $gateway['state'] ) : ?>
						<?php echo wp_kses_post( self::badge() ); ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Inline row telling the admin a ceiling was reached.
	 *
	 * @param string $message What is capped.
	 * @return string
	 */
	public static function ceiling_notice( $message ) {
		if ( self::is_pro() ) {
			return '';
		}

		return sprintf(
			'<p class="at8-mluc-ceiling"><span class="dashicons dashicons-lock" aria-hidden="true"></span>%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></p>',
			esc_html( $message ),
			esc_url( self::upgrade_url() ),
			esc_html__( 'Unlock with Pro', 'at8-moonlight-user-center' )
		);
	}
}
