# Release ZIP Audit — 2.7.0

- 构建日期：2026-10-30
- 构建命令：`python _tools/make_zip.py at8-moonlight-user-center 2.7.0`（从经测试的 `master` 工作区打包）
- 产物：`at8-moonlight-user-center-2.7.0.zip`
- **SHA-256**：`ed20c5cc40f972a6618562feb83232434acc14ddefa65cb1ee9c6ae41796a76f`
- **51 文件 / 184,166 B**

## 核对项

| 项 | 结果 |
|---|---|
| 版本一致（头 / `AT8MLUC_VERSION` / readme Stable tag / changelog） | 全部 2.7.0（zip 内实测） |
| 根目录 | 单一根 `at8-moonlight-user-center/`，主插件文件位于根 |
| 排除项 | `.git/`、`.gitignore`、`README.md`（开发者文档）、`docs/`（审计报告与测试脚本）、隐藏文件、`*.po`/`*.mo`（语言包由 translate.wordpress.org 分发，保留 `.pot`） |
| 无敏感内容 | 无测试密钥 / token / 日志 / Pro 代码（构建脚本排除 + 内容抽查） |
| slug | `at8-moonlight-user-center`（未变更；除新增两张表外数据库键未变更） |
| 运行必需资源 | PHP 类 + 模板 + `assets/css/at8mluc.css` + `assets/js/at8mluc.js` + `assets/js/at8mluc-points-admin.js` + `languages/at8-moonlight-user-center.pot` + LICENSE + uninstall.php 齐全 |
| Plugin Check（2.1.0，ZIP 解压扫描） | **0 ERROR / 25 WARNING**；余下告警均为既有项（`$_POST` 读取、payments 直连查询、`meta_query`），本轮新增的 `class-points.php` 零告警 |
| 安装 | 从该 ZIP `wp plugin install --force` 后版本 2.7.0、`at8mluc_points_db=1`、两张表正常 |
| 升级 | 2.6.5 → 2.6.6：余额 50→50、流水 1→1、幂等键保留、OAuth 绑定可查、升级后变动 55；十二个测试套件全部重跑通过 |
| 卸载（delete_data=1） | 两张表被 DROP、`at8mluc_%` usermeta 归零、`at8mluc_points_db` 删除；核心数据不变（users 26→26、posts 30→30、comments 1→1；2.6.0 轮实测，卸载代码未变） |
| 卸载（默认保留） | 两张表与全部数据保留，仅清运行态（transients / 旧版本遗留的 lock、claim 行） |
| 运行期告警 | 全流程 `debug.log` 增量 0 行 |

## 回归与并发结果（对该 ZIP 安装后实测）

| 套件 | 结果 |
|---|---|
| 事务终审套件 `tx_final_test.php` | 114 / 0 |
| 事务加固套件 `tx_boundary_test.php` | 58 / 0 |
| 事务边界套件 `transaction_test.php` | 45 / 0 |
| 数据库安全套件 `db_safety_test.php` | 47 / 0 |
| 迁移/事务套件 `migration_test.php` | 41 / 0 |
| 后端矩阵 `v4_matrix_test.php` | 50 / 0 |
| 存储层 `atomicity_test.php` | 32 / 0 |
| 并发 10 场景 `concurrency_test.php` | 54 / 0 |
| Free 独立 `reg_test.php` | 76 / 0 |
| Free + Pro `pro_test.php` | 64 / 0 |
| `php -l` 全量 | 0 失败 |

## 本轮 ZIP 内变更

- `includes/class-oauth.php`：回调参数统一入口 `callback_param()` / `callback_user_names()` / `requested_redirect()`；绑定查找改 `find_bound_user()`（usermeta 索引查询）
- `includes/class-payments.php`：两处条件状态更新与两处订单查询补充书面理由
- `readme.txt` / `README.md`：2.6.6 与 changelog
