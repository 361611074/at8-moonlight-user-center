<?php
/**
 * 第三方登录（OAuth2）：微信 / QQ / GitHub / Google / Apple。
 *
 * 各提供商的 client_id / client_secret 在后台「第三方登录」区块配置；
 * 未配置则对应按钮不显示。回调地址固定为 home_url('/?at8mluc_oauth=提供商')。
 * 真实密钥由站点管理员填写，本类只负责标准 OAuth2 流程与账号绑定。
 *
 * @package AT8_Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
	exit;
}

class AT8MLUC_OAuth
{
	private static $instance;

	public static function get_instance()
	{
		if (!self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct()
	{
		add_action('init', array($this, 'maybe_handle_callback'), 5);
		add_shortcode('at8mluc_oauth_buttons', array($this, 'shortcode_buttons'));
		// 挂在 Free 登录/注册表单底部；主题或其它插件也可自行挂这个 action。
		add_action('at8mluc_login_form_bottom', array($this, 'render_buttons'));
		add_action('at8mluc_register_form_bottom', array($this, 'render_buttons'));
		// 兼容动作名（历史版本）。
		add_action('at8mluc_oauth_buttons', array($this, 'render_buttons'));
	}

	/**
	 * 提供商配置表（端点与展示信息）。
	 */
	public static function providers()
	{
		return apply_filters('at8mluc_oauth_providers', array(
			'wechat' => array(
				'label' => __('微信', 'at8-moonlight-user-center'),
				'color' => '#07c160',
				'icon_svg' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="#fff" d="M8.69 4C4.54 4 1.2 6.94 1.2 10.5c0 2.04 1.08 3.86 2.76 5.08L3.04 18l2.62-1.36c.9.25 1.86.39 2.86.39.26 0 .51-.01.76-.03a5.7 5.7 0 0 1-.34-1.96c0-3.39 3.22-6.09 7.2-6.09.26 0 .51.01.76.04C16.62 6.27 13.1 4 8.69 4zm-2.29 4.6a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm4.6 0a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/><path fill="#fff" d="M22.8 14.5c0-2.9-2.82-5.25-6.3-5.25s-6.3 2.35-6.3 5.25 2.82 5.25 6.3 5.25c.74 0 1.46-.1 2.12-.3l2.02 1.05-.55-1.86c1.44-1.02 2.41-2.5 2.41-4.14zm-8.3-.9a.8.8 0 1 1 0-1.6.8.8 0 0 1 0 1.6zm3.9 0a.8.8 0 1 1 0-1.6.8.8 0 0 1 0 1.6z"/></svg>',
				'auth_url'   => 'https://open.weixin.qq.com/connect/qrconnect',
				'token_url'  => 'https://api.weixin.qq.com/sns/oauth2/access_token',
				'scope'      => 'snsapi_login',
				'response_type' => 'code',
				'format'     => 'json',
				'type'       => 'wechat',
			),
			'qq' => array(
				'label' => __('QQ', 'at8-moonlight-user-center'),
				'color' => '#12b7f5',
				'icon_svg' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="#fff" d="M12 2.2c-3.6 0-6.4 2.9-6.4 6.6 0 1.4.4 2.7 1.1 3.8-.9 1.2-1.5 2.7-1.5 3.9 0 .7.2 1.3.6 1.8-.2.5-.2 1.1 0 1.7.3.9 1.2 1.6 2.3 1.6.6 1.1 1.9 1.9 3.5 1.9.9 0 1.7-.2 2.4-.5.5.1 1 .3 1.6.3.7 0 1.4-.2 2-.5.7.3 1.6.5 2.4.5 1.6 0 2.9-.8 3.5-1.9 1.1 0 2-.7 2.3-1.6.2-.6.2-1.2 0-1.7.4-.5.6-1.1.6-1.8 0-1.2-.6-2.7-1.5-3.9.7-1.1 1.1-2.4 1.1-3.8 0-3.7-2.8-6.6-6.4-6.6zm-2.6 9.2c.6 0 1 .5 1 1.1s-.4 1.1-1 1.1-1-.5-1-1.1.4-1.1 1-1.1zm5.2 0c.6 0 1 .5 1 1.1s-.4 1.1-1 1.1-1-.5-1-1.1.4-1.1 1-1.1z"/></svg>',
				'auth_url'   => 'https://graph.qq.com/oauth2.0/authorize',
				'token_url'  => 'https://graph.qq.com/oauth2.0/token',
				'scope'      => 'get_user_info',
				'response_type' => 'code',
				'format'     => 'query',
				'type'       => 'qq',
			),
			'github' => array(
				'label' => __('GitHub', 'at8-moonlight-user-center'),
				'color' => '#24292e',
				'icon_svg' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="#fff" d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>',
				'auth_url'   => 'https://github.com/login/oauth/authorize',
				'token_url'  => 'https://github.com/login/oauth/access_token',
				'scope'      => 'read:user user:email',
				'response_type' => 'code',
				'format'     => 'json',
				'type'       => 'github',
			),
			'google' => array(
				'label' => __('Google', 'at8-moonlight-user-center'),
				'color' => '#ea4335',
				'icon_svg' => '<svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true" focusable="false"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>',
				'auth_url'   => 'https://accounts.google.com/o/oauth2/v2/auth',
				'token_url'  => 'https://oauth2.googleapis.com/token',
				'userinfo_url' => 'https://www.googleapis.com/oauth2/v3/userinfo',
				'scope'      => 'openid email profile',
				'response_type' => 'code',
				'format'     => 'json',
				'type'       => 'google',
			),
			'apple' => array(
				'label' => __('Apple', 'at8-moonlight-user-center'),
				'color' => '#000000',
				'icon_svg' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="#fff" d="M17.05 12.04c-.03-2.65 2.16-3.92 2.26-3.98-1.23-1.8-3.15-2.05-3.83-2.08-1.63-.16-3.18.96-4.01.96-.83 0-2.11-.94-3.47-.91-1.78.03-3.43 1.04-4.35 2.64-1.86 3.23-.48 8.01 1.34 10.63.89 1.28 1.95 2.72 3.34 2.67 1.34-.05 1.85-.87 3.47-.87 1.62 0 2.08.87 3.49.84 1.44-.03 2.35-1.31 3.23-2.6 1.02-1.49 1.44-2.93 1.46-3.01-.03-.01-2.8-1.08-2.83-4.27zM14.6 4.59c.73-.89 1.22-2.12 1.09-3.35-1.05.04-2.32.7-3.08 1.58-.68.78-1.27 2.04-1.11 3.24 1.17.09 2.37-.6 3.1-1.47z"/></svg>',
				'auth_url'   => 'https://appleid.apple.com/auth/authorize',
				'token_url'  => 'https://appleid.apple.com/auth/token',
				'scope'      => 'name email',
				'response_type' => 'code',
				// Apple 默认 response_mode=form_post 会把 code 放到 POST 体，WP 的 $_GET 读不到 → 登录必失败。
				// 显式 query 让 code/state 走 GET，便于本站标准回调处理。
				'response_mode' => 'query',
				'format'     => 'json',
				'type'       => 'apple',
			),
		));
	}

	/**
	 * 读取某提供商的 client_id / client_secret（存于 at8mluc_options['oauth']）。
	 */
	private function get_creds($id)
	{
		$all = at8mluc_get_option('oauth', array());
		$c   = isset($all[$id]) ? $all[$id] : array();
		return array(
			'client_id'     => isset($c['client_id']) ? trim($c['client_id']) : '',
			'client_secret' => isset($c['client_secret']) ? trim($c['client_secret']) : '',
		);
	}

	public function is_enabled($id)
	{
		$creds = $this->get_creds($id);
		return !empty($creds['client_id']) && !empty($creds['client_secret']);
	}

	public function get_redirect_uri($id)
	{
		return home_url('/?at8mluc_oauth=' . rawurlencode($id));
	}

	/**
	 * 生成授权 URL（带 state 防 CSRF，并把回跳地址存入 state）。
	 *
	 * @param string $id          提供商
	 * @param string $redirect_to 登录后期望回跳的同站地址（内部白名单校验后才用）
	 * @param bool   $remember    是否延长会话；不传时由 at8mluc_oauth_remember 决定，默认 false。
	 */
	public function get_auth_url($id, $redirect_to = '', $remember = null)
	{
		$providers = self::providers();
		if (!isset($providers[$id])) {
			return '';
		}
		$p = $providers[$id];
		$creds = $this->get_creds($id);
		// 32 alphanumeric characters from wp_rand(); the callback insists on
		// this exact shape, so a state can never be a value the site did not
		// mint itself.
		$state = wp_generate_password(32, false);

		if (null === $remember) {
			/**
			 * Filters whether a social login keeps the session alive longer.
			 *
			 * Default false: a provider login has the same short session as a
			 * normal login without "remember me". The front-end script adds a
			 * per-click cookie when the visitor ticks the login form's
			 * checkbox; that value is read on the callback, so a site does not
			 * need this filter unless it wants a site-wide policy.
			 *
			 * @since 2.7.2
			 *
			 * @param bool   $remember   Whether to set a long-lived auth cookie.
			 * @param string $id         Provider slug.
			 * @param string $redirect_to Same-site return URL.
			 */
			$remember = (bool) apply_filters('at8mluc_oauth_remember', false, $id, $redirect_to);
		}

		// state 同时记录 provider、回跳意图与发起时的登录身份，回调据此还原
		set_transient('at8mluc_oauth_state_' . $state, array(
			'provider'  => $id,
			'redirect'  => $redirect_to ? esc_url_raw($redirect_to) : '',
			'remember'  => (bool) $remember,
			// 安全：记录发起授权时的用户。回调时若当前登录用户与之不同，
			// 说明是别人发起的流程被用到本浏览器上（账户绑定劫持），一律拒绝。
			'init_user' => get_current_user_id(),
		), 600);
		// 安全：state 需与浏览器会话绑定。仅靠 transient 校验时，攻击者可拿自己
		// 生成的 state + 自己的授权 code 拼出回调链接，诱导已登录的受害者点击，
		// 从而把攻击者的第三方身份绑到受害者账户（账户接管）。写入 cookie 后，
		// 回调必须出示同一浏览器持有的 state 才被接受。
		$this->set_state_cookie($state);

		$params = array(
			'response_type' => $p['response_type'],
			'client_id'     => $creds['client_id'],
			'redirect_uri'  => $this->get_redirect_uri($id),
			'scope'         => $p['scope'],
			'state'         => $state,
		);
		if (!empty($p['response_mode'])) {
			$params['response_mode'] = $p['response_mode'];
		}
		return add_query_arg($params, $p['auth_url']);
	}

	/**
	 * 把 state 写入会话 cookie（HttpOnly + SameSite=Lax，10 分钟有效）。
	 * SameSite=Lax 保证第三方完成授权后的顶层 GET 回跳仍会携带本 cookie。
	 */
	private function set_state_cookie($state)
	{
		if (headers_sent()) {
			return;
		}
		$opts = array(
			'expires'  => time() + 600,
			'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		if (PHP_VERSION_ID >= 70300) {
			setcookie('at8mluc_oauth_state', $state, $opts);
		} else {
			setcookie(
				'at8mluc_oauth_state',
				$state,
				$opts['expires'],
				$opts['path'] . '; SameSite=Lax',
				$opts['domain'],
				$opts['secure'],
				$opts['httponly']
			);
		}
	}

	private function clear_state_cookie()
	{
		if (headers_sent()) {
			return;
		}
		$path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
		setcookie('at8mluc_oauth_state', '', time() - 3600, $path, $domain, is_ssl(), true);
	}

	/**
	 * 渲染登录按钮（仅显示已配置的提供商），使用官方品牌图标。
	 *
	 * @param string $redirect_to 透传到授权 URL 的回跳地址（当前页面带的 redirect_to）
	 */
	public function render_buttons($redirect_to = '')
	{
		$providers = self::providers();
		$enabled = array();
		foreach ($providers as $id => $p) {
			if ($this->is_enabled($id)) {
				$enabled[$id] = $p;
			}
		}
		if (empty($enabled)) {
			return;
		}
		// 优先采用当前页面传入的 redirect_to（如登录页 ?redirect_to=...），保证登录后回原处
		if (!$redirect_to) {
			$redirect_to = $this->requested_redirect();
		}
		echo '<div class="at8mluc-oauth">';
		echo '<div class="at8mluc-oauth-divider"><span>' . esc_html__('或使用第三方账号登录', 'at8-moonlight-user-center') . '</span></div>';
		echo '<div class="at8mluc-oauth-buttons">';
		foreach ($enabled as $id => $p) {
			printf(
				'<a class="at8mluc-oauth-btn at8mluc-oauth-%s" href="%s" style="background:%s" aria-label="%s" rel="noopener">',
				esc_attr($id),
				esc_url($this->get_auth_url($id, $redirect_to)),
				esc_attr($p['color']),
				/* translators: %s: social network name (WeChat, QQ, GitHub, ...). */
				sprintf(esc_attr__('使用 %s 登录', 'at8-moonlight-user-center'), esc_attr($p['label']))
			);
			// The SVG passes a strict element/attribute allow-list below: filter
			// providers are not trusted, so scripts, event handlers, external
			// references and unknown elements/attributes are stripped by wp_kses.
			echo wp_kses( isset($p['icon_svg']) ? $p['icon_svg'] : '', self::svg_allowed_tags() );
			printf('<span>%s</span></a>', esc_html($p['label']));
		}
		echo '</div></div>';
	}

	public function shortcode_buttons()
	{
		ob_start();
		$this->render_buttons();
		return ob_get_clean();
	}

	/**
	 * Sanitise an SVG icon down to a fixed element/attribute allow-list.
	 *
	 * Providers come from a filterable table, so the value is never trusted:
	 * <script>, foreignObject, event handlers (on*), external <use>/<image>
	 * references and unknown elements/attributes are removed by wp_kses.
	 *
	 * @return array Allowed SVG elements and attributes.
	 */
	public static function svg_allowed_tags()
	{
		$allowed = array(
			'svg'      => array('viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'aria-hidden' => true, 'focusable' => true, 'role' => true, 'xmlns' => true, 'class' => true),
			'g'        => array('fill' => true, 'fill-opacity' => true, 'stroke' => true, 'transform' => true, 'opacity' => true),
			'path'     => array('d' => true, 'fill' => true, 'fill-opacity' => true, 'fill-rule' => true, 'clip-rule' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true),
			'circle'   => array('cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true),
			'ellipse'  => array('cx' => true, 'cy' => true, 'rx' => true, 'ry' => true, 'fill' => true),
			'rect'     => array('x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true),
			'polygon'  => array('points' => true, 'fill' => true),
			'polyline' => array('points' => true, 'fill' => true),
			'line'     => array('x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true),
			'title'    => array(),
		);

		return $allowed;
	}

	/**
	 * Maximum accepted length of each callback parameter, in bytes.
	 *
	 * The limits are generous enough for real provider responses but keep a
	 * crafted value out of transients, option names and outbound URLs.
	 *
	 * @var array<string, int>
	 */
	private $param_limits = array(
		'at8mluc_oauth' => 64,
		'code'          => 2048,
		'state'         => 64,
		'error'         => 128,
		'redirect_to'   => 2048,
		'remember'      => 8,
		'firstName'     => 191,
		'lastName'      => 191,
	);

	/**
	 * 读取当前页面要求登录后返回的地址。
	 *
	 * 只用于拼本站跳转地址：先做类型与长度校验，再经 esc_url_raw() 过滤，
	 * 最后用 wp_validate_redirect() 拒绝站外目标。
	 *
	 * @return string
	 */
	private function requested_redirect()
	{
		$requested = $this->callback_param('redirect_to');

		if ('' === $requested) {
			return '';
		}

		// 只保留同站地址；外部域名一律丢弃，不进 state、不进授权 URL。
		return (string) wp_validate_redirect(esc_url_raw($requested), '');
	}

	/**
	 * 读取第三方回传的一个参数。
	 *
	 * 这里无法使用 WordPress nonce：浏览器是从第三方域名跳回来的，那个域名
	 * 并不知道本站的 nonce。真正的 CSRF 凭据是 state —— 由本站生成、存进
	 * transient 并绑定当前会话，在使用下面任何值之前先校验（见
	 * maybe_handle_callback()）。Apple 用 form_post 回传，所以 GET / POST
	 * 两处都读，且只在这一处读。
	 *
	 * 只接受字符串/整数/浮点三种标量。数组、对象、资源、布尔值一律拒绝，
	 * 不做 (string) 强转——那会在数组上产生 "Array to string conversion"
	 * Warning，并让意外的结构流入 OAuth 流程。超长值直接拒绝而不是截断：
	 * 截断后的 state 或 code 可能碰巧命中另一个合法值。
	 *
	 * @param string $name       参数名，决定长度上限。
	 * @param int    $max_length 覆盖默认上限。
	 * @return string 已清洗的值；缺失、类型异常、超长均为空串。
	 */
	private function callback_param($name, $max_length = 0)
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 第三方回调；CSRF 由 state 参数校验，值在方法末尾统一 sanitize_text_field()。
		$value = isset($_GET[$name]) ? wp_unslash($_GET[$name]) : null;

		if (null === $value) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 第三方回调（Apple 用表单 POST）；CSRF 由 state 参数校验，值在方法末尾统一 sanitize_text_field()。
			$value = isset($_POST[$name]) ? wp_unslash($_POST[$name]) : null;
		}

		if (null === $value) {
			return '';
		}

		// 类型白名单：非标量绝不转换成字符串。bool 也拒绝，避免 '1' 被当成开关。
		if (!is_string($value) && !is_int($value) && !is_float($value)) {
			$this->reject_malformed_input($name, 'type');
			return '';
		}

		if (is_float($value) && !is_finite($value)) {
			$this->reject_malformed_input($name, 'type');
			return '';
		}

		$value = (string) $value;
		$limit = $max_length > 0 ? (int) $max_length : (isset($this->param_limits[$name]) ? $this->param_limits[$name] : 256);

		if (strlen($value) > $limit) {
			$this->reject_malformed_input($name, 'length');
			return '';
		}

		return sanitize_text_field($value);
	}

	/**
	 * 记录一次畸形回调输入，供站点做日志/告警。
	 *
	 * 只记录参数名和原因，不记录原始值——回调参数里可能有 token。
	 *
	 * @param string $name   参数名。
	 * @param string $reason type 或 length。
	 */
	private function reject_malformed_input($name, $reason)
	{
		/**
		 * Fires when a callback parameter was refused for its type or length.
		 *
		 * @since 2.7.2
		 *
		 * @param string $name   Whitelisted parameter name.
		 * @param string $reason Either 'type' or 'length'.
		 */
		do_action('at8mluc_oauth_malformed_input', (string) $name, (string) $reason);
	}

	/**
	 * 读取一个只接受字符串的表单字段（Apple 的 user 对象元素）。
	 *
	 * @param mixed  $value 字段值。
	 * @param string $name  用于查长度上限的字段名。
	 * @return string
	 */
	private function scalar_field($value, $name)
	{
		if (!is_string($value) && !is_int($value) && !is_float($value)) {
			$this->reject_malformed_input($name, 'type');
			return '';
		}

		$value  = (string) $value;
		$limit  = isset($this->param_limits[$name]) ? $this->param_limits[$name] : 191;

		if (strlen($value) > $limit) {
			$this->reject_malformed_input($name, 'length');
			return '';
		}

		return sanitize_text_field($value);
	}

	/**
	 * 读取 Apple 一次性回传的姓名对象。
	 *
	 * @return string[] [firstName, lastName]，缺失或类型异常项为空串。
	 */
	private function callback_user_names()
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 第三方回调；数组的每个元素由 scalar_field() 单独校验。
		$user = isset($_POST['user']) && is_array($_POST['user']) ? wp_unslash($_POST['user']) : array();

		$first = isset($user['firstName']) ? $this->scalar_field($user['firstName'], 'firstName') : '';
		$last  = isset($user['lastName']) ? $this->scalar_field($user['lastName'], 'lastName') : '';

		return array($first, $last);
	}

	/**
	 * 已绑定某个第三方账号的用户。
	 *
	 * 直接查 usermeta：这是一个按值取单条记录的查找，每次社交登录都会走，
	 * 用一条走索引的语句比"整表用户查询 + meta 过滤"更快，也不需要把
	 * 结果集取回内存。
	 *
	 * @param string $meta_key 绑定用的 meta 键。
	 * @param string $value    第三方账号 ID。
	 * @return int 用户 ID，未绑定返回 0。
	 */
	private static function find_bound_user($meta_key, $value)
	{
		global $wpdb;

		$value = (string) $value;

		if ('' === $value) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 按 meta 值取单条记录，meta API 无法在不拉整批用户的情况下做到。
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				$meta_key,
				$value
			)
		);
	}

	/**
	 * 回调处理：?at8mluc_oauth=provider&code=...&state=...
	 * Apple 在某些配置下以 form_post 回传，故 code/state 同时兼容 GET 与 POST。
	 *
	 * 第三方回调没有 WP nonce 可校验，改用 state（transient）+ 同源 cookie
	 * 双因子防登录 CSRF 与账户绑定劫持，见下方两处安全校验。
	 */
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Third-party OAuth callback; CSRF is covered by the state transient + cookie pair below.
	public function maybe_handle_callback()
	{
		$id = sanitize_key($this->callback_param('at8mluc_oauth'));
		if (!$id) {
			return;
		}
		$providers = self::providers();
		if (!isset($providers[$id])) {
			$this->oauth_fail('unsupported', __('不支持的登录方式。', 'at8-moonlight-user-center'));
		}

		// 用户取消授权（各平台回传 error 参数）
		$error = $this->callback_param('error');
		if ($error) {
			$this->oauth_fail('cancelled', __('已取消授权，请重试或使用其他方式登录。', 'at8-moonlight-user-center'));
		}

		// 兼容 GET（多数平台）与 POST（Apple form_post）两种回传方式
		$code  = $this->callback_param('code');
		$state = $this->callback_param('state');

		/*
		 * A state is a fixed-shape token this plugin minted a moment ago.
		 * Checking the shape before it is used as part of an option name
		 * keeps the lookup cheap and keeps a long or exotic value from
		 * reaching the options cache at all.
		 */
		if ('' !== $state && ! preg_match('/^[A-Za-z0-9]{16,64}$/', $state)) {
			$this->oauth_fail('state', __('安全校验失败（state 格式无效），请重试。', 'at8-moonlight-user-center'));
		}

		$st = $state ? get_transient('at8mluc_oauth_state_' . $state) : null;
		if (!$code || !$state || !is_array($st) || empty($st['provider']) || $st['provider'] !== $id) {
			$this->oauth_fail('state', __('安全校验失败（state 无效），请重试。', 'at8-moonlight-user-center'));
		}
		// 安全校验一：state 必须来自本浏览器会话（本浏览器确实发起过这次授权），
		// 防止攻击者用自己的 state+code 构造回调链接让他人点击（登录 CSRF）。
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 只读比较值；先做类型与长度校验，再清洗。
		$raw_cookie_state = isset($_COOKIE['at8mluc_oauth_state']) ? wp_unslash($_COOKIE['at8mluc_oauth_state']) : '';
		$cookie_state = $this->scalar_field($raw_cookie_state, 'state');
		if ('' === $cookie_state || !hash_equals((string) $cookie_state, (string) $state)) {
			$this->clear_state_cookie();
			$this->oauth_fail('state', __('安全校验失败（state 不匹配），请重试。', 'at8-moonlight-user-center'));
		}
		// 安全校验二：已登录时，发起授权的身份必须与当前身份一致，
		// 否则就是别人的授权流程被套用到当前账户上（账户绑定劫持）。
		if (is_user_logged_in() && isset($st['init_user']) && (int) $st['init_user'] !== get_current_user_id()) {
			$this->clear_state_cookie();
			$this->oauth_fail('state', __('安全校验失败（登录身份不一致），请重试。', 'at8-moonlight-user-center'));
		}
		delete_transient('at8mluc_oauth_state_' . $state);
		$this->clear_state_cookie();
		$redirect_to = !empty($st['redirect']) ? (string) $st['redirect'] : '';

		// 「记住我」由登录页脚本在点击时写入短时 cookie；站点策略存在 state 里。
		// 两者都默认 false，绝不无条件延长会话。
		$remember_click = $this->requested_remember();
		$remember       = !empty($st['remember']) || $remember_click;

		$token = $this->fetch_token($id, $code);
		if (is_wp_error($token)) {
			$this->oauth_fail('token', $token->get_error_message());
		}

		$profile = $this->fetch_profile($id, $token);
		if (is_wp_error($profile)) {
			$this->oauth_fail('profile', $profile->get_error_message());
		}

		$this->login_or_register($id, $profile, $redirect_to, $remember);
	}

	/**
	 * 读取并清除登录页脚本写下的「记住我」意图。
	 *
	 * 只认字面量 '1'；数组、对象、其它值一律按 false 处理，不写日志、
	 * 不展示、不进数据库。cookie 用完即清，避免影响下一次登录。
	 *
	 * @return bool
	 */
	private function requested_remember()
	{
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 只比较字面量；scalar_field() 先做类型与长度校验。
		$raw = isset($_COOKIE['at8mluc_oauth_remember']) ? wp_unslash($_COOKIE['at8mluc_oauth_remember']) : '';

		$value = $this->scalar_field($raw, 'remember');

		$this->clear_remember_cookie();

		return '1' === $value;
	}

	/**
	 * 清除「记住我」意图 cookie。
	 */
	private function clear_remember_cookie()
	{
		if (headers_sent()) {
			return;
		}

		$path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
		setcookie('at8mluc_oauth_remember', '', time() - 3600, $path, $domain, is_ssl(), true);
	}

	/**
	 * OAuth 失败统一跳回登录页并带错误码（不再白屏 wp_die）。
	 * 模板按错误码展示对应中文提示（见 templates/login.php）。
	 */
	private function oauth_fail($code, $message)
	{
		/**
		 * Fires when a social-login attempt is refused.
		 *
		 * @since 2.7.0
		 *
		 * @param string $code    Short reason (state, cancelled, token, profile, unsupported, register_closed, register_fail, bind_taken, bind_conflict).
		 * @param string $message Human-readable explanation.
		 */
		do_action('at8mluc_oauth_fail', (string) $code, (string) $message);

		/*
		 * The notice travels in a short-lived cookie instead of the query
		 * string: the login page can then show it without reading request
		 * input, and the value is a whitelisted code either way.
		 */
		if (!headers_sent()) {
			setcookie(
				'at8mluc_oauth_notice',
				(string) $code,
				array(
					'expires'  => time() + 300,
					'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		wp_safe_redirect(at8mluc_get_login_url());
		exit;
	}

	/**
	 * 用授权码换取 access_token（按提供商格式差异处理）。
	 */
	private function fetch_token($id, $code)
	{
		$providers = self::providers();
		$p = $providers[$id];
		$creds = $this->get_creds($id);

		$body = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'client_id'    => $creds['client_id'],
			'client_secret'=> $creds['client_secret'],
			'redirect_uri' => $this->get_redirect_uri($id),
		);

		$headers = array();
		if ('github' === $p['type']) {
			$headers['Accept'] = 'application/json';
		}

		$response = wp_remote_post($p['token_url'], array(
			'body'    => $body,
			'headers' => $headers,
			'timeout' => 20,
		));
		if (is_wp_error($response)) {
			return $response;
		}
		$raw  = wp_remote_retrieve_body($response);
		$data = ('query' === $p['format']) ? wp_parse_args($raw) : json_decode($raw, true);
		if (!is_array($data) || empty($data['access_token'])) {
			return new WP_Error('oauth_token', __('换取令牌失败。', 'at8-moonlight-user-center'));
		}
		return $data;
	}

	/**
	 * 取用户信息（按提供商差异处理），返回统一 profile 数组。
	 */
	private function fetch_profile($id, $token)
	{
		$providers = self::providers();
		$p = $providers[$id];
		$access_token = $token['access_token'];
		$profile = array('provider' => $id, 'provider_id' => '', 'email' => '', 'name' => '', 'avatar' => '', 'email_verified' => false);

		switch ($p['type']) {
			case 'wechat':
				if (empty($token['openid'])) {
					return new WP_Error('oauth_openid', __('获取微信 openid 失败。', 'at8-moonlight-user-center'));
				}
				$profile['provider_id'] = $token['openid'];
				$info = $this->get_json('https://api.weixin.qq.com/sns/userinfo', array(
					'access_token' => $access_token,
					'openid'       => $token['openid'],
				));
				if (!is_wp_error($info)) {
					$profile['name']   = isset($info['nickname']) ? $info['nickname'] : '';
					$profile['avatar'] = isset($info['headimgurl']) ? $info['headimgurl'] : '';
				}
				break;

			case 'qq':
				$me = wp_remote_get('https://graph.qq.com/oauth2.0/me?access_token=' . rawurlencode($access_token), array('timeout' => 20));
				if (is_wp_error($me)) {
					return $me;
				}
				if (!preg_match('/"openid"\s*:\s*"([^"]+)"/', wp_remote_retrieve_body($me), $m)) {
					return new WP_Error('oauth_openid', __('获取 QQ openid 失败。', 'at8-moonlight-user-center'));
				}
				$openid = $m[1];
				$profile['provider_id'] = $openid;
				$info = $this->get_json('https://graph.qq.com/user/get_user_info', array(
					'access_token'       => $access_token,
					'oauth_consumer_key' => $this->get_creds($id)['client_id'],
					'openid'             => $openid,
				));
				if (!is_wp_error($info)) {
					$profile['name']   = isset($info['nickname']) ? $info['nickname'] : '';
					$profile['avatar'] = isset($info['figureurl_qq_2']) ? $info['figureurl_qq_2'] : (isset($info['figureurl_qq_1']) ? $info['figureurl_qq_1'] : '');
				}
				break;

			case 'github':
				$user = $this->get_json('https://api.github.com/user', array(), array('Authorization' => 'Bearer ' . $access_token, 'User-Agent' => 'at8-moonlight-user-center', 'Accept' => 'application/vnd.github+json'));
				if (is_wp_error($user)) {
					return $user;
				}
				$profile['provider_id'] = isset($user['id']) ? (string) $user['id'] : '';
				$profile['name']   = isset($user['name']) ? $user['name'] : (isset($user['login']) ? $user['login'] : '');
				$profile['avatar'] = isset($user['avatar_url']) ? $user['avatar_url'] : '';
				$profile['email']  = isset($user['email']) ? $user['email'] : '';
				// 仅采用已验证邮箱，避免用他人未验证邮箱接管账户
				$profile['email_verified'] = !empty($user['email']) && !empty($user['email_verified']);
				if (empty($profile['email'])) {
					$emails = $this->get_json('https://api.github.com/user/emails', array(), array('Authorization' => 'Bearer ' . $access_token, 'User-Agent' => 'at8-moonlight-user-center', 'Accept' => 'application/vnd.github+json'));
					if (!is_wp_error($emails) && is_array($emails)) {
						foreach ($emails as $e) {
							if (!empty($e['email']) && !empty($e['verified']) && !empty($e['primary'])) {
								$profile['email'] = $e['email'];
								$profile['email_verified'] = true;
								break;
							}
						}
						// 无已验证主邮箱则不采纳任何邮箱
						if (empty($profile['email_verified'])) {
							$profile['email'] = '';
						}
					}
				}
				break;

			case 'google':
				$info = $this->get_json($p['userinfo_url'], array(), array('Authorization' => 'Bearer ' . $access_token));
				if (is_wp_error($info)) {
					return $info;
				}
				$profile['provider_id'] = isset($info['sub']) ? $info['sub'] : '';
				$profile['email']  = isset($info['email']) ? $info['email'] : '';
				$profile['name']   = isset($info['name']) ? $info['name'] : '';
				$profile['avatar'] = isset($info['picture']) ? $info['picture'] : '';
				// Google 返回 email_verified 声明
				$profile['email_verified'] = !empty($info['email_verified']) && !empty($info['email']);
				break;

			case 'apple':
				// Apple 通过 id_token(JWT) 返回 sub 与 email；必须校验签名，否则可伪造身份接管账户。
				if (empty($token['id_token'])) {
					return new WP_Error('oauth_apple', __('获取 Apple 用户信息失败。', 'at8-moonlight-user-center'));
				}
				$payload = $this->verify_apple_id_token($token['id_token']);
				if (is_wp_error($payload)) {
					return $payload;
				}
				if (empty($payload['sub'])) {
					return new WP_Error('oauth_apple', __('解析 Apple 身份失败。', 'at8-moonlight-user-center'));
				}
				$profile['provider_id'] = $payload['sub'];
				$profile['email'] = isset($payload['email']) ? $payload['email'] : '';
				// Apple 私邮中继域名不做邮箱匹配，避免与其它账户误绑
				$profile['email_verified'] = !empty($profile['email']) && false === strpos($profile['email'], '@privaterelay.appleid.com');
				$profile['name']  = trim(implode(' ', $this->callback_user_names()));
				if (empty($profile['name'])) {
					$profile['name'] = $profile['email'] ? strtok($profile['email'], '@') : __('Apple 用户', 'at8-moonlight-user-center');
				}
				break;

			default:
				return new WP_Error('oauth_unsupported', __('暂不支持该登录方式。', 'at8-moonlight-user-center'));
		}

		if (empty($profile['provider_id'])) {
			return new WP_Error('oauth_noid', __('未能获取第三方用户标识。', 'at8-moonlight-user-center'));
		}
		return $profile;
	}

	/**
	 * 登录或注册并绑定。
	 *
	 * @param string $redirect_to 登录后回跳地址（已通过 state 白名单校验）
	 */
	private function login_or_register($id, $profile, $redirect_to = '', $remember = false)
	{
		$meta_key = 'at8mluc_oauth_' . $id . '_id';

		// 已登录：把第三方账号绑定到当前账户。绑定不在这里落库——
		// 先通过登录策略检查，do_login() 认证通过后才写入，见其 $bind_meta_key。
		if (is_user_logged_in()) {
			$this->do_login(get_current_user_id(), $profile, $redirect_to, $remember, $meta_key);
		}

		// 按 provider_id 查找已有绑定
		$bound_user = self::find_bound_user($meta_key, $profile['provider_id']);
		if ($bound_user) {
			$this->do_login($bound_user, $profile, $redirect_to, $remember);
		}

		// 按邮箱查找：仅当第三方已验证该邮箱归属时才自动登录既有账户，
		// 否则任意未验证邮箱可冒用他人账户（账户接管）。
		/**
		 * Filters whether a verified provider e-mail may be matched to an
		 * existing account.
		 *
		 * The default keeps the familiar behaviour: a verified address that
		 * belongs to an existing account signs that account in. A site that
		 * would rather require an explicit link for every account can return
		 * false here; new accounts are then created instead of matched.
		 *
		 * @since 2.7.1
		 *
		 * @param bool   $allow   Whether to match by e-mail.
		 * @param array  $profile Normalised provider profile.
		 * @param string $id      Provider slug.
		 */
		$link_by_email = apply_filters('at8mluc_oauth_allow_email_link', true, $profile, $id);

		if ($link_by_email && !empty($profile['email']) && !empty($profile['email_verified'])) {
			$u = get_user_by('email', $profile['email']);
			if ($u) {
				// 绑定同样延后到认证通过之后；账户已绑了同提供商的另一个
				// 第三方账号时会被拒绝，不会覆盖。
				$this->do_login($u->ID, $profile, $redirect_to, $remember, $meta_key);
			}
		}

		// 自动注册新用户
		if (!get_option('users_can_register')) {
			$this->oauth_fail('register_closed', __('站点已关闭注册，且未找到匹配的账号。', 'at8-moonlight-user-center'));
		}
		$login = $this->generate_login($id, $profile);
		// 无邮箱的提供商（微信/QQ）必须保证邮箱唯一，否则第二个用户会撞 @oauth.local 导致注册失败。
		// 用 provider+provider_id 派生唯一本地邮箱，避免与既有用户冲突。
		$email = !empty($profile['email'])
			? $profile['email']
			: ('ml' . $id . '_' . substr(md5($profile['provider_id']), 0, 8) . '@oauth.local');
		$user_id = wp_create_user($login, wp_generate_password(24, false), $email);
		if (is_wp_error($user_id)) {
			/*
			 * 撞邮箱通常意味着上一次注册创建了账户、但登录策略拒绝了登录，
			 * 第三方绑定没有写入——账户被保留等待重试（见 do_login 的绑定
			 * 时机）。找到那个账户继续本次认证，而不是再造一个孤儿账户，
			 * 也不是直接失败让访客永远无法完成登录。
			 */
			$retry = get_user_by('email', $email);
			// 来自第三方的邮箱必须已经过验证才能作为找回依据；派生邮箱
			// 由 provider_id 决定，找回等价于按 provider_id 匹配，是安全的。
			if (!$retry || (!empty($profile['email']) && empty($profile['email_verified']))) {
				$this->oauth_fail('register_fail', $user_id->get_error_message());
			}
			$user_id = (int) $retry->ID;
		}
		if (!empty($profile['name'])) {
			wp_update_user(array('ID' => $user_id, 'display_name' => $profile['name']));
		}
		$this->do_login($user_id, $profile, $redirect_to, $remember, $meta_key);
	}

	/**
	 * 第三方身份验证成功之后、建立 WordPress 登录态之前的统一认证适配层。
	 *
	 * 四种事情严格分开，绝不把 provider 返回的资料直接当成本地已认证用户：
	 *
	 *   1. 第三方验证：provider 的 token / profile 已由 fetch_* 校验；
	 *   2. 本地账户匹配：按绑定 ID / 已验证邮箱解析出本地 WP_User；
	 *   3. 本地登录策略：站点停用规则 + WordPress 原生认证扩展点；
	 *   4. 登录状态建立：只在第 3 步全部放行之后，由 do_login() 完成。
	 *
	 * 为什么不调用 wp_signon() / wp_authenticate()：两者都会走密码校验，而
	 * OAuth 没有本地密码；伪造一个密码再"通过"校验既是撒谎也会绕过真正的
	 * 策略检查。这里改为把已经解析好的 WP_User 作为初始值交给原生的
	 * `authenticate` 过滤器链 —— 核心的两个密码校验器看到 WP_User 会原样
	 * 返回（core 的 wp_authenticate_username_password() 首行即如此），而
	 * 挂在 authenticate 上的安全 / 限流 / 双因素插件仍然可以返回 WP_Error
	 * 拒绝本次登录。随后再跑一次 `wp_authenticate_user`，覆盖 spam / ban
	 * 这类挂在该过滤器上的插件。
	 *
	 * 诚实的限制：需要交互式第二因子（现场输入 TOTP 码、短信码）的插件在
	 * OAuth 回调里拿不到输入界面。这类插件返回 WP_Error 时本方法拒绝登录，
	 * 而不是绕过它；因此这是「可被拒绝、不被绕过」，不是「全面兼容 2FA」。
	 * 站点若挂载了与已认证 WP_User 不兼容的 authenticate 回调，可用
	 * at8mluc_oauth_use_authenticate_filter 关闭该桥接（默认开启）。
	 *
	 * @param int   $user_id 已解析出的本地用户 ID。
	 * @param array $profile 归一化后的 provider 资料。
	 * @return WP_User|WP_Error 认证通过的用户，或失败原因。
	 */
	private function authenticate_oauth_user($user_id, $profile)
	{
		$user = get_userdata((int) $user_id);

		if (!$user || !$user->ID) {
			return new WP_Error('at8mluc_oauth_no_user', 'identity');
		}

		// 多站点：非本站点成员且非超级管理员，不允许从本站登录。
		if (is_multisite() && !is_user_member_of_blog($user->ID, get_current_blog_id()) && !is_super_admin($user->ID)) {
			return $this->oauth_denied($user, 'not_member', new WP_Error('at8mluc_oauth_not_member', 'not_member'));
		}

		/**
		 * Filters whether the matched local account is allowed to sign in.
		 *
		 * Sites with their own "disabled / suspended / banned" flag can return
		 * false or a WP_Error here. The default reflects WordPress core, which
		 * has no separate account-active flag.
		 *
		 * @since 2.7.2
		 *
		 * @param bool|WP_Error $active  Whether the account may log in.
		 * @param WP_User       $user    Matched local account.
		 * @param array         $profile Normalised provider profile.
		 */
		$active = apply_filters('at8mluc_oauth_user_is_active', true, $user, $profile);

		if (is_wp_error($active)) {
			return $this->oauth_denied($user, 'account_policy', $active);
		}

		if (!$active) {
			return $this->oauth_denied($user, 'inactive', new WP_Error('at8mluc_oauth_inactive', 'inactive'));
		}

		/*
		 * 原生「登录开始」事件。第二个参数是空字符串，含义是「本次没有本地
		 * 密码」，不是伪造一个密码：密码校验完全不参与本流程。
		 */
		$login_name  = $user->user_login;
		$no_password = '';
		do_action_ref_array('wp_authenticate', array(&$login_name, &$no_password)); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core action fired by wp_signon() before authentication.

		// 2.7.1 起插件专用否决口，保留向后兼容。
		$veto = apply_filters('at8mluc_oauth_authenticate_user', true, $user, $profile);

		if (is_wp_error($veto)) {
			return $this->oauth_denied($user, 'veto', $veto);
		}

		if (!$veto) {
			return $this->oauth_denied($user, 'veto', new WP_Error('at8mluc_oauth_veto', 'veto'));
		}

		/**
		 * Filters whether the native `authenticate` filter chain is used.
		 *
		 * Default true. Set it to false only when a third-party callback on
		 * `authenticate` cannot cope with a pre-resolved WP_User; the plugin
		 * still applies `at8mluc_oauth_authenticate_user` and
		 * `wp_authenticate_user` in that case.
		 *
		 * @since 2.7.2
		 *
		 * @param bool    $use     Whether to bridge into the core filter.
		 * @param WP_User $user    Matched local account.
		 * @param array   $profile Normalised provider profile.
		 */
		$use_native = apply_filters('at8mluc_oauth_use_authenticate_filter', true, $user, $profile);

		$result = $user;

		if ($use_native) {
			// 传 WP_User 而非 null：core 的密码校验器会短路，策略插件仍可否决。
			$result = apply_filters('authenticate', $user, $user->user_login, ''); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter: the extension point security plugins use.
		}

		if (!$result instanceof WP_User) {
			$error = is_wp_error($result) ? $result : new WP_Error('at8mluc_oauth_authenticate_failed', 'not_wp_user');
			return $this->oauth_denied($user, 'authenticate', $error);
		}

		// core 在密码校验成功后触发它；spam / ban 类插件常挂在这里。
		$result = apply_filters('wp_authenticate_user', $result, ''); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter: account-level login policy hooks live here.

		if (is_wp_error($result)) {
			return $this->oauth_denied($user, 'user_policy', $result);
		}

		if (!$result instanceof WP_User) {
			return $this->oauth_denied($user, 'user_policy', new WP_Error('at8mluc_oauth_user_policy', 'not_wp_user'));
		}

		// 防账户掉包：任何过滤器都不能把身份换成另一个用户。
		if ((int) $result->ID !== (int) $user->ID) {
			return $this->oauth_denied($user, 'identity_changed', new WP_Error('at8mluc_oauth_identity_changed', 'identity_changed'));
		}

		return $result;
	}

	/**
	 * 认证拒绝的统一出口：触发原生失败事件让限流 / 审计插件计数，
	 * 详细原因只交给站点动作，不回显给浏览器。
	 *
	 * @param WP_User  $user   被拒绝的账户。
	 * @param string   $reason 内部原因码。
	 * @param WP_Error $error  错误对象。
	 * @return WP_Error
	 */
	private function oauth_denied($user, $reason, $error)
	{
		do_action('wp_login_failed', $user->user_login, $error); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core action: rate limiting and audit plugins count refusals here.

		/**
		 * Fires when a social login was refused by a local login policy.
		 *
		 * @since 2.7.2
		 *
		 * @param string   $reason Short internal reason code.
		 * @param int      $user_id Matched local account ID.
		 * @param WP_Error $error  Full error object, for site-owned logging.
		 */
		do_action('at8mluc_oauth_login_denied', (string) $reason, (int) $user->ID, $error);

		return $error;
	}

	/**
	 * 认证通过后建立会话 —— 插件里唯一一处为社交登录写 auth cookie 的地方。
	 *
	 * 顺序照 wp_signon()：secure cookie 过滤 → wp_set_auth_cookie() →
	 * wp_login 动作；wp_set_current_user() 只为了让本次请求的
	 * is_user_logged_in() 立即生效。已经以此身份登录时（账户绑定）整段跳过，
	 * 不重复建 cookie、不重复触发登录动作。
	 *
	 * @param int    $user_id       本地用户 ID。
	 * @param array  $profile       归一化后的 provider 资料。
	 * @param string $redirect_to   已校验的同站回跳地址。
	 * @param bool   $remember      是否延长会话，默认 false。
	 * @param string $bind_meta_key 需要在认证通过后写入的绑定 meta 键；
	 *                              空串表示该用户已有绑定或无需绑定。
	 * @return void
	 */
	private function do_login($user_id, $profile, $redirect_to = '', $remember = false, $bind_meta_key = '')
	{
		$user = $this->authenticate_oauth_user($user_id, $profile);

		if (is_wp_error($user)) {
			// 通用文案：不泄漏第三方错误、安全插件规则或账户状态细节。
			$this->oauth_fail('blocked', __('本站当前不允许该账户通过第三方账号登录。', 'at8-moonlight-user-center'));
		}

		/*
		 * 登录策略全部放行之后、建立会话之前，才写入第三方绑定关系：
		 * 认证被拒绝时既不落绑定、也不建 Cookie、也不触发登录成功动作。
		 * 两种冲突都不覆盖旧数据：第三方账号已绑到别的用户、或本账户
		 * 已绑定了同一提供商的另一个第三方账号。绑定冲突时同样不建会话。
		 */
		if ('' !== $bind_meta_key) {
			$taken_by = self::find_bound_user($bind_meta_key, $profile['provider_id']);
			if ($taken_by && (int) $taken_by !== (int) $user->ID) {
				$this->oauth_fail('bind_taken', __('该第三方账号已绑定其他账户。', 'at8-moonlight-user-center'));
			}
			$existing = (string) get_user_meta($user->ID, $bind_meta_key, true);
			if ('' !== $existing && $existing !== (string) $profile['provider_id']) {
				$this->oauth_fail('bind_conflict', __('该账户已绑定此登录方式的其他账号。', 'at8-moonlight-user-center'));
			}
			update_user_meta($user->ID, $bind_meta_key, $profile['provider_id']);
		}

		$already_signed_in = is_user_logged_in() && (int) get_current_user_id() === (int) $user->ID;

		if (!$already_signed_in) {
			$credentials = array(
				'user_login'    => $user->user_login,
				'user_password' => '',
				'remember'      => (bool) $remember,
			);

			$secure_cookie = apply_filters('secure_signon_cookie', is_ssl(), $credentials); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter used by wp_signon().

			wp_set_auth_cookie($user->ID, (bool) $remember, (bool) $secure_cookie);
			wp_set_current_user($user->ID);

			/** This action is documented in wp-includes/user.php */
			do_action('wp_login', $user->user_login, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core action: the event security and session plugins listen for.
		}

		/**
		 * Fires after a social login has been accepted.
		 *
		 * The third parameter tells listeners whether a new session was
		 * created or the visitor was already signed in and only linked an
		 * account. Previously this action had two parameters; extra arguments
		 * do not break existing callbacks.
		 *
		 * @since 2.7.0
		 *
		 * @param int   $user_id           Signed-in account ID.
		 * @param array $profile           Normalised provider profile.
		 * @param bool  $already_signed_in No new session was created.
		 */
		do_action('at8mluc_oauth_login', $user->ID, $profile, $already_signed_in);

		// 回跳地址在 state 生成时已校验，此处再兜一次，绝不裸跳外部域名。
		if ($redirect_to && wp_validate_redirect($redirect_to, false)) {
			wp_safe_redirect($redirect_to);
		} else {
			wp_safe_redirect(at8mluc_get_account_url());
		}
		exit;
	}

	private function generate_login($id, $profile)
	{
		$base = 'ml' . $id . '_' . substr(md5($profile['provider_id']), 0, 8);
		$login = $base;
		$i = 1;
		while (username_exists($login)) {
			$login = $base . $i;
			$i++;
		}
		return $login;
	}

	/**
	 * GET 请求并解析 JSON。
	 */
	private function get_json($url, $query = array(), $headers = array())
	{
		$u = $query ? add_query_arg($query, $url) : $url;
		$response = wp_remote_get($u, array('headers' => $headers, 'timeout' => 20));
		if (is_wp_error($response)) {
			return $response;
		}
		$data = json_decode(wp_remote_retrieve_body($response), true);
		if (!is_array($data)) {
			return new WP_Error('oauth_json', __('第三方返回数据解析失败。', 'at8-moonlight-user-center'));
		}
		return $data;
	}

	/**
	 * 校验 Apple id_token（RS256 签名 + 受众 + 签发方 + 过期），返回 payload 数组或 WP_Error。
	 * 不验签会被伪造身份接管账户，故校验失败一律拒绝。
	 */
	private function verify_apple_id_token($jwt)
	{
		$parts = explode('.', $jwt);
		if (count($parts) !== 3) {
			return new WP_Error('oauth_apple', __('Apple 令牌格式错误。', 'at8-moonlight-user-center'));
		}
		list($head_b64, $payload_b64, $sig_b64) = $parts;
		$header = json_decode($this->b64url($head_b64), true);
		if (empty($header['kid']) || empty($header['alg']) || 'RS256' !== $header['alg']) {
			return new WP_Error('oauth_apple', __('Apple 令牌算法不支持。', 'at8-moonlight-user-center'));
		}
		$keys = $this->get_json('https://appleid.apple.com/auth/keys');
		if (is_wp_error($keys) || empty($keys['keys'])) {
			return new WP_Error('oauth_apple', __('无法获取 Apple 公钥。', 'at8-moonlight-user-center'));
		}
		$pub = null;
		foreach ($keys['keys'] as $k) {
			if (isset($k['kid']) && $k['kid'] === $header['kid']) {
				$pub = $k;
				break;
			}
		}
		if (empty($pub['n']) || empty($pub['e'])) {
			return new WP_Error('oauth_apple', __('Apple 公钥不匹配。', 'at8-moonlight-user-center'));
		}
		$pem = $this->rsa_pem_from_mod_exp($pub['n'], $pub['e']);
		if (!$pem) {
			return new WP_Error('oauth_apple', __('构建 Apple 公钥失败。', 'at8-moonlight-user-center'));
		}
		$key = openssl_pkey_get_public($pem);
		if (!$key) {
			return new WP_Error('oauth_apple', __('加载 Apple 公钥失败。', 'at8-moonlight-user-center'));
		}
		$signing = $head_b64 . '.' . $payload_b64;
		$sig = $this->b64url_raw($sig_b64);
		$ok = openssl_verify($signing, $sig, $key, OPENSSL_ALGO_SHA256);
		// OpenSSL key resources are freed automatically since PHP 8; the
		// deprecated openssl_free_key() call was removed.
		if (1 !== $ok) {
			return new WP_Error('oauth_apple', __('Apple 令牌签名校验失败。', 'at8-moonlight-user-center'));
		}
		$payload = json_decode($this->b64url($payload_b64), true);
		if (!is_array($payload)) {
			return new WP_Error('oauth_apple', __('Apple 令牌解析失败。', 'at8-moonlight-user-center'));
		}
		$client_id = $this->get_creds('apple')['client_id'];
		if ($client_id && (!empty($payload['aud']) && $payload['aud'] !== $client_id)) {
			return new WP_Error('oauth_apple', __('Apple 令牌受众不符。', 'at8-moonlight-user-center'));
		}
		if (empty($payload['iss']) || 'https://appleid.apple.com' !== $payload['iss']) {
			return new WP_Error('oauth_apple', __('Apple 令牌签发方不符。', 'at8-moonlight-user-center'));
		}
		if (!empty($payload['exp']) && (int) $payload['exp'] < time()) {
			return new WP_Error('oauth_apple', __('Apple 令牌已过期。', 'at8-moonlight-user-center'));
		}
		return $payload;
	}

	private function b64url($b64)
	{
		return base64_decode(strtr($b64, '-_', '+/'), true);
	}

	private function b64url_raw($b64)
	{
		$s = strtr($b64, '-_', '+/');
		$pad = strlen($s) % 4;
		if ($pad) {
			$s .= str_repeat('=', 4 - $pad);
		}
		return base64_decode($s, true);
	}

	private function rsa_pem_from_mod_exp($n, $e)
	{
		$mod = $this->b64url_raw($n);
		$exp = $this->b64url_raw($e);
		if (!$mod || !$exp) {
			return false;
		}
		$rsa_pub = $this->der_sequence(array(
			$this->der_integer($mod),
			$this->der_integer($exp),
		));
		$bitstr = "\x03" . $this->der_len(strlen($rsa_pub) + 1) . "\x00" . $rsa_pub;
		// AlgorithmIdentifier: rsaEncryption OID 1.2.840.113549.1.1.1 + NULL
		$oid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
		$algid = "\x30" . $this->der_len(strlen($oid) + 2) . $oid . "\x05\x00";
		$spki = "\x30" . $this->der_len(strlen($algid) + strlen($bitstr)) . $algid . $bitstr;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
	}

	private function der_integer($bytes)
	{
		$bytes = ltrim($bytes, "\x00");
		if ('' === $bytes) {
			$bytes = "\x00";
		}
		if ((ord($bytes[0]) & 0x80) !== 0) {
			$bytes = "\x00" . $bytes;
		}
		return "\x02" . $this->der_len(strlen($bytes)) . $bytes;
	}

	private function der_sequence($items)
	{
		$data = implode('', $items);
		return "\x30" . $this->der_len(strlen($data)) . $data;
	}

	private function der_len($len)
	{
		if ($len < 0x80) {
			return chr($len);
		}
		$b = '';
		while ($len > 0) {
			$b = chr($len & 0xff) . $b;
			$len >>= 8;
		}
		return chr(0x80 | strlen($b)) . $b;
	}
}
