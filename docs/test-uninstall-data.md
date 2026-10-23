# Uninstall & Data Test Record — 2.5.2

- 环境：本地隔离站（SQLite drop-in）；执行方式：带 `WP_UNINSTALL_PLUGIN` 引导 `uninstall.php`（wp-cli 的 `plugin delete` 不触发卸载钩子，属已知工具行为）

## 实现核查

- 守卫：`if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;` ✅
- 开关：`at8_mluc_options['delete_data']`（Settings API 注册，checkbox 默认 **0 = 保留**，`sanitize()` 白名单键）✅
- 范围：仅共享键清单（`at8_mluc_user_meta_keys()`）+ `at8_mluc_oauth_`/`at8_mluc_field_` 前缀 LIKE + CPT `at8_mluc_avatar`/`at8_mluc_order` + 双 cron + 自家 transient 前缀 + 本插件 options；多站点走 `switch_to_blog` 逐站处理 ✅
- 停用：deactivate 钩子无任何删除逻辑（grep 证据）✅
- 旧站兼容：键不存在时 `$delete_data = !empty(...)` = false → 保留 ✅

## 测试结果（V4 轮实测 + 本轮复核，逻辑未变）

| # | 用例 | 预期 | 实际 | 结果 |
|---|---|---|---|---|
| 1 | 默认（delete_data=0）卸载 | 数据保留 | options/meta/CPT 原样保留（仅 transient/cron 清理） | **PASS**（代码路径：早 return 分支） |
| 2 | delete_data=1 卸载 | 只删本插件数据 | option ✅、version option ✅、订单 1→0 ✅、头像 3→0 ✅、user meta `at8_mluc_%` 23→0 ✅、限流 transient 0 ✅、双 cron gone ✅（8/8 断言） | **PASS** |
| 3 | 仅停用 | 数据保留 | 停用无删除逻辑 | **PASS** |
| 4 | 旧版本未设置 delete_data 键 | 数据保留（安全默认） | 同用例 1 | **PASS** |
| 5 | 未授权请求触发清理 | 不可能 | 卸载仅在 `WP_UNINSTALL_PLUGIN` + 删除插件时执行，无任何 HTTP 入口 | **PASS** |
| 6 | 多站点 | 逐站清理，不误删其他站 | `is_multisite()` 分支 + switch_to_blog 循环 | **PASS**（代码审计；多站实机 NOT RUN） |
| 7 | 非本插件数据 | 不触碰 | 删除键全部带 `at8_mluc_` 前缀或显式清单；无模糊 `LIKE 'mluc_%'` | **PASS** |
