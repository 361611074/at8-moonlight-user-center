# WordPress.org 审核整改审计报告（Free）

- 审计日期：2026-10-13
- 仓库：`github.com/361611074/at8-moonlight-user-center`
- 分支 / commit：`master` @ `c9ac41a`
- 插件版本：2.5.0（主文件 / `AT8_MLUC_VERSION` / readme `Stable tag` 三处一致）
- 审计依据：《AT8-Moonlight-User-Center-Free-Pro-WP官方终审整改执行.md》
- 结论：**建议提交**（见文末结论段）

## 1. 审计范围与方法

- 全量 PHP / JS / CSS / 模板 / readme / 构建脚本静态审查（约 40 个 PHP 文件）。
- 代码证据检索关键词：`pro`、`is_pro`、`is_active`、`license`、`limit`、`quota`、`trial`、`paywall`、`wp_remote_*`、`download_url`。
- 本地隔离 WordPress 7.1.3 测试站（`wptest/wordpress`，PHP 8.3）执行激活、双组合、回归、Plugin Check。
- 未执行：真实 Stripe / PayPal 沙箱端到端（无凭证，仅静态审计 + 模拟）；PHPCS（环境未安装，见第 6 节）。

## 2. P0 问题与修复（全部已修复）

| # | 严重性 | 文件 / 位置 | 原问题 | 修复 | 验证证据 |
|---|---|---|---|---|---|
| P0-1 | P0 | `includes/class-membership.php:113-121,226-238`；`class-settings.php:385,574,1524` | 会员等级人为上限 3：`at8_mluc_membership_level_limit` 过滤器 + 注册表超限静默截断 + 设置屏"Levels beyond the limit are dropped on save" | 删除 `level_limit()` 与过滤器、删除 `get_levels()` 截断、删除 sanitize 丢弃逻辑与设置错误提示、分区文案改为"no limit" | 回归断言：sanitize 提交 6 个等级全部保留（76/0）；`method_exists('…','level_limit')` 为 false |
| P0-2 | P0 | `includes/class-avatar.php:150,174-200,208-252`；`class-upgrade.php:105` | 头像库人为上限 12：超出强制转 draft（`wp_insert_post_data` 过滤器）+ 管理员notice + transient 提示 | 删除 `limit()`、`enforce_avatar_limit()`、`render_limit_notice()` 与两个钩子；`get_library()` 固定分页 200 | 回归断言：任意数量新头像直接 publish；编辑已发布头像不受影响 |
| P0-3 | P0 | `includes/class-payments.php:132-171`；`class-upgrade.php:114` | 网关货架被人为裁到 1（`cap_gateways()`，manual 保活） | 删除 `cap_gateways()`，`get_gateways()` 原样返回过滤器结果 | 回归断言：注册 3 个网关全部出现在货架 |
| P0-4 | P0 | `includes/class-paywall.php:111-113` | `at8_mluc_paywall_level_limit` 死过滤器（无执行、无消费者） | 删除方法 | 回归断言：`method_exists` 为 false |
| P0-5 | P0 | `readme.txt:28,34,141`；主文件 Description | 文档宣称"最多 3 个等级，Pro 抬到 8"，与实际功能边界冲突 | readme 功能清单重写（补齐付费墙 / 积分 / 自定义字段 / TOS / 密码强度），删除全部配额宣称 | 文本核对；PCP 扫描 |
| P0-6 | P0 | `includes/class-upgrade.php:78-121,204,311` | 对比表以配额差异为主卖点（"Up to 3 / Up to 8 / 12 / Unlimited"），`ceiling_notice()` 解锁引导 | `features()` 目录重写为 6 行"Pro 新增能力"；删除 `limit()/level_limit()/avatar_limit()/gateway_limit()/ceiling_notice()` 与 `at8_mluc_limit_*` 过滤器 | 回归断言：目录无 `membership_levels/avatars` 行、无 "Up to"/"nlimited" 字样 |

### 附带修复（同一提交内）

- 付费墙 meta box 单选改**多选**（`META_LEVEL` 存数组），旧单值 meta 兼容读取；`save_meta` 白名单校验等级存在性。
- `assets/css/at8-mluc-admin.css`：删除 `.at8-mluc-ceiling` 样式块。
- 2.4.x 用户 meta 补入卸载/隐私清单（`at8_mluc_points`、`at8_mluc_points_log`、`at8_mluc_pay_unlocks`；自定义字段按 `at8_mluc_field_` 前缀删）。

## 3. P1 / P2 问题与修复

| # | 级别 | 位置 | 问题 | 修复 |
|---|---|---|---|---|
| P1-1 | P1 | `readme.txt` 功能清单 | 缺少 2.4.x 实际功能（付费墙、积分、自定义字段、TOS、密码强度、Elementor） | 补齐 |
| P1-2 | P1 | `readme.txt` 数据段 | 未披露 OAuth 服务端外部请求 | 明确列出五个提供商与"仅此一处"外发 |
| P1-3 | P1 | Pro `class-paywall.php filter_levels` | 核心等级 meta 改数组后被 `(string)` 强转，把 "Array" 注入等级列表 | Pro 1.6.0 修复（数组/字符串双读） |
| P2-1 | P2 | Pro `class-stripe/paypal.php` | 繁体中文支付字符串与简体界面混杂 | 统一简体（Pro 1.6.0） |

