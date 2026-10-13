<?php
/**
 * Registration safeguards: terms acceptance and a password strength meter.
 *
 * Both ship in the free plugin. The terms checkbox is a plain required field
 * with a configurable label and link; the strength meter is a progressive
 * enhancement that never replaces the server-side password rules.
 *
 * @package AT8_Moonlight_User_Center
 */

defined( 'ABSPATH' ) || exit;

/**
 * Terms checkbox and password meter rendering.
 */
class AT8_MLUC_Terms {

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUC_Terms|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUC_Terms
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
		add_action( 'at8_mluc_register_form_fields', array( $this, 'render_terms' ), 5 );
		add_action( 'at8_mluc_register_form_fields', array( $this, 'render_strength' ), 20 );
	}

	/**
	 * Render the password strength meter inside the registration form.
	 *
	 * The meter is static markup; the front-end script scores the password as
	 * it is typed. Without JavaScript the meter simply stays empty and the
	 * form's own minlength rule still applies.
	 *
	 * @return void
	 */
	public function render_strength() {
		?>
		<div class="at8-mluc-strength" data-at8-mluc-strength="at8-mluc-register-pass"
			data-labels="<?php echo esc_attr( implode( '|', $this->strength_labels() ) ); ?>">
			<span class="at8-mluc-strength-track" aria-hidden="true"><span class="at8-mluc-strength-fill"></span></span>
			<span class="at8-mluc-strength-label"></span>
		</div>
		<?php
	}

	/**
	 * Labels for scores 0-4.
	 *
	 * @return array<int, string>
	 */
	private function strength_labels() {
		return array(
			__( 'Too short', 'at8-moonlight-user-center' ),
			__( 'Weak', 'at8-moonlight-user-center' ),
			__( 'Fair', 'at8-moonlight-user-center' ),
			__( 'Good', 'at8-moonlight-user-center' ),
			__( 'Strong', 'at8-moonlight-user-center' ),
		);
	}

	/**
	 * Whether the terms checkbox is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) at8_mluc_get_option( 'terms_enabled', 0 );
	}

	/**
	 * Terms page link.
	 *
	 * @return string
	 */
	public static function terms_url() {
		$page_id = (int) at8_mluc_get_option( 'terms_page_id', 0 );

		return $page_id > 0 ? (string) get_permalink( $page_id ) : '';
	}

	/**
	 * Render the terms checkbox inside the registration form.
	 *
	 * @return void
	 */
	public function render_terms() {
		if ( ! self::enabled() ) {
			return;
		}

		$label = (string) at8_mluc_get_option( 'terms_text', '' );

		if ( '' === $label ) {
			$label = __( 'I have read and agree to the terms of service.', 'at8-moonlight-user-center' );
		}

		$link = self::terms_url();
		?>
		<p class="at8-mluc-field at8-mluc-field--tos">
			<input type="checkbox" id="at8-mluc-register-terms" name="at8_mluc_terms" value="1" required />
			<label for="at8-mluc-register-terms">
				<?php echo esc_html( $label ); ?>
			<?php if ( '' !== $link ) : ?>
					<a href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Read the terms', 'at8-moonlight-user-center' ); ?>
					</a>
				<?php endif; ?>
			</label>
		</p>
		<?php
	}
}