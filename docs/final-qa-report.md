# Final QA Report — 2.5.2（AUTOPREREVIEW 整改验收）

- 日期：2026-10-23；环境：本地隔离 WordPress 7.1.3 / PHP 8.3 / SQLite（WP_DEBUG 开）
- 结论：**NEEDS HUMAN VERIFICATION**（代码 P0/P1 已全部关闭；剩余两项只能由人工完成：真实 OAuth 第三方凭据端到端、官方服务条款/隐私政策链接的人工点开确认——理由见"未完成项"）

## 测试结果总表

| 检查 | 命令 / 步骤 | 结果 |
|---|---|---|
| PHP lint（全部改动文件 + 全仓抽样） | `php -l` | **PASS**（0 失败） |
| Plugin Check（ZIP 解压扫描，2.1.0） | `wp plugin check <zip解压目录> --format=json` | **PASS**：0 ERROR / 25 WARNING（结构性项已书面说明） |
| Free 独立回归（ZIP 安装，Pro 停用） | `wp eval-file reg_test.php` | **PASS**：76/0 |
| Free + Pro 联合（授权种子） | `wp eval-file pro_test.php` | **PASS**：64/0 |
| 安全/功能矩阵（含积分幂等、SVG payload、nonce/越权/重复回调/过期/授权篡改/限流） | `wp eval-file v4_matrix_test.php` | **PASS**：37/0 |
| 积分与订单 | 见 `test-points-orders.md` | 11 PASS / 2 NOT RUN（并发压测、支付沙箱） |
| 卸载与数据 | 见 `test-uninstall-data.md` | 7 PASS / 1 NOT RUN（多站点实机） |
| 外部请求 | 见 `audit-external-requests.md` | 仅 OAuth 必需交换，无遥测 |
| 请求安全 | 见 `audit-request-security.md` | 全入口表格，无未防护入口 |
| ZIP 发布 | 见 `release-zip-audit.md` | 版本/排除/根目录/敏感内容全过 |
| WP_DEBUG 告警 | 套件运行前后 debug.log 行数对比 | **PASS**：0 新增 |
| JS/CSS 注册与加载条件 | 代码审计 + handle 清单 | **PASS**（仅 post 编辑页 / profile 页 / 前端账户页加载） |
| PHP 8.3（当前稳定） | 全部套件运行环境 | **PASS** |
| PHP 7.4（声明最低） | — | **NOT RUN**（环境未备 7.4 实机；代码未用 7.4 以上语法，`php -l` 于 8.3 通过） |
| WordPress 5.8（声明最低） | — | **NOT RUN**（环境 7.1.3；未用 5.8 之后 API——inline-only 脚本容器为 5.7+ 特性 ✓） |
| Free 单独 / Free + Pro 冲突 | 类/函数/handle/option 冲突扫描 + 联合回归 | **PASS** |
| OAuth 真实端到端（Google/微信/GitHub/QQ/Apple） | — | **NOT RUN**（需人工提供各平台测试凭据） |

## P0/P1/P2 状态

| 级别 | 项 | 状态 |
|---|---|---|
| P0 | 积分按消费金额发放 | **已修复**（端到端 + 幂等 + 状态防御 + 审计账本） |
| P0 | 卸载删除数据设置 | **已修复**（真实设置 UI + 默认保留 + 卸载实测 8/8） |
| P1 | 内联 JS/CSS | **已修复**（标准脚本 API，2 处归零） |
| P1 | Nonce/权限 | **已关闭**（全入口表格 + 修复证据） |
| P1 | OAuth SVG XSS | **已修复**（kses 白名单 + 6 条 payload 回归） |
| P1 | 外部服务披露 | **已修复**（readme External services 全量 + 矛盾表述清除） |
| P1 | 命名前缀 | **已审计**（单一 at8_mluc_ 前缀，无冲突，零迁移） |
| P2 | openssl_free_key | **已移除** |
| P2 | 翻译打包 | **已处理**（zip 无 po/mo） |
| P2 | 版本/ZIP 一致性 | **已核对**（2.5.2 三处一致） |

## 修改文件（2.5.1 → 2.5.2 差异摘要）

- `includes/class-points.php`：积分发放增加订单状态防御（仅 paid）
- `includes/class-privacy.php`：隐私建议文案去掉"绝不向外部发送数据"绝对化表述，改为条件化准确描述
- `readme.txt`：Description 绝对化表述修正 + changelog 2.5.2
- `README.md`：设计原则段改为"无分析/遥测 + OAuth 条件化披露"，版本与 zip 文件名 2.5.2
- 主插件文件 / readme Stable tag：2.5.2

## 未解决问题与人工步骤

1. **NEEDS HUMAN**：申请各平台 OAuth 测试凭据，在测试站跑通 5 个 provider 的完整登录（浏览器端验证 + 回调日志检查）
2. **NEEDS HUMAN**：逐一点开 readme.txt External services 中的 10 个条款/隐私政策链接，确认内容适用（引用日期 2026-10-23）
3. NOT RUN：PHP 7.4 / WP 5.8 最低版本实机、多站点卸载、支付沙箱、并发压测（已在各文档标注）
4. 人工提交：上传 `at8-moonlight-user-center-2.5.2.zip` 到原提交页面，原邮件线程简短回复
