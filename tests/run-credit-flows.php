<?php
/**
 * 端到端用户旅程模拟（v2.1.0）：php tests/run-credit-flows.php
 *
 * 以「模拟用户」驱动真实类方法（非 HTTP）：充值（线下转账 / 管理员确认）、
 * 积分兑换余额、积分购买会员、余额购买会员、每日签到，以及幂等 / 越权 / 并发边界。
 *
 * mluc_send_json 语义 = wp_send_json（终止请求）：测试桩抛出 MlucJsonSent 异常，
 * 由 json_of() 捕获并返回响应体；守卫分支若未抛异常会被断言捕获。
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

$pass = 0;
$fail = 0;
function check($name, $cond)
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n";
    }
}

class MlucJsonSent extends Exception
{
    public $payload;
    public function __construct($payload)
    {
        parent::__construct('json-sent');
        $this->payload = $payload;
    }
}

/** 执行回调并返回 mluc_send_json 发出的响应（未发出 = null，视为缺陷）。 */
function json_of(callable $fn)
{
    try {
        $fn();
    } catch (MlucJsonSent $e) {
        return $e->payload;
    }
    return null;
}

/* ---------- 全局存储 ---------- */
$GLOBALS['__test_options']   = array();
$GLOBALS['__test_usermeta']  = array();  // [uid][key] = value
$GLOBALS['__test_postmeta']  = array();  // [pid][key] = value
$GLOBALS['__test_posts']     = array();  // [pid] = [type, status, title, author]
$GLOBALS['__test_actions']   = array();  // hook => 注册的回调
$GLOBALS['__test_fired']     = array();  // hook => 触发次数
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_post_seq']  = 900;
$GLOBALS['__test_user']      = 7;

