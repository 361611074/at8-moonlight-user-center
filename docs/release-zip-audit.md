# Release ZIP Audit — 2.5.2

- 构建日期：2026-10-23
- 构建命令：`python _tools/make_zip.py at8-moonlight-user-center 2.5.2`（从经测试的提交 `master` 工作区打包）
- 产物：`at8-moonlight-user-center-2.5.6.zip`（并已发布为 GitHub Release：https://github.com/361611074/at8-moonlight-user-center/releases/tag/2.5.6，资产 digest 与本地 SHA-256 一致）
- **SHA-256**：`134efdaa1c86218c662e5289140a24d9206770577aaef0d2ac0151d711c5d341`
- **50 文件 / 159,077 B**

## 核对项

| 项 | 结果 |
|---|---|
| 版本一致（头 / `AT8_MLUC_VERSION` / readme Stable tag / CHANGELOG） | 全部 2.5.6（zip 内实测） |
| 根目录 | 单一根 `at8-moonlight-user-center/`，主插件文件位于根 |
| 排除项 | `.git/`、`.gitignore`、`README.md`（开发者文档）、`docs/`、隐藏文件、`*.po`/`*.mo`（语言包由 translate.wordpress.org 分发，保留 `.pot`） |
| 无敏感内容 | 无测试密钥 / access token / 日志 / Pro 代码（构建脚本排除 + 内容抽查） |
| slug | `at8-moonlight-user-center`（未变更） |
| 运行必需资源 | PHP 类 + 模板 + `assets/css/at8-mluc.css` + `assets/js/at8-mluc.js` + 新增 `assets/js/at8-mluc-points-admin.js` + `languages/at8-moonlight-user-center.pot` + LICENSE + uninstall.php 齐全 |
| 安装测试 | 从该 ZIP `wp plugin install --force` 后 Free 独立 reg_test **76/0**、矩阵 **37/0** |
| Plugin Check（ZIP 解压扫描） | **0 ERROR / 25 WARNING**（结构性项，见 audit-request-security.md / PCP 终报） |
