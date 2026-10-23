# Release ZIP Audit — 2.5.8

- 构建日期：2026-10-23
- 构建命令：`python _tools/make_zip.py at8-moonlight-user-center 2.5.8`（从经测试的提交 `master` 工作区打包）
- 产物：`at8-moonlight-user-center-2.5.8.zip`
- **SHA-256**：`67c18fa060e3a151f8cecd1c3e4eb6e66e1c3b9d72f4b1f9eab80abc2642f95b`
- **50 文件 / 163,857 B**

## 核对项

| 项 | 结果 |
|---|---|
| 版本一致（头 / `AT8_MLUC_VERSION` / readme Stable tag / changelog） | 全部 2.5.8（zip 内实测） |
| 根目录 | 单一根 `at8-moonlight-user-center/`，主插件文件位于根 |
| 排除项 | `.git/`、`.gitignore`、`README.md`（开发者文档）、`docs/`、隐藏文件、`*.po`/`*.mo`（语言包由 translate.wordpress.org 分发，保留 `.pot`） |
| 无敏感内容 | 无测试密钥 / access token / 日志 / Pro 代码（构建脚本排除 + 内容抽查） |
| slug | `at8-moonlight-user-center`（未变更） |
| 运行必需资源 | PHP 类 + 模板 + `assets/css/at8-mluc.css` + `assets/js/at8-mluc.js` + `assets/js/at8-mluc-points-admin.js` + `languages/at8-moonlight-user-center.pot` + LICENSE + uninstall.php 齐全 |
| Plugin Check（2.1.0，ZIP 解压扫描） | **0 ERROR / 25 WARNING**；本轮改动的 `class-points.php`、`class-activator.php` 零告警 |
| 干净站安装 | 从该 ZIP `wp plugin install --activate` 后 12/12 自检通过（激活选项、四处短代码渲染、积分发放、claim 行、无残留锁） |
| 升级 | 覆盖安装到已有 2.5.7 站点：版本 2.5.8，用户/流水/订单计数完全不变，`at8_mluc_version` 自愈为 2.5.8 |
| 卸载（delete_data=1） | 插件状态全部清零（options/usermeta/ledger/claims/orders），核心数据不变（users 2→2、comments 1→1） |
| 卸载（默认保留） | 用户、流水、设置项均保留，仅清运行态（lock/claim） |
| 运行期告警 | 全流程 `debug.log` 增量 0 行 |

## 本轮 ZIP 内变更

- `includes/class-points.php`：claim + per-user mutex + 重放余额（并发一致性重写）
- `includes/class-activator.php`：`sync_version()`，升级后自动对齐版本记录
- `at8-moonlight-user-center.php`：bootstrap 调用 `sync_version()`
- `uninstall.php`：清理 `at8_mluc_points_claim_*`
- `readme.txt` / `README.md`：2.5.8 changelog 与并发模型说明