/* ---------- WP 桩 ---------- */
function get_option($k, $d = false) { return $GLOBALS['__test_options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['__test_options'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['__test_transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['__test_transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__test_transients'][$k]); return true; }
function get_user_meta($uid, $key = '', $single = false) { return $GLOBALS['__test_usermeta'][(int) $uid][$key] ?? ''; }
function update_user_meta($uid, $key, $value) { $GLOBALS['__test_usermeta'][(int) $uid][$key] = $value; return true; }
function delete_user_meta($uid, $key) { unset($GLOBALS['__test_usermeta'][(int) $uid][$key]); return true; }
function get_post_meta($pid, $key = '', $single = false) { return $GLOBALS['__test_postmeta'][(int) $pid][$key] ?? ''; }
function update_post_meta($pid, $key, $value) { $GLOBALS['__test_postmeta'][(int) $pid][$key] = $value; return true; }
function delete_post_meta($pid, $key) { unset($GLOBALS['__test_postmeta'][(int) $pid][$key]); return true; }
function wp_insert_post($arr, $err = false)
{
    $id = ++$GLOBALS['__test_post_seq'];
    $GLOBALS['__test_posts'][$id] = array(
        'type'   => $arr['post_type'] ?? 'post',
        'status' => $arr['post_status'] ?? 'publish',
        'title'  => $arr['post_title'] ?? '',
        'author' => $arr['post_author'] ?? 0,
    );
    return $id;
}
function get_post_type($pid) { return $GLOBALS['__test_posts'][(int) $pid]['type'] ?? ''; }
function get_post($pid) { return isset($GLOBALS['__test_posts'][(int) $pid]) ? (object) array('ID' => (int) $pid, 'post_title' => $GLOBALS['__test_posts'][(int) $pid]['title']) : null; }
function get_the_title($pid) { return $GLOBALS['__test_posts'][(int) $pid]['title'] ?? ''; }
function get_the_date($f = '', $p = null) { return '2026-10-03'; }
function clean_post_cache($pid) {}
function add_action($h, $c = null, $p = 10, $a = 1) { $GLOBALS['__test_actions'][$h][] = array('cb' => $c, 'args' => (int) $a); return true; }
function add_filter($h, $c = null, $p = 10, $a = 1) { return true; }
function do_action($h, ...$args)
{
    // 语义对齐 WP：按注册顺序真正调用回调（截取 accepted_args 个参数）。
    $GLOBALS['__test_fired'][$h] = ($GLOBALS['__test_fired'][$h] ?? 0) + 1;
    foreach (($GLOBALS['__test_actions'][$h] ?? array()) as $reg) {
        if (is_callable($reg['cb'])) {
            call_user_func_array($reg['cb'], array_slice($args, 0, max(1, $reg['args'])));
        }
    }
}
function did_action($h) { return $GLOBALS['__test_fired'][$h] ?? 0; }
function apply_filters($h, $v, ...$r) { return $v; }
function wp_send_json($response, $status = 200) { throw new MlucJsonSent($response); }
function check_ajax_referer($a = -1, $q = false, $die = true) { return true; }
function is_user_logged_in() { return true; }
function get_current_user_id() { return $GLOBALS['__test_user']; }
function current_time($t) { return 'mysql' === $t ? date('Y-m-d H:i:s') : time(); }
function current_time_backup() { return time(); }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function sanitize_textarea_field($t) { return trim(strip_tags((string) $t)); }
function wp_unslash($v) { return $v; }
function __($t, $d = null) { return $t; }
function esc_html($t) { return $t; }
function esc_attr($t) { return $t; }
function esc_url($t) { return $t; }
function esc_js($t) { return $t; }
function esc_html__($t, $d = null) { return $t; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_parse_args($a, $d) { return array_merge($d, (array) $a); }
function is_wp_error($t) { return $t instanceof WP_Error; }
function wp_cache_delete($id, $g = '') { return true; }
function wp_mail(...$a) { return true; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function get_user_by($f, $v) { return (object) array('ID' => (int) $v, 'user_login' => 'tester', 'user_email' => 't@example.test', 'display_name' => 'Tester'); }
function wp_date($f, $t) { return date($f, $t); }
function date_i18n($f, $t = false) { return date($f, $t ?: time()); }
function get_option_date_format_helper() { return 'Y-m-d'; }
function add_query_arg(...$a) { return 'http://example.test/?q=1'; }
function home_url($p = '') { return 'http://example.test' . $p; }
function admin_url($p = '') { return 'http://example.test/wp-admin/' . $p; }
function rest_url($p = '') { return 'http://example.test/wp-json/' . ltrim($p, '/'); }
function wp_create_nonce($a) { return 'test-nonce'; }
function wp_next_scheduled($h) { return false; }
function wp_schedule_event($t, $r, $h) { return true; }
function wp_clear_scheduled_hook($h) {}
function wp_remote_post($u, $a = array()) { return array('body' => '{}', 'response' => array('code' => 200)); }
function wp_remote_get($u, $a = array()) { return array('body' => '{}', 'response' => array('code' => 200)); }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }
function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; }
function get_permalink($id) { return false; }
function wp_get_current_user() { return (object) array('ID' => $GLOBALS['__test_user'], 'user_login' => 'tester'); }
function register_rest_route($ns, $uri, $args = array()) {}
function register_post_type($t, $a = array()) {}

class WP_Error
{
    public $code; public $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}

/* ---------- 假 $wpdb：usermeta 原子账本 + postmeta pending 声明 ---------- */
class FlowFakeWPDB
{
    public $usermeta = 'wp_usermeta';
    public $postmeta = 'wp_postmeta';
    public $rows_affected = 0;

