# Release ZIP Audit — 2.7.4

- 构建日期：2026-11-20
- 构建命令：`python _tools/make_zip.py at8-moonlight-user-center 2.7.4`（从经测试的 `master` 工作区打包）
- 产物：`at8-moonlight-user-center-2.7.4.zip`
- **52 文件 / 208,937 B**
- **SHA-256**：`500cf54cbb38ce69ab61086736862cbcfb1970cc2af6081313a0f88f710dd1c9`

## 核对项

| 项 | 结果 |
|---|---|
| 版本一致（头 / `AT8MLUC_VERSION` / readme Stable tag / changelog） | 全部 2.7.4（zip 内实测） |
| 根目录 | 单一根 `at8-moonlight-user-center/`，主插件文件位于根 |
| 排除项 | `.git/`、`.gitignore`、`README.md`（开发者文档）、`docs/`（审计报告与测试脚本）、隐藏文件、`*.po`/`*.mo` |
| Plugin Check（2.1.0，ZIP 解压扫描） | **0 ERROR / 0 WARNING** |
| 干净站安装 | 15 / 0：设置项、版本记录、四个短代码、积分链路、幂等唯一索引、后台菜单 |
| 升级（2.7.3 → 2.7.4） | 安装后数据计数不变，旧数据可读 |
| 卸载（delete_data=1） | options / usermeta / 两张表 全部归零 |
| 卸载（默认保留） | options 4 项、买家账户与账本行保留，仅清运行态 |
| 运行期告警 | `debug.log` 0 条 PHP 级告警 |

## 回归结果（对该 ZIP 安装后实测）

| 套件 | 结果 |
|---|---|
| `_tools/reg_test.php`（Free 独立） | 76 / 0 |
| `_tools/v4_matrix_test.php`（Free 独立） | 67 / 0 |
| `_tools/v4_matrix_test.php`（Pro 激活） | 68 / 0 |
| `_tools/pro_test.php`（Pro 联合） | 64 / 0 |
| `docs/tests/run_auth_tests.sh`（认证链，21 场景） | 75 / 0 |
| `_tools/v4_conc_run.sh award 8` | 4 / 0 |
| `_tools/v4_conc_run.sh deduct 8` | 5 / 0 |
| `php -l` 全量 | 0 失败 |

## 本轮 ZIP 内变更

- `readme.txt`：新增 Gravatar 外部服务披露（用途、域名、发送的数据、触发时机、条款与隐私链接）；补全社交登录的账号创建与登录说明；2.7.4 changelog
- `includes/class-oauth.php`：注册撞邮箱的复用条件与 `at8mluc_oauth_allow_email_link` 对齐（不再绕过）；显示名改到认证与绑定通过之后写入
- `includes/class-hidecontent.php`：CTA 过滤器输出过 `wp_kses_post()`；短代码与内容过滤的透传返回补充说明与注解
- `includes/class-paywall.php`：付费墙返回值的透传说明与注解（`wp_kses()` 会剥掉 iframe 与内联脚本，故不在接缝处转义）
- `includes/class-fields.php`：字段选项改 `sanitize_text_field()`，上限 100 条；文本槽拒绝数组；新增 `scalar_text()` 助手
- `uninstall.php`：卸载时按前缀清扫全部选项，修掉 2.7.0 迁移标记残留

## 未覆盖

- 真实 MySQL/MariaDB 上的并发与迁移分支（本机为 SQLite）
- 真实第三方 OAuth 凭据的端到端登录
- PHP 7.4 实机（本机为 PHP 8.3）
