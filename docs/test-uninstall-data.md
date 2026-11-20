# Uninstall & Data Test Record — 2.5.2

- 环境：本地隔离站（SQLite drop-in）；执行方式：带 `WP_UNINSTALL_PLUGIN` 引导 `uninstall.php`（wp-cli 的 `plugin delete` 不触发卸载钩子，属已知工具行为）

## 实现核查

- 守卫：`if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;` ✅
- 开关：`at8mluc_options['delete_data']`（Settings API 注册，checkbox 默认 **0 = 保留**，`sanitize()` 白名单键）✅
- 范围：仅共享键清单（`at8mluc_user_meta_keys()`）+ `at8mluc_oauth_`/`at8mluc_field_` 前缀 LIKE + CPT `at8mluc_avatar`/`at8mluc_order` + 双 cron + 自家 transient 前缀 + 本插件 options；多站点走 `switch_to_blog` 逐站处理 ✅
- 停用：deactivate 钩子无任何删除逻辑（grep 证据）✅
- 旧站兼容：键不存在时 `$delete_data = !empty(...)` = false → 保留 ✅

## 测试结果（V4 轮实测 + 本轮复核，逻辑未变）

| # | 用例 | 预期 | 实际 | 结果 |
|---|---|---|---|---|
| 1 | 默认（delete_data=0）卸载 | 数据保留 | options/meta/CPT 原样保留（仅 transient/cron 清理） | **PASS**（代码路径：早 return 分支） |
| 2 | delete_data=1 卸载 | 只删本插件数据 | option ✅、version option ✅、订单 1→0 ✅、头像 3→0 ✅、user meta `at8mluc_%` 23→0 ✅、限流 transient 0 ✅、双 cron gone ✅（8/8 断言） | **PASS** |
| 3 | 仅停用 | 数据保留 | 停用无删除逻辑 | **PASS** |
| 4 | 旧版本未设置 delete_data 键 | 数据保留（安全默认） | 同用例 1 | **PASS** |
| 5 | 未授权请求触发清理 | 不可能 | 卸载仅在 `WP_UNINSTALL_PLUGIN` + 删除插件时执行，无任何 HTTP 入口 | **PASS** |
| 6 | 多站点 | 逐站清理，不误删其他站 | `is_multisite()` 分支 + switch_to_blog 循环 | **PASS**（代码审计；多站实机 NOT RUN） |
| 7 | 非本插件数据 | 不触碰 | 删除键全部带 `at8mluc_` 前缀或显式清单；无模糊 `LIKE 'mluc_%'` | **PASS** |

## 补充（2.5.2 验收轮，2026-10-23）

| # | 用例 | 预期 | 实际 | 结果 |
|---|---|---|---|---|
| 8 | Pro 1.x 历史遗留键（`at8mlucp_membership_level/expires`，Free 迁移垫片读取）在 delete_data=1 卸载后 | 一并清理（插件语义拥有其生命周期） | 2 → 0，`at8mluc_%` 总残留 0 | **PASS** |
| 9 | Pro 现版活跃键（`at8mlucp_material_dl_*` 等） | 不受卸载影响（无通配匹配，只按显式键清单删除） | 精确键清单设计，未触碰 | **PASS**（设计审计） |
| 10 | WordPress 核心数据（users/posts/comments）在 delete_data=1 卸载前后 | 完全不变 | 17→17、11→11、1→1 | **PASS** |

## 补充（2.7.6 复核轮，2026-11-20）

上一轮之后卸载逻辑有两处实质变化，本节按新的实现重新实测。

### 实现变化

- 计划任务：原先清的是 `at8mluc_daily_maintenance`——全仓库历史检索确认该钩子**从未被调度过**，只被清理过；真正在跑的 `at8mluc_pay_autoclose`（`register_cpt()` 在 `init` 上按 `order_auto_close_hours` 调度）反而只在 `delete_data=1` 分支里清理。现在两条都在**始终执行**的区块里清理，并额外清 2.7.0 改名前的 `at8_mluc_pay_autoclose`。
- 停用：`deactivate()` 原为空实现。WordPress 停用插件不会移除 cron 条目，于是这个每小时事件会继续为一个没有监听者的钩子烧掉一次运行。现在停用时清除，重新启用后由 `init` 自动补回（已实测：停用后两条都消失，启用后当前前缀那条恢复、旧前缀那条不再复活）。
- 瞬态：原为硬编码清单（`at8mluc_rl_`、`at8mluc_points_lock_`、`at8mluc_points_claim_`、`at8mluc_reg_tk_`），实测漏掉 `at8mluc_buy_rl_*`、`at8mluc_pw_rl_*`、`at8mluc_oauth_state_*`、`at8mluc_prefix_retry`、`at8mluc_admin_notice_*`。现在按名称扫描 `_transient_%at8mluc%` 与 `_transient_%at8_mluc%`，逐个走 `delete_transient()`（持久对象缓存也会失效）。
- usermeta：原按 `at8mluc_oauth_` / `at8mluc_field_` 两个前缀删。现在按 `at8mluc_` / `at8_mluc_` 整体清扫，覆盖旧前缀升级站。
- 表：原来只删当前前缀的两张。现在补上 `at8_mluc_points_ledger` / `at8_mluc_points_account`，表名固定不动态发现（动态名会被 Plugin Check 判为未转义参数）。
- 选项扫描：补上旧前缀 `at8_mluc_`。

### 实测（delete_data=1，预先写入新旧两套前缀数据）

| # | 用例 | 预期 | 实际 | 结果 |
|---|---|---|---|---|
| 11 | Free 自有 options（含 `at8_mluc_options`、`at8_mluc_points_claim_*` 共 15 条） | 全部删除 | 0 | **PASS** |
| 12 | usermeta（`at8mluc_field_*`、`at8_mluc_field_*`、`at8mluc_oauth_*`、`at8_mluc_oauth_*`） | 全部删除 | 0 | **PASS** |
| 13 | 积分表（当前前缀 2 张 + 手工重建的旧前缀 2 张） | 4 张全删 | 0 | **PASS** |
| 14 | 瞬态（`at8mluc_buy_rl_1`、`at8mluc_pw_rl_1`、`at8mluc_oauth_state_abc`、`at8_mluc_oauth_state_old`、`at8mluc_prefix_retry`） | 全部删除 | 0 | **PASS** |
| 15 | Pro 自有数据（`at8mlucp_options`、`at8_mlucp_options`、`at8lic_sdk_*_state`、`at8sdk_verify_*` 事件） | **不受影响**（Pro 有自己的卸载流程） | 3 项选项 + 1 个事件原样保留 | **PASS** |
| 16 | CPT 内容（`at8mluc_avatar`、`at8mluc_order`） | 全部删除 | 0 | **PASS** |
| 17 | 停用（不卸载） | 数据保留，仅清 cron | 账户/账本/订单保留；两条 autoclose 事件清除 | **PASS** |
| 18 | 重新启用 | 当前前缀事件恢复 | `at8mluc_pay_autoclose` 恢复，旧前缀不再出现 | **PASS** |

### 已知边界

- 旧前缀的 `at8_mluc_*` 表只在"从 2.6.x 升级上来"的站点存在；全新站点没有，删不删都是 0。
- `at8sdk_verify_at8-moonlight-user-center-pro` 是 Pro 的许可证校验事件，Free 的卸载**故意**不动它。