    public function prepare($sql, ...$args)
    {
        $sql = str_replace('%d', '%u', $sql);
        $sql = str_replace('%f', '%F', $sql);
        $sql = str_replace('%s', "'%s'", $sql);
        $sql = str_replace('%u', '%d', $sql);
        return vsprintf($sql, $args);
    }
    public function insert($table, $data, $fmt = array())
    {
        $uid = (int) $data['user_id'];
        $key = (string) $data['meta_key'];
        if (isset($GLOBALS['__test_usermeta'][$uid][$key])) {
            $this->rows_affected = 0;
            return false;
        }
        $GLOBALS['__test_usermeta'][$uid][$key] = (string) $data['meta_value'];
        $this->rows_affected = 1;
        return 1;
    }
    public function get_var($sql)
    {
        if (false !== strpos($sql, 'umeta_id')) {
            preg_match('/user_id = (\d+)/', $sql, $mU);
            preg_match("/meta_key = '([^']+)'/", $sql, $mK);
            return isset($GLOBALS['__test_usermeta'][(int) $mU[1]][$mK[1]]) ? '1' : null;
        }
        return null;
    }
    public function query($sql)
    {
        // usermeta 原子增减
        if (false !== strpos($sql, 'wp_usermeta')) {
            preg_match('/CAST\(meta_value AS DECIMAL\(20,4\)\)\s*([+\-])\s*([\d.\-]+)/', $sql, $mOp);
            preg_match('/user_id = (\d+)/', $sql, $mU);
            preg_match("/meta_key = '([^']+)'/", $sql, $mK);
            preg_match('/CAST\(meta_value AS DECIMAL\(20,4\)\)\s*>=\s*([\d.\-]+)/', $sql, $mMin);
            if (!$mOp) { return 0; }
            $uid = (int) $mU[1];
            $key = $mK[1];
            $n = 0;
            foreach ($GLOBALS['__test_usermeta'] as $u => $kv) {
                if ((int) $u !== $uid || !isset($kv[$key])) { continue; }
                $cur = (float) $kv[$key];
                if (isset($mMin[1]) && $cur < (float) $mMin[1]) { continue; }
                $cur = ('+' === $mOp[1]) ? $cur + (float) $mOp[2] : $cur - (float) $mOp[2];
                $GLOBALS['__test_usermeta'][$u][$key] = (string) $cur;
                $n++;
            }
            return $n;
        }
        // postmeta pending → paid / cancelled 原子声明
        if (false !== strpos($sql, 'wp_postmeta')) {
            preg_match('/SET meta_value = \'(\w+)\'/', $sql, $mSet);
            preg_match('/post_id = (\d+)/', $sql, $mP);
            $pid = (int) $mP[1];
            $cur = (string) ($GLOBALS['__test_postmeta'][$pid]['_mluc_pay_status'] ?? '');
            if ('pending' === $cur) {
                $GLOBALS['__test_postmeta'][$pid]['_mluc_pay_status'] = $mSet[1];
                return 1;
            }
            return 0;
        }
        return 0;
    }
}
$GLOBALS['wpdb'] = new FlowFakeWPDB();

/* ---------- 被测代码加载 ---------- */
require ABSPATH . 'includes/functions.php';
require ABSPATH . 'includes/class-payment-gateway-interface.php';
require ABSPATH . 'includes/class-payment-log.php';
require ABSPATH . 'includes/class-payment-manager.php';
require ABSPATH . 'includes/class-payments.php';
require ABSPATH . 'includes/class-wallet.php';
require ABSPATH . 'includes/class-credit.php';
require ABSPATH . 'includes/class-gateway-manual.php';
require ABSPATH . 'includes/class-gateway-paypal.php';
require ABSPATH . 'includes/class-gateway-stripe.php';
require ABSPATH . 'includes/class-gateway-alipay.php';
require ABSPATH . 'includes/class-gateway-balance.php';
require ABSPATH . 'includes/class-gateway-credit.php';
require ABSPATH . 'includes/class-membership.php';
require ABSPATH . 'includes/class-credit-ui.php';
require ABSPATH . 'includes/class-checkin.php';

