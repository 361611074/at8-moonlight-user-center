# 目录页资源（`assets/`）

WordPress.org 插件页的图标、横幅和截图，与安装包是两条独立的分发通道：ZIP 里没有它们，它们走 SVN 的顶层 `assets/` 目录。

## 当前状态

**SVN 仓库尚不存在。** 2026-11-20 实测 `https://plugins.svn.wordpress.org/at8-moonlight-user-center/` 返回 **404**。这是正常的：仓库在插件通过审核后才创建。所以「截图还没进 SVN」目前不是遗漏，是还没到时候——但资源本身已经备齐并校验过了。

审核方在评审 ZIP 时看不到这些图，这是设计如此，不需要在回复信里解释。

## 资源清单

全部位于 `at8-moonlight-user-center/.wordpress-org/`，文件名即 SVN 中要用的文件名，一一对应，不需要改名。

| 文件 | 尺寸 | 用途 |
|---|---|---|
| `icon-128x128.png` | 128×128 | 搜索结果与后台插件列表里的方形图标 |
| `icon-256x256.png` | 256×256 | 同上，高分屏（Retina） |
| `banner-772x250.png` | 772×250 | 插件页顶部横幅 |
| `banner-1544x500.png` | 1544×500 | 同上，高分屏；**不能单独存在**，必须与 772×250 一起 |
| `screenshot-1.png` | 1200×900 | 账户概览 |
| `screenshot-2.png` | 1200×900 | 资料编辑 |
| `screenshot-3.png` | 1200×900 | 头像选择 |
| `screenshot-4.png` | 1200×900 | 前台登录 |

图标与横幅是图形设计（`_tools/make_wporg_assets.py` 用 HTML + 无头 Chrome 渲染，配色取自 `assets/css/at8mluc-admin.css`）；截图是真实页面抓取（`_tools/shots_capture.py`），不是拼的。

## 会静默失败的四条规则

目录对文件名和位置极其严格，**错了不会报错，图就是不出现**：

1. **必须在 SVN 顶层 `assets/`**，不是 `trunk/assets/`，也不是 `tags/1.0/assets/`。`assets/` 与 `trunk`、`tags` 同级。
2. **文件名必须完全一致且全小写。** 大写字母直接失效。
3. **尺寸必须与文件名里的数字一致。** 把 772×250 的图命名成 `banner-772x250.png` 却塞 800×260 的像素，页面会很难看或者干脆不显示。
4. **必须设 `svn:mime-type image/png`。** 否则服务器以 `application/octet-stream` 发送，访客点图会变成「下载文件」。这是提交成功但页面无图最常见的原因，脚本里已经处理。

另外：**readme.txt 的 `== Screenshots ==` 每一条对应一张图**，行数必须与图片数一致，否则第 5 条标题下面会是空白。`check_wporg_assets.py` 会校验这个对应关系。

## 校验

```bash
python _tools/check_wporg_assets.py
```

检查必需文件是否齐、尺寸是否精确、体积是否超限（图标 1 MB / 横幅 4 MB / 截图 10 MB）、文件名是否全小写、截图数是否与 readme 标题数一致。任何一项不合格都以非零码退出。

## 发布

```bash
bash _tools/svn_assets.sh              # 演练：校验 + 显示会提交什么，不提交
bash _tools/svn_assets.sh --commit     # 真正提交
```

脚本做这些事：跑校验 → 探测仓库是否存在 → 在 `_tmp_svn/` 里做一份临时检出 → 复制文件 → `svn add` → `svn propset svn:mime-type` → 打印 `svn status` → 提交。默认只演练，避免手滑。

如果 `assets/` 目录还不存在（首次发布），脚本会先检出仓库根目录再建目录。

**凭据**：用拥有该插件的 wordpress.org 账号，svn 会交互提示，脚本不保存。认证失败最常见的原因是两步验证，去个人资料页确认。

**没有 svn 客户端时**：用 TortoiseSVN 检出 `https://plugins.svn.wordpress.org/at8-moonlight-user-center`，把八个文件放进顶层 `assets/`，右键 SVN Commit。提交前记得在 TortoiseSVN 里确认 `svn:mime-type` 属性已设为 `image/png`（属性面板可加）。

## 提交之后

目录页资源走 CDN 并重度缓存，**几分钟到几小时**才刷新，高峰期六小时也见过。这段时间页面没变化不是失败，别重复提交。

## 重新生成

```bash
python _tools/make_wporg_assets.py     # 图标与横幅
python _tools/shots_capture.py         # 四张截图（需要本地测试站与 QA mu-plugin）
```

截图依赖 `?at8shot=<login>` 强制身份，那个 mu-plugin 已从测试站移除，副本在 `_tools/at8-shot-helper.php.muplugin`，需要重抓时放回 `wp-content/mu-plugins/`，抓完再删。
