# Free / Pro 功能边界矩阵（阶段 A 审计产出）

- 审计日期：2026-10-13
- Free：`at8-moonlight-user-center` @ `16dc959`（v2.4.1）
- Pro：`at8-moonlight-user-center-pro` @ `370baf6`（v1.5.0）
- 审计方式：全量 PHP/JS/模板/readme 静态审查 + 代码证据定位；未做真实支付沙箱（无凭证）。

## 1. 功能矩阵（审计时快照）

| 功能 | 当前实现文件/入口 | Free 审计时行为 | Pro 审计时行为 | 是否依赖授权 | 处理 |
|---|---|---|---|---|---|
| 登录/注册/找回密码/资料 | class-auth.php、templates/login.php 等 | 完整 | 不涉及 | 否 | 保留 |
| 头像库 | class-avatar.php | **人为配额 12**，超出转 draft（`enforce_avatar_limit`） | 过滤器抬到无限 | 是（解除配额） | **P0 移除配额** |
| 会员等级 | class-membership.php、class-settings.php | **人为配额 3**，`get_levels()` 超限截断 | 过滤器抬到 8 | 是（解除配额） | **P0 移除配额** |
| 积分 | class-points.php | 完整 | 不涉及 | 否 | 保留 |
| 自定义字段 | class-fields.php | 完整 | 不涉及 | 否 | 保留 |
| TOS/密码强度 | class-terms.php、assets/js | 完整 | 不涉及 | 否 | 保留 |
| 线下转账订单 | class-payments.php | 完整；**网关货架被人为裁到 1**（`cap_gateways`） | 抬到 3 | 是（解除配额） | **P0 移除裁剪** |
| 付费墙 | class-paywall.php | 完整（每篇 1 个免费阅读等级的 `<select>`；`level_limit()` 死代码） | 富卡片 + 多选免费等级 + 资源 | 是（扩展） | 核心盒改多选，删死过滤器 |
| 隐藏内容 | class-hidecontent.php | logged/reply 完整 | vip1/payshow | 是（Pro 类型） | 保留（Pro 新增类型） |
| 在线支付 PayPal/Stripe | Pro class-paypal/stripe.php | 无入口 | 完整 | 是 | 保留 |
| 差价升级 tiered | Pro class-upgrade-tiers.php | 无 | 完整 | 是 | 保留 |
| 视频/素材/购买记录 | Pro class-video/material/purchases.php | 无 | 完整 | 是 | 保留 |
| License/自动更新 | Pro sdk/ | 无 | SDK 完整 | — | 保留，仅门控 Pro 自身 |
| Elementor 小工具 | Free class-elementor.php | 完整 | 不涉及 | 否 | 保留 |
| OAuth 社交登录 | Free class-oauth.php | 完整（外部请求仅 OAuth 提供商） | 不涉及 | 否 | 保留，readme 披露 |

## 2. Hook 矩阵（Free 对外，Pro 使用）

| Hook | 定义文件 | Pro 用途 | 审计结论 |
|---|---|---|---|
| `at8_mluc_account_tabs` | class-account.php | 挂购买记录 tab | 保留 |
| `at8_mluc_is_pro` | functions.php | 翻转布尔桥 | 保留（仅 UI/文案，不锁功能） |
| `at8_mluc_admin_menu` | 主文件 | 子菜单挂载 | 保留 |
| `at8_mluc_register_settings` / `at8_mluc_settings_extra_fields` | class-settings.php | Online Payment/Orders/Hidden Content 分区 | 保留 |
| `at8_mluc_payment_gateways` | class-payments.php | 注册 paypal/stripe | 保留 |
| `at8_mluc_payment_order_response` | class-payments.php | 替换 manual 响应 | 保留 |
| `at8_mluc_complete_paywall_order` | class-payments.php | 1.5.0 起 Pro 已不使用（核心自claim） | 保留（公共交货点） |
| `at8_mluc_upgrade_paths` | class-upgrade-paths.php | 注册 tiered | 保留 |
| `at8_mluc_paywall_*`（10 个） | class-paywall.php | 字段映射/卡片/资源 | 保留 |
| `at8_mluc_order_price` | class-payments.php | 差价订单 | 保留 |
| `at8_mluc_template_args` / `at8_mluc_ui_label` | functions.php | 模板参数/文案覆盖 | 保留 |
| **`at8_mluc_membership_level_limit`** | class-membership.php | 抬 3→8 | **删除（配额移除）** |
| **`at8_mluc_limit_avatar_count`** | class-upgrade.php | 抬 12→0 | **删除（配额移除）** |
| **`at8_mluc_limit_gateway_count`** | class-upgrade.php | 抬 1→3 | **删除（配额移除）** |
| **`at8_mluc_paywall_level_limit`** | class-paywall.php | 无人使用（死代码） | **删除** |
| `at8_mluc_upgrade_features` | class-upgrade.php | 校正对比表 | 保留（内容重写） |
| `at8_mluc_shortcode_reference` | class-settings.php | Pro 短码速查 | 保留 |
| `at8_mluc_membership_levels` | class-membership.php | 未使用 | 保留 |

## 3. 远程通信清单