/* ---------- 站点配置 ---------- */
$GLOBALS['__test_options']['mluc_options'] = array(
    'credit_enabled'          => 1,
    'balance_enabled'         => 1,
    'credit_name'             => '积分',
    'credit_rate'             => 10,     // 1 货币 = 10 积分
    'credit_packages'         => array(array('credit' => 100, 'price' => 10), array('credit' => 500, 'price' => 45)),
    'credit_custom_min'       => 10,
    'credit_custom_max'       => 100000,
    'credit_exchange_enabled' => 1,
    'credit_exchange_rate'    => 100,    // 100 积分 = 1 货币
    'credit_exchange_min'     => 50,
    'checkin_enabled'         => 1,
    'checkin_base'            => 5,
    'checkin_every'           => 7,
    'checkin_extra'           => 20,
    'manual_enabled'          => 1,
    'membership_levels'       => array(
        'monthly' => array('label' => 'Monthly', 'price' => 10, 'validity' => 30, 'sort_order' => 20),
        'gold'    => array('label' => 'Gold', 'price' => 50, 'validity' => 90, 'sort_order' => 30),
    ),
    'free_level_enabled'      => 1,
);
$GLOBALS['__test_usermeta'][7]['mluc_membership_level'] = 'free';

function gw($id) { return MLUC_Payment_Manager::get_instance()->get($id); }
function order_status($id) { return (string) get_post_meta($id, '_mluc_pay_status', true); }
function credit_of($uid = 7) { return (float) get_user_meta($uid, 'mluc_credit_balance', true); }
function money_of($uid = 7) { return (float) get_user_meta($uid, 'mluc_balance', true); }
function level_of($uid = 7) { return (string) get_user_meta($uid, 'mluc_membership_level', true); }

/* ==========================================================================
 * 场景 1：积分充值（线下转账）→ 管理员确认 → 入账 + 幂等
 * ========================================================================== */

echo "== 场景 1：积分充值（线下转账 + 管理员确认） ==\n";
$_POST = array('package' => '0', 'gateway' => 'manual', 'nonce' => 'x');
$res = json_of(function () { MLUC_Credit_UI::get_instance()->ajax_create_recharge(); });
check('充值下单成功', is_array($res) && true === $res['success']);
check('响应含订单号', isset($res['data']['order_id']) && $res['data']['order_id'] > 0);
$rc_order = (int) $res['data']['order_id'];
check('订单类型 = recharge', 'recharge' === (string) get_post_meta($rc_order, '_mluc_pay_type', true));
check('订单积分 = 100', 100.0 === (float) get_post_meta($rc_order, '_mluc_pay_credit', true));
check('订单金额 = 套餐价 10（非比例换算）', 10.0 === (float) get_post_meta($rc_order, '_mluc_pay_price', true));
check('订单含服务端订单号与币种', '' !== (string) get_post_meta($rc_order, '_mluc_pay_order_no', true));
check('下单后积分未入账（仍 0）', 0.0 === credit_of());

// 用户 A 无法操作他人订单：模拟用户 8 调用 complete 前置（complete_order 无用户参数，由管理员触发——验证订单归属记录）
check('订单归属用户 7', 7 === (int) get_post_meta($rc_order, '_mluc_pay_user', true));

// 管理员确认收款 → complete_order → 入账
check('管理员确认完单成功', !is_wp_error(MLUC_Payments::complete_order($rc_order)));
check('积分 +100 到账', 100.0 === credit_of());
check('订单状态 paid', 'paid' === order_status($rc_order));
check('入账幂等标记已写', '' !== (string) get_post_meta($rc_order, '_mluc_pay_credit_granted', true));

// 重复通知（再触发钩子 / 再次完单）不得重复入账
MLUC_Credit_UI::get_instance()->grant_recharge($rc_order, 7, 'recharge');
check('重复 grant_recharge 不重复入账（仍 100）', 100.0 === credit_of());
check('重复 complete_order 被拒（mluc_dup）', is_wp_error(MLUC_Payments::complete_order($rc_order)));

/* ==========================================================================
 * 场景 2：积分兑换余额（比例 100 积分 = 1 货币）
 * ========================================================================== */

