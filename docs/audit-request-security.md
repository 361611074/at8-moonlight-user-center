# Request Security Audit — AT8 Moonlight User Center 2.5.8

- 审计日期：2026-10-23
- 方法：全仓搜索 `$_GET/$_POST/$_REQUEST/$_COOKIE/$_FILES`、`admin_post_`、`wp_ajax_`、`register_rest_route`、`update_option`、用户创建/更新、积分增减、OAuth 回调、导出/删除、设置保存，逐入口核对

## 入口总表

| 入口 | 文件 / 方法 | 公开 | 请求验证 | 能力 / 归属 | 输入验证 | 输出转义 | 结论 |
|---|---|---|---|---|---|---|---|
| at8_mluc_login（nopriv+logged） | class-login.php `handle_login` | 是（登录必需） | 内联 `check_ajax_referer(NONCE_ACTION,...,false)` + `Security::allow('login')` 限流 10/600s | 无（登录流程） | `sanitize_user`；密码只 unslash 不改写；redirect 白名单校验 | send_json 全 esc | ✅ |
| at8_mluc_register（nopriv+logged） | class-register.php `handle_ajax` | 是（注册开启时） | 内联 nonce（专用 action）+ 限流 5/3600s + 注册令牌 transient | 无 | 字段级 sanitize/validate（fields） | 同上 | ✅ |
| at8_mluc_lost_password（nopriv+logged） | class-login.php | 是 | 内联 nonce + 限流 3/900s | 无 | 邮箱 sanitize+is_email | 同上 | ✅ |
| at8_mluc_change_password | class-login.php | 登录用户 | 内联 nonce | `is_user_logged_in` + 当前密码验证 | 密码 unslash | 同上 | ✅ |
| at8_mluc_update_profile | class-profile.php | 登录用户 | 内联 nonce（独立 action） | 本人资料 | 字段级 sanitize | 同上 | ✅ |
| at8_mluc_select_avatar | class-avatar.php | 登录用户 | 内联 nonce | 库内头像 ID 归属校验 | absint | 同上 | ✅ |
| at8_mluc_buy_level | class-payments.php | 登录用户 | nonce（payments flow） | 订单/等级服务端生成 | 等级 slug 白名单 | 同上 | ✅ |
| at8_mluc_paywall_buy | class-paywall.php | 登录用户 | 内联 nonce（专用 action） | 文章/价格服务端 | absint | 同上 | ✅ |
| at8_mluc_adjust_points | class-points.php `ajax_adjust` | 登录用户 | 内联 nonce | `current_user_can('edit_user', $user_id)` | amount int、user_id int | 同上 | ✅ |
| at8_mluc_save_fields | class-fields.php | 管理员 | 内联 nonce | `Security::manage_capability()` | 字段定义逐项 normalize | 同上 | ✅ |
| at8_mluc_redeem_level | class-upgrade(-paths).php | 登录用户 | nonce | 积分余额服务端核算（硬性不透支） | 等级白名单 | 同上 | ✅ |
| at8_mluc_dismiss_upgrade | class-upgrade.php | 登录用户 | nonce | 仅 UI 偏好（user meta） | — | 同上 | ✅ |
| 设置保存（options-general 风格） | class-settings.php | 管理员 | Settings API（core nonce）+ `register_setting` sanitize_callback | `manage_options`（core menu 校验）+ `check_admin_referer` | 每键白名单清洗（absint/checkbox/sanitize_text_field/color…） | 渲染全 esc | ✅ |
| 用户资料屏字段保存 | class-membership.php:570 / class-fields.php:774 | 管理员/本人 | 显式 `check_admin_referer('update-user_'.$id)`（core 已验，二次确认） | `manage_options` / `edit_user` | 按类型 sanitize（membership: key/text/date；fields: 分类型） | 同上 | ✅ |
| OAuth 回调 `?at8_mluc_oauth=provider` | class-oauth.php `maybe_handle_callback` | 是（第三方重定向必需） | **state transient（一次性消费）+ provider 绑定 + 同源 cookie 双因子**（OAuth2 无站点 nonce） | — | code/state/error 全 sanitize；provider 白名单 | 失败走固定文案，不泄露内部错误 | ✅ |
| 素材/视频下载（Pro 侧） | class-material/video.php | 登录用户 | per-post nonce | `Membership::user_can_access` + 限次 | absint + realpath 约束 | 403 固定文案 | ✅ |
| REST API | — | — | **插件未注册任何 REST 路由**（grep `register_rest_route` = 0 命中；Pro 侧 stripe-webhook 仅 Pro 包内且验签） | — | — | — | ✅ N/A |

## 关键原则核对

- **nonce ≠ 授权**：全部状态变更入口在 nonce 之外另有能力/归属校验（积分改动的 `edit_user`、字段保存的 `manage_capability`、订单的 user 归属 meta 比对）
- **公开入口不误加管理权限**：登录/注册/找回/OAuth 均无能力要求，防护改为限流 + state + 白名单
- **输入**：统一 `wp_unslash` 后按类型清洗（absint/sanitize_key/sanitize_email/sanitize_text_field/sanitize_textarea_field/esc_url_raw）；无跨类型一刀切
- **规模限制**：ledger 上限 200 条、订单查询 `posts_per_page` 显式、限流 transient 兜底
- **SQL**：仅 2 条直接 UPDATE（原子认领/取消）均 `$wpdb->prepare` 绑定；其余走 WP API

## 修复证据（本验收周期内）

- `save_profile_fields` / `save_admin_fields` 显式 `check_admin_referer`（消除 NonceVerification 5+1 条且真实二验）
- 积分发放增加订单状态防御（仅 `paid` 发放）
- 全部发现项修复后回归：reg_test 76/0、pro_test 64/0、matrix 37/0