**Free**：仅 OAuth 授权/令牌/用户信息三个方向的提供商请求（QQ/Weibo/GitHub/Google/Apple，class-oauth.php），登录功能本身需要，readme 需披露。无授权服务器、无更新检查、无遥测。

**Pro**（详见 docs/PRO_NETWORK_PRIVACY.md）：
- AT8 License SDK（sdk/）：激活/校验/解绑/每日复查，POST www.at8.fun，发送域名、产品 slug、版本、许可证令牌；
- 自动更新（sdk/includes/class-updater.php）：仅 HTTPS、禁跳转、强制证书校验、SHA-256 完整性校验（hash_equals）、401/403/410 令牌处理、失败保留现有安装；
- PayPal/Stripe API：支付业务请求，密钥仅存设置项。

## 4. 文档与代码不一致

| # | 位置 | 问题 | 处理 |
|---|---|---|---|
| D1 | Free readme.txt:28,34,141 | 宣称"最多 3 个等级，Pro 抬到 8" | 配额移除后重写 |
| D2 | Free readme.txt 功能清单 | 缺少 2.4.x 实际功能：付费墙、积分、自定义字段、TOS、密码强度 | 补齐 |
| D3 | Free readme.txt 数据段 | 未披露 OAuth 外部请求 | 补隐私说明 |
| D4 | Free 主文件 Description | 未提付费墙/积分 | 同步 |
| D5 | Pro readme.txt:11,19,43,64,75 | "beyond the free cap"/"lifts the cap"营销语 | 删除并重写为"新增模块" |
| D6 | Pro class-stripe/paypal 若干字符串 | 繁体中文（"訂單無效"等）与简体界面混杂 | 统一简体 |

## 5. P0/P1/P2 问题清单（含证据）

| 级别 | 问题 | 证据 |
|---|---|---|
| P0-1 | Free 会员等级人为上限 3，超限静默截断 | class-membership.php:121,226-238；class-settings.php:385,1524 |
| P0-2 | Free 头像人为上限 12，超出强制转 draft | class-avatar.php:150,174-200；class-upgrade.php:105 |
| P0-3 | Free 网关货架被人为裁到 1 | class-payments.php:132-150；class-upgrade.php:114 |
| P0-4 | Pro 以"解除 Free 配额"为核心卖点（违反 guideline 6/8） | Pro at8-moonlight-user-center-pro.php:178-206；readme.txt:11,19,64,75 |
| P0-5 | `at8_mluc_paywall_level_limit` 死过滤器（无人执行/无人使用） | class-paywall.php:111-113 |
| P1-1 | Free readme 功能清单陈旧（缺 paywall/points/fields/TOS） | readme.txt:20-33 |
| P1-2 | Free readme 未披露 OAuth 外部请求 | readme.txt "How it handles your data" |
| P1-3 | Pro 对比表以配额差异为主卖点 | class-upgrade.php:120-205 features() |
| P1-4 | Free 设置屏"最多 N 个等级"提示依赖配额 | class-settings.php:385-395,574 |
| P2-1 | Pro 繁体字符串混杂 | class-stripe.php:244,252 等 |
| P2-2 | `at8_mluc_upgrade_features` 过滤器随配额移除失去原语义 | class-upgrade.php:204 |

## 6. 通过项（无需整改）

- Free 无许可证逻辑、无远程可执行代码下载；`at8_mluc_is_pro` 仅影响 UI 与文案，不锁 Free 功能（付费墙 is_pro 早退已在 2.4.1 移除）。
- Pro 授权失效仅停 Pro 自身模块（class-gateways.php:71,96,184），Free 核心完整可用；Pro 停用不删 Free 数据。
- Stripe webhook：HMAC-SHA256 验签 + 5 分钟时间容差 + livemode 一致性 + 服务端回查会话；PayPal capture：custom_id/金额/归属校验；均不信任前端成功回跳。
- 订单发放原子化（claim_order 单条 UPDATE），重复 webhook/回调不会双重发放。
- OAuth state 随机且与浏览器会话绑定，防登录 CSRF。
- 卸载仅删本插件前缀 meta/option/CPT（uninstall.php），2.4.x 用户 meta 已纳入清单。
- 发布 ZIP 无 .git/测试文件/日志（已核 52/42 文件清单）。

## 7. 结论与最终状态（2026-10-13 整改后）

阶段 A 判定时存在 5 项 P0。**已全部修复并验证**（详见 `WORDPRESS_ORG_AUDIT.md`）：

- Free 2.5.0（commit `c9ac41a`）：三处人工配额（等级 3 / 头像 12 / 网关 1）与死过滤器全部移除；付费墙盒支持多选免费阅读等级；对比表只描述 Pro 新增能力；readme 补齐真实功能与隐私披露。
- Pro 1.6.0（commit `eef731a`）：删除三个抬升过滤器与全部"解除上限"营销话术；依赖 Free 2.5.0；修复多选 meta 映射与繁简统一。
- 验收：功能矩阵中**不存在**"Free 本地具备功能但需 Pro 许可证/购买后才可使用"的条目；`at8_mluc_is_pro` 仅影响 UI/文案。
- 回归：Free 76/0（Pro 停用）、Pro 61/0（Pro 激活+授权）、Plugin Check 对最终 ZIP **0 ERROR**。