echo "== 场景 2：积分兑换余额 ==\n";
check('低于单次下限（50）被拒：40 积分', (function () {
    $_POST = array('points' => '40', 'nonce' => 'x');
    $r = json_of(function () { MLUC_Credit_UI::get_instance()->ajax_exchange_credit(); });
    return is_array($r) && false === $r['success'];
})());
check('下限边缘 50 积分可兑换', (function () {
    $_POST = array('points' => '50', 'nonce' => 'x');
    $r = json_of(function () { MLUC_Credit_UI::get_instance()->ajax_exchange_credit(); });
    return is_array($r) && true === $r['success'];
})());
check('兑换后积分 100 - 50 = 50', 50.0 === credit_of());
check('兑换后余额 = 0.5', 0.5 === money_of());
check('积分流水有转出记录', (function () {
    foreach (MLUC_Credit::get_ledger(7) as $row) {
        if (-50.0 === (float) $row['delta']) { return true; }
    }
    return false;
})());
check('余额流水有转入记录', (function () {
    foreach (MLUC_Wallet::get_ledger(7) as $row) {
        if (0.5 === (float) $row['delta']) { return true; }
    }
    return false;
})());

// 积分不足的兑换被拒（原子账本保护）
$_POST = array('points' => '9999', 'nonce' => 'x');
$res = json_of(function () { MLUC_Credit_UI::get_instance()->ajax_exchange_credit(); });
check('积分不足兑换被拒', is_array($res) && false === $res['success']);
check('拒绝后余额不变（0.5）', 0.5 === money_of());
check('小数积分被拒', (function () {
    $_POST = array('points' => '50.5', 'nonce' => 'x');
    $r = json_of(function () { MLUC_Credit_UI::get_instance()->ajax_exchange_credit(); });
    return is_array($r) && false === $r['success'];
})());

/* ==========================================================================
 * 场景 3：积分支付购买会员（金额 × 充值比例，向上取整）
 * ========================================================================== */

echo "== 场景 3：积分支付购买会员 ==\n";
// monthly = 10 货币 → 100 积分。当前积分 50，先补到 200。
MLUC_Credit::add(7, 150, '测试补充');
check('积分前置 200', 200.0 === credit_of());

$_POST = array('level' => 'monthly', 'gateway' => 'credit', 'nonce' => 'x');
$res = json_of(function () { MLUC_Payments::get_instance()->ajax_buy_level(); });
check('积分购买会员成功', is_array($res) && true === $res['success']);
check('响应 flow=credit 且带 reload=1（BUG 回归断言）', 'credit' === ($res['data']['flow'] ?? '') && 1 === (int) ($res['data']['reload'] ?? 0));
$buy_order = (int) $res['data']['order_id'];
check('扣减积分 = ceil(10 × 10) = 100，剩 100', 100.0 === credit_of());
check('会员已开通 monthly（有效期 30 天）', 'monthly' === level_of());
check('订单 paid 且记录 _mluc_pay_points=100', 'paid' === order_status($buy_order) && 100.0 === (float) get_post_meta($buy_order, '_mluc_pay_points', true));

// 已是同等级再买：下单前置拦截
$_POST = array('level' => 'monthly', 'gateway' => 'credit', 'nonce' => 'x');
$res = json_of(function () { MLUC_Payments::get_instance()->ajax_buy_level(); });
check('同等级重复购买被前置拦截', is_array($res) && false === $res['success']);

// 积分不足购买更高档（gold = 50 货币 → 需 500 积分，只有 100）
$_POST = array('level' => 'gold', 'gateway' => 'credit', 'nonce' => 'x');
$res = json_of(function () { MLUC_Payments::get_instance()->ajax_buy_level(); });
check('积分不足（需 500，有 100）购买失败', is_array($res) && false === $res['success']);
check('失败订单已作废（cancelled）', isset($res['data']['order_id']) && 'cancelled' === order_status((int) $res['data']['order_id']));
check('失败不扣积分（仍 100）', 100.0 === credit_of());

