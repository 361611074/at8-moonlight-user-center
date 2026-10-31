# Release ZIP Audit — 2.6.2

- 构建日期：2026-10-30
- 构建命令：`python _tools/make_zip.py at8-moonlight-user-center 2.6.2`（从经测试的 `master` 工作区打包）
- 产物：`at8-moonlight-user-center-2.6.2.zip`
- **SHA-256**：`0fbbfd0d4440cac93666b2d22b7777d2c9f9f92148a935c3bc10994efc4e7265`
- **50 文件 / 170,860 B**

## 核对项

| 项 | 结果 |
|---|---|
| 版本一致（头 / `AT8_MLUC_VERSION` / readme Stable tag / changelog） | 全部 2.6.2（zip 内实测） |
| 根目录 | 单一根 `at8-moonlight-user-center/`，主插件文件位于根 |
| 排除项 | `.git/`、`.gitignore`、`README.md`（开发者文档）、`docs/`（审计报告与测试脚本）、隐藏文件、`*.po`/`*.mo`（语言包由 translate.wordpress.org 分发，保留 `.pot`） |
| 无敏感内容 | 无测试密钥 / token / 日志 / Pro 代码（构建脚本排除 + 内容抽查） |
| slug | `at8-moonlight-user-center`（未变更；除新增两张表外数据库键未变更） |
| 运行必需资源 | PHP 类 + 模板 + `assets/css/at8-mluc.css` + `assets/js/at8-mluc.js` + `assets/js/at8-mluc-points-admin.js` + `languages/at8-moonlight-user-center.pot` + LICENSE + uninstall.php 齐全 |
| Plugin Check（2.1.0，ZIP 解压扫描） | **0 ERROR / 25 WARNING**；余下告警均为既有项（`$_POST` 读取、payments 直连查询、`meta_query`），本轮新增的 `class-points.php` 零告警 |
| 安装 | 从该 ZIP `wp plugin install --force` 后版本 2.6.2、`at8_mluc_points_db=1`、两张表正常 |
| 升级 | 2.6.1 → 2.6.2 覆盖安装后：现存 3 个账户 `verify_balance` 全部通过（合计 1647 分），六个测试套件全部重跑通过 |
| 卸载（delete_data=1） | 两张表被 DROP、`at8_mluc_%` usermeta 归零、`at8_mluc_points_db` 删除；核心数据不变（users 26→26、posts 30→30、comments 1→1；2.6.0 轮实测，卸载代码未变） |
| 卸载（默认保留） | 两张表与全部数据保留，仅清运行态（transients / 旧版本遗留的 lock、claim 行） |
| 运行期告警 | 全流程 `debug.log` 增量 0 行 |

## 回归与并发结果（对该 ZIP 安装后实测）

| 套件 | 结果 |
|---|---|
| 数据库安全套件 `db_safety_test.php` | 47 / 0 |
| 迁移/事务套件 `migration_test.php` | 41 / 0 |
| 后端矩阵 `v4_matrix_test.php` | 50 / 0 |
| 存储层 `atomicity_test.php` | 32 / 0 |
| 并发 10 场景 `concurrency_test.php` | 54 / 0 |
| Free 独立 `reg_test.php` | 76 / 0 |
| Free + Pro `pro_test.php` | 64 / 0 |
| `php -l` 全量 | 0 失败 |

## 本轮 ZIP 内变更

- `includes/class-points.php`：新增 `ledger_sum()`（读失败返回 WP_Error，不再当 0）；`reconcile()`/`verify_balance()` 失败即回滚且不写镜像；`account()`/`has_entry()`/`legacy_entries()` 区分"没有记录"与"查询失败"；`schema_problems()` 对引擎做严格校验（报错 / 空结果 / 缺 Engine 均判不合格）
- `readme.txt` / `README.md`：2.6.2 与 changelog
