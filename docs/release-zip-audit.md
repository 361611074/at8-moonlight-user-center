# Release ZIP Audit — 2.7.6

- 构建日期：2026-11-20
- 构建命令：`python _tools/make_zip.py at8-moonlight-user-center 2.7.6`（从经测试的工作区打包）
- 产物：`at8-moonlight-user-center-2.7.6.zip`
- **52 文件 / 212,817 B**
- **SHA-256**：`5c35735dfda02a0333d622ecfcc8cf6166fdaa1e6279274d76d61cc237538db9`

## 核对项

| 项 | 结果 |
|---|---|
| 版本一致（头 / `AT8MLUC_VERSION` / readme Stable tag / changelog） | 全部 2.7.6（zip 内实测） |
| 根目录 | 单一根 `at8-moonlight-user-center/`，主插件文件位于根 |
| 排除项 | `.git/`、`.gitignore`、`README.md`（开发者文档）、`docs/`（审计报告与测试脚本）、`.wordpress-org/`（目录页插图，属于 SVN 的 `assets/`）、隐藏文件、`*.po`/`*.mo` |
| 截图 | `.wordpress-org/screenshot-1..4.png`，均 1200×900，与 readme 的 4 条标题一一对应；不进 ZIP |
| Plugin Check（2.1.0，zip 解压后扫描） | **0 ERROR / 1 WARNING** |
| 干净站安装 | 15 / 0：设置项、版本记录、四个短代码、积分链路、幂等唯一索引、后台菜单 |
| 卸载（`delete_data=1`，含旧前缀数据） | Free 自有 options / usermeta / 表 **全部归零**；Pro 的 3 项选项按设计保留 |
| 卸载（默认保留数据） | 账户、账本、订单、头像保留；仅清运行态（瞬态与计划任务） |
| 停用 | `at8mluc_pay_autoclose` 与旧前缀同名事件一并清除；重新启用后由 `init` 自动补回当前前缀那条 |
| 运行期告警 | `debug.log` 0 条 PHP 级告警 |
| CRLF | 0（zip 内全部文件为 LF） |

### 唯一一条 WARNING 的说明

`readme_parser_warnings_trimmed_section_changelog` —— `== Changelog ==` 超过 5000 字符，WordPress.org 只渲染前 5000。2.7.5 的包内该段已是 30,644 字符，2.7.6 为 33,850，属既有状态而非本次引入。处理方式是保留完整历史（站点所有者与 GitHub 读者会看），接受渲染截断；如需消除该警告，可把 2.7.3 及更早的条目移入 `CHANGELOG.md`。

## 回归结果（对该 ZIP 解压安装后实测）

| 套件 | 结果 |
|---|---|
| `_tools/reg_test.php`（Pro 停用） | 76 / 0 |
| `_tools/pro_test.php`（Pro 激活） | 64 / 0 |
| `_tools/v4_matrix_test.php`（Pro 激活） | 72 / 0 |
| `_tools/v4_clean_install.php` | 15 / 0 |
| `docs/tests/run_auth_tests.sh`（认证链，21 场景） | 75 / 0（连续三轮一致） |
| `_tools/v4_conc_run.sh award 8` | 4 / 0 |
| `_tools/v4_conc_run.sh deduct 8` | 5 / 0 |
| `_tools/v4_conc_run.sh mixed 8` | 4 / 0 |
| `php -l` 全量 | 0 失败 |

## 本轮 ZIP 内变更

- `includes/class-upgrade-paths.php`：积分兑换的扣费带幂等键（`redeem:<用户>:<等级>:<日期>`），重复请求回放余额而不是二次扣费
- `includes/class-fields.php`：`save_after_profile()` 在请求缺少资料表单 nonce 时直接返回，程序化触发不再清空自定义字段
- `includes/class-oauth.php`：社交资料数组去掉 `avatar` 字段——从未有消费方，插件也从不导入远端头像
- `includes/class-activator.php`：`deactivate()` 清除 `at8mluc_pay_autoclose`（含旧前缀），停用后不再留下无监听者的每小时计划任务
- `uninstall.php`：
  - 计划任务改为**始终**清理，并同时清理 2.7.0 改名前的旧钩子
  - 瞬态改为按名称扫描（`%at8mluc%` 与 `%at8_mluc%`），不再依赖会过期的硬编码清单
  - usermeta 改为双前缀整体清扫，覆盖社交绑定与自定义字段
  - 积分两张表补上旧前缀同名表，固定表名、不做动态发现
  - 选项扫描补上旧前缀
- `readme.txt`：Description 压到 2,390 字符（上限 2,500）；新增 2.7.6 changelog 与 Upgrade Notice；4 条截图标题按实际画面重写
- `readme.txt` 的 `== External services ==`：GitHub / WeChat / QQ 三处原写「插件接收头像 URL」，与 2.7.6 移除 payload 头像字段的改动读起来相互矛盾。改为说明「响应体也含头像 URL，但插件既不存储也不使用，且从不导入远端头像」——对外披露宁可多说，但不能前后不一致
- `templates/account-overview.php`：会员到期时间用 `date_i18n()` 渲染。`get_user_expires()` 返回的是时间戳，原代码却交给 `mysql2date()`（它要 MySQL 日期字符串），于是任何有到期时间的账户都只显示一个空的 "Valid until"；无到期的账户因为 `0 !== ''` 也会渲染这个悬空标签。会员标签页的模板本来就用 `(int)` + `date_i18n()`，概览页与它对齐
- `CHANGELOG.md`：补 2.7.4 / 2.7.5 / 2.7.6 三段（此前停在 2.7.3）

## 测试基建修正（不进入 ZIP）

- `_tools/oauth_chain_child.php`：新增 `pm_child_scrub_identity()`，重试注册与未验证邮箱两个场景开跑前先清同名 fixture 身份；限流在进程级关闭。此前上一轮留下的 `mlwechat_<hash>` 账号不会被 `pmc_`/`pmr_` 前缀清理到，导致下一轮直接走"复用已有账号"分支，把"新账号被创建"的断言翻转成假失败
- `at8-moonlight-user-center/docs/tests/run_auth_tests.sh`：清理补上按 `at8mluc_oauth_*` fixture 绑定收集的账号；开跑前清空限流桶
- `at8-moonlight-user-center/docs/tests/oauth_chain_child.php`、`oauth_chain_assert.php`：与 `_tools/` 下的实际执行版本同步（此前落后数个版本）

## 未覆盖

- 真实 MySQL/MariaDB 上的并发与迁移分支（本机为 SQLite）
- 真实第三方 OAuth 凭据的端到端登录
- PHP 7.4 实机（本机为 PHP 8.3）