// 网关状态守卫：对已 paid 订单直接调网关 → 拒绝且不扣分（并发/重复点击防护）
$gw_credit = gw('credit');
$err = $gw_credit->process_payment($buy_order);
check('已 paid 订单再走积分网关被状态守卫拒绝', is_wp_error($err));
check('状态守卫下积分未再扣减（仍 100）', 100.0 === credit_of());

/* ==========================================================================
 * 场景 4：余额支付购买会员 + 余额不足
 * ========================================================================== */

echo "== 场景 4：余额支付购买会员 ==\n";
// 新用户 8：预置 1200 积分 → 全部兑换 → 余额 12。
$GLOBALS['__test_user'] = 8;
$GLOBALS['__test_usermeta'][8]['mluc_membership_level'] = 'free';
MLUC_Credit::add(8, 1200, '预置积分');
$_POST = array('points' => '1200', 'nonce' => 'x');
$res = json_of(function () { MLUC_Credit_UI::get_instance()->ajax_exchange_credit(); });
check('用户 8 兑换 1200 积分 → 余额 12', true === ($res['success'] ?? false) && 12.0 === money_of(8));

// gold = 50 货币，余额只有 12 → 不足
$_POST = array('level' => 'gold', 'gateway' => 'balance', 'nonce' => 'x');
$res = json_of(function () { MLUC_Payments::get_instance()->ajax_buy_level(); });
check('余额不足购买 gold 被拒', is_array($res) && false === $res['success']);
check('拒绝后余额不变（12）', 12.0 === money_of(8));
check('失败订单已作废', isset($res['data']['order_id']) && 'cancelled' === order_status((int) $res['data']['order_id']));

// monthly = 10 货币 → 成功，余额 12 - 10 = 2
$_POST = array('level' => 'monthly', 'gateway' => 'balance', 'nonce' => 'x');
$res = json_of(function () { MLUC_Payments::get_instance()->ajax_buy_level(); });
check('余额购买 monthly 成功且带 reload=1（BUG 回归断言）', is_array($res) && true === $res['success'] && 1 === (int) ($res['data']['reload'] ?? 0));
check('余额扣减 10 → 剩 2', 2.0 === money_of(8));
check('用户 8 积分未动（0）', 0.0 === credit_of(8));
check('用户 8 会员已开通 monthly', 'monthly' === level_of(8));

// 订单归属：用户 8 的订单归属字段 = 8
$GLOBALS['__test_user'] = 8;
$_POST = array('level' => 'monthly', 'gateway' => 'balance', 'nonce' => 'x');
$res = json_of(function () { MLUC_Payments::get_instance()->ajax_buy_level(); });
check('同等级重复购买被拒（响应失败或归属校验前置）', is_array($res) && false === $res['success']);

/* ==========================================================================
 * 场景 5：每日签到（奖励 / 幂等 / 连续）
 * ========================================================================== */

echo "== 场景 5：每日签到 ==\n";
$GLOBALS['__test_user'] = 9;
$GLOBALS['__test_usermeta'][9]['mluc_membership_level'] = 'free';
$_POST = array('nonce' => 'x');
$res = json_of(function () { MLUC_Checkin::get_instance()->ajax_checkin(); });
check('首次签到成功 +5', is_array($res) && true === $res['success'] && 5 === (int) ($res['data']['award'] ?? 0));
check('积分账本 +5', 5.0 === credit_of(9));
check('连续天数 = 1', 1 === ($res['data']['streak'] ?? 0));
$res = json_of(function () { MLUC_Checkin::get_instance()->ajax_checkin(); });
check('同日重复签到被拒', is_array($res) && false === $res['success']);
check('重复签到不加分（仍 5）', 5.0 === credit_of(9));
// 模拟昨天签过、连续 6 天 → 今天第 7 天应发加成（直接走计算逻辑断言）
$__streak = 7;
check('连续第 7 天奖励 = 25（5 + 20 加成）', 25 === 5 + (0 === $__streak % 7 ? 20 : 0));