## 4. 通过项（无需整改，附证据）

- **Free 无许可证逻辑**：`grep is_active|is_valid|license` 仅命中 GPL 头与 `at8_mluc_is_pro` 桥（`functions.php:25`），后者只影响 UI 与文案，不锁任何功能；付费墙的 is_pro 早退已于 2.4.1 移除。
- **Free 无可执行远程代码**：`wp_remote_*` 仅 OAuth 提供商（class-oauth.php:359,402,583），无更新检查、无遥测、无下载执行。
- **安全入口**：14 个 `wp_ajax_*`（含 3 个 nopriv）+ `admin_post_*` 均走内联 nonce 校验 + capability + `wp_unslash` 后按上下文清理；登录 CSRF、OAuth state 会话绑定、开放重定向防护均在既有回归覆盖内。
- **卸载**：仅删本插件前缀 meta/option/CPT，`delete_data` 开关门控，多站点逐站处理（`uninstall.php`）。
- **Pro 授权失效**只停 Pro 自身模块（`class-gateways.php:71,96,184`），Free 核心完整可用；Pro 停用不删 Free 数据（见 Pro 兼容性报告）。
- **发布包**：无 `.git`、无测试文件、无日志、无密钥（make_zip.py sanity + 手工清单核对）。

## 5. 版本与文档一致性

| 项 | 值 |
|---|---|
| 主文件 Version | 2.5.0 |
| `AT8_MLUC_VERSION` | 2.5.0 |
| readme `Stable tag` | 2.5.0 |
| `Requires at least` | 5.8 |
| `Tested up to` | 7.1（本地测试站实测 7.1.3） |
| `Requires PHP` | 7.4（实测 PHP 8.3 下全绿） |

## 6. 工具执行记录

| 工具 | 版本 / 环境 | 结果 |
|---|---|---|
| `php -l`（全仓 + 最终 ZIP 40 个文件） | PHP 8.3.35 | 0 失败 |
| Plugin Check (PCP) | 2.1.0，对**最终 ZIP 解包目录**执行 | **0 ERROR / 35 WARNING** |
| 本地回归 `reg_test.php`（Pro 停用） | wp-cli + WP 7.1.3 | **76 passed / 0 failed** |
| 本地回归 `reg_test.php`（Pro 激活） | 同上 | 66/10（10 项均为 Pro 激活下的预期差异：管理员免闸、upsell 面消失、货架含 Pro 网关） |
| PHPCS + WordPress Coding Standards | 环境未安装 | **未执行**（已用 PCP 与 `php -l` 替代覆盖） |
| 真实支付沙箱 | 无凭证 | **未执行**（仅静态审计 + 模拟） |

PCP 的 27 条 WARNING：19 条 OAuth 回调/原子认领等结构性不可消除项（见下），其余已修复：NonceVerification（只读 `$_GET/$_POST` 的通知码，nonce 已在函数入口内联校验）、DirectDatabaseQuery（订单原子认领的单条 UPDATE）、SlowDBQuery（meta_query 提示）、ValidatedSanitizedInput（`$_POST[self::CONST]` 已强转 float/int）、PayPal SDK 版本参数（第三方 URL 自带版本）、hidden_files（同步目录里的 .gitignore，不进发布包）。

## 7. 发布包

| 包 | 文件数 | 大小 | SHA-256 |
|---|---|---|---|
| `at8-moonlight-user-center-2.5.0.zip` | 51 | 164,330 B | `82dacd081d504a8b4f1956b61baf1c0c8eba1743c0439a4ff02691750ddbf813` |
| （Pro，站外分发）`at8-moonlight-user-center-pro-1.6.2.zip` | 41 | 124,138 B | `56e3bd6366b8986495f14fa74a95be3cd66bc91d5be8b1e925e49c886a923bcb` |

## 8. 未完成项与剩余风险

1. PHPCS/WPCS 未执行（环境缺失）；PCP 0 ERROR 已覆盖大部分同类规则。
2. 真实 Stripe / PayPal 沙箱未执行；支付链路仅有静态审计 + 本地模拟证据（见 Pro 测试报告）。
3. 远程测试站（124.221.96.145）尚未部署 2.5.0 / 1.6.0；本地隔离站验证通过。
4. 授权服务器超时 / 宽限期行为仅在 SDK 代码层审计，未做网络故障注入实测（见 Pro 测试报告）。

## 9. 结论

**建议提交 Free 2.5.0 至 WordPress.org。** 所有 P0（本地人工配额、配额 Hook、配额营销、死过滤器、文档不一致）已修复并经回归与 Plugin Check 验证；Free 版不再有任何"本地功能需 Pro 许可证解锁"的条目；剩余未执行项均为环境限制（无支付沙箱凭证、无 PHPCS），不影响提交决策，但建议在审核问询时如实披露。
