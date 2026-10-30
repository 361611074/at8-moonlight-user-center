# Release ZIP Audit — 2.6.0

- 构建日期：2026-10-30
- 构建命令：`python _tools/make_zip.py at8-moonlight-user-center 2.6.0`（从经测试的 `master` 工作区打包）
- 产物：`at8-moonlight-user-center-2.6.0.zip`
- **SHA-256**：`0f579e15ff2d4e1b533f0e3895bb57c3ad2d19ee7025a534768cba3b49c926b0`
- **50 文件 / 165,177 B**

## 核对项

| 项 | 结果 |
|---|---|
| 版本一致（头 / `AT8_MLUC_VERSION` / readme Stable tag / changelog） | 全部 2.6.0（zip 内实测） |
| 根目录 | 单一根 `at8-moonlight-user-center/`，主插件文件位于根 |
| 排除项 | `.git/`、`.gitignore`、`README.md`（开发者文档）、`docs/`（审计报告与测试脚本）、隐藏文件、`*.po`/`*.mo`（语言包由 translate.wordpress.org 分发，保留 `.pot`） |
| 无敏感内容 | 无测试密钥 / token / 日志 / Pro 代码（构建脚本排除 + 内容抽查） |
| slug | `at8-moonlight-user-center`（未变更；除新增两张表外数据库键未变更） |
| 运行必需资源 | PHP 类 + 模板 + `assets/css/at8-mluc.css` + `assets/js/at8-mluc.js` + `assets/js/at8-mluc-points-admin.js` + `languages/at8-moonlight-user-center.pot` + LICENSE + uninstall.php 齐全 |
| Plugin Check（2.1.0，ZIP 解压扫描） | **0 ERROR / 25 WARNING**；余下告警均为既有项（`$_POST` 读取、payments 直连查询、`meta_query`），本轮新增的 `class-points.php` 零告警 |
| 安装 | 从该 ZIP `wp plugin install --force` 后版本 2.6.0、`at8_mluc_points_db=1`、两张表创建成功 |
| 升级 | 覆盖安装到 2.5.9 站点：余额 70→70、流水 2→2 行、`purchase:<order>` 幂等键保留、重发不变、升级后新增变动正常（→75）；23 个老用户逐用户余额与 2.5.9 相等（总量 2669→2669） |
| 卸载（delete_data=1） | 两张表被 DROP、`at8_mluc_%` usermeta 归零、`at8_mluc_points_db` 删除；核心数据不变（users 26→26、posts 30→30、comments 1→1） |
| 卸载（默认保留） | 两张表与全部数据保留，仅清运行态（transients / 旧版本遗留的 lock、claim 行） |
| 运行期告警 | 全流程 `debug.log` 增量 0 行 |

## 回归与并发结果（对该 ZIP 安装后实测）

| 套件 | 结果 |
|---|---|
| 后端矩阵 `v4_matrix_test.php` | 50 / 0 |
| 存储层 `atomicity_test.php` | 32 / 0 |
| 并发 10 场景 `concurrency_test.php` | 54 / 0 |
| Free 独立 `reg_test.php` | 76 / 0 |
| Free + Pro `pro_test.php` | 64 / 0 |
| `php -l` 全量 | 0 失败 |

## 本轮 ZIP 内变更

- `includes/class-points.php`：积分存储改为专用流水表 + 账户表，单事务 + 版本 CAS + 唯一幂等键；移除 per-user 锁、claim 行、TTL 租约与"事后删除流水"补偿
- `includes/class-activator.php`：激活时建表
- `at8-moonlight-user-center.php`：`plugins_loaded` 时 `maybe_install()`，原地升级也能建表
- `includes/class-privacy.php`：导出含流水、擦除含表数据
- `uninstall.php`：`delete_data=1` 时 DROP 两张表
- `readme.txt` / `README.md`：2.6.0 与 changelog