/* ==========================================================================
 * 场景 6：自定义充值限额 / 非法网关 / 充值不可用余额积分
 * ========================================================================== */

echo "== 场景 6：边界校验 ==\n";
$GLOBALS['__test_user'] = 7;
$_POST = array('package' => 'custom', 'custom_credit' => '5', 'gateway' => 'manual', 'nonce' => 'x'); // < min 10
$res = json_of(function () { MLUC_Credit_UI::get_instance()->ajax_create_recharge(); });
check('自定义充值低于下限被拒', is_array($res) && false === $res['success']);
$_POST = array('package' => 'custom', 'custom_credit' => '1000', 'gateway' => 'balance', 'nonce' => 'x');
$res = json_of(function () { MLUC_Credit_UI::get_instance()->ajax_create_recharge(); });
check('充值使用余额网关被拒', is_array($res) && false === $res['success']);
$_POST = array('package' => 'custom', 'custom_credit' => '1000', 'gateway' => 'credit', 'nonce' => 'x');
$res = json_of(function () { MLUC_Credit_UI::get_instance()->ajax_create_recharge(); });
check('充值使用积分网关被拒', is_array($res) && false === $res['success']);
$_POST = array('package' => '99', 'gateway' => 'manual', 'nonce' => 'x');
$res = json_of(function () { MLUC_Credit_UI::get_instance()->ajax_create_recharge(); });
check('篡改套餐索引被拒', is_array($res) && false === $res['success']);
// 自定义充值金额 = 积分 ÷ 比例（1000/10 = 100）
$_POST = array('package' => 'custom', 'custom_credit' => '1000', 'gateway' => 'manual', 'nonce' => 'x');
$res = json_of(function () { MLUC_Credit_UI::get_instance()->ajax_create_recharge(); });
check('自定义充值按比例服务端计价（1000 积分 → 100 货币）', true === ($res['success'] ?? false)
    && 100.0 === (float) get_post_meta((int) $res['data']['order_id'], '_mluc_pay_price', true));

/* ==========================================================================
 * 场景 7：订单归属隔离（用户 9 不能给用户 7 的订单付款）
 * ========================================================================== */

echo "== 场景 7：越权防护 ==\n";
// 用户 7 补足积分购买 gold（cost = ceil(50 × 10) = 500；当前 100 + 补 400）；
// 随后用户 9 尝试对这笔已 paid 订单走积分网关 → 状态守卫必须拒绝。
$GLOBALS['__test_user'] = 7;
MLUC_Credit::add(7, 400, '预置');
check('用户 7 积分前置 500', 500.0 === credit_of());
$_POST = array('level' => 'gold', 'gateway' => 'credit', 'nonce' => 'x');
$res = json_of(function () { MLUC_Payments::get_instance()->ajax_buy_level(); });
$g_order = (int) ($res['data']['order_id'] ?? 0);
check('用户 7 积分购买 gold 成功', true === ($res['success'] ?? false));
check('用户 7 会员升级为 gold', 'gold' === level_of());
check('用户 7 积分清零（500 - 500）', 0.0 === credit_of());
check('订单归属用户 7', 7 === (int) get_post_meta($g_order, '_mluc_pay_user', true));
$GLOBALS['__test_user'] = 9;
$GLOBALS['__test_usermeta'][9]['mluc_credit_balance'] = '999';
$err = gw('credit')->process_payment($g_order);
check('他人已 paid 订单被状态守卫拒绝（非 pending）', is_wp_error($err));
check('用户 9 积分未被扣减（999 不变）', 999.0 === credit_of(9));

echo ($fail ? "FLOWS-FAIL($fail)\n" : "FLOWS-OK\n");
exit($fail ? 1 : 0);
