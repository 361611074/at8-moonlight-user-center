# Final QA Report — 2.5.8（并发账本整改验收）

- 日期：2026-10-23；环境：本地隔离 WordPress 7.1.3 / PHP 8.3.35 / SQLite（WP_DEBUG 开）
- 结论：**代码层面的 P1 全部关闭**；仍有两项只能由人工完成（真实 OAuth 凭据端到端、官方政策链接人工确认），另有若干环境限制项标注为 NOT RUN。

## 测试结果总表

| 检查 | 命令 / 步骤 | 结果 |
|---|---|---|
| PHP lint（全部改动文件 + 全仓抽样） | `php -l` | **PASS**（0 失败） |
| Plugin Check（最终 ZIP 解压扫描，2.1.0） | `wp plugin check <解压目录>` | **PASS**：0 ERROR / 25 WARNING（结构性项，见 audit-request-security.md） |
| **并发：同订单 8 路回调** | `bash _tools/v4_conc_run.sh award 8` | **PASS 6/0**：1 条流水、余额 20、临界区 0 重叠 |
| **并发：8 路扣分（余额 100 × 扣 30）** | `bash _tools/v4_conc_run.sh deduct 8` | **PASS 7/0**：3 成功 / 5 拒绝、余额 10、账本合计 10 |
| **并发：加扣混合** | `bash _tools/v4_conc_run.sh mixed 8` | **PASS 6/0**：余额恒等于账本合计，无负余额 |
| Free 独立回归（ZIP 安装，Pro 停用） | `wp eval-file reg_test.php` | **PASS**：76/0 |
| 安全/功能矩阵（含 claim、接管、镜像失效、故障注入） | `wp eval-file v4_matrix_test.php` | **PASS**：Free 独立 49/0、Pro 激活态 50/0 |
| Free + Pro 联合（授权种子） | `wp eval-file pro_test.php` | **PASS**：64/0 |
| 干净站安装（ZIP，全新数据库） | `wp plugin install <zip> --activate` + `v4_clean_install.php` | **PASS 12/0** |
| 升级（2.5.7 → 2.5.8 覆盖安装） | `wp plugin install <zip> --force` | **PASS**：数据计数不变，版本自愈 |
| 卸载（delete_data=1） | `wp plugin uninstall` | **PASS**：插件数据全清，核心数据不变 |
| 卸载（默认保留） | 同上 | **PASS**：用户/流水/设置保留 |
| WP_DEBUG 告警 | 全流程前后 debug.log 行数对比 | **PASS**：0 新增 |
| PHP 7.4（声明最低） | — | **NOT RUN**（无 7.4 实机；代码未用 7.4 以上语法，8.3 `php -l` 通过） |
| WordPress 5.8（声明最低） | — | **NOT RUN**（环境 7.1.3；未用 5.8 之后 API） |
| MySQL / MariaDB 实机 | — | **NOT RUN**（本机为 SQLite；模型依赖的 `option_name` UNIQUE 索引是核心 schema 自带） |
| 多站点实机 | — | **NOT RUN** |
| 支付沙箱（PayPal / Stripe 真实扣款） | — | **NOT RUN**（无沙箱凭据） |
| OAuth 真实端到端（Google/微信/GitHub/QQ/Apple） | — | **NOT RUN**（需各平台测试凭据） |
| 官方政策链接人工确认 | — | **NOT RUN**（10 条链接需人工点开核对，引用日期 2026-10-23） |

## 本轮整改项

| 级别 | 项 | 状态 |
|---|---|---|
| P1 | 同一订单并发重复发放积分 | **已修复**（claim 行 = 数据库级唯一，复现 8 条流水 → 现 1 条） |
| P1 | 并发重算余额写回旧值 / 扣分竞争 | **已修复**（per-user mutex + 余额只从账本重放；复现 240 分透支 → 现 3 成功 5 拒绝） |
| P1 | 公开仓库版本落后 | **不成立**（远程 `master` = `e1a5003`，头/常量/Stable tag/README 均为 2.5.7，Release 2.5.7 已发布；本轮再推 2.5.8） |
| P2 | 升级后 `at8_mluc_version` 停留在旧版本 | **已修复**（`sync_version()` 自愈） |
| — | `docs/` 版本号停留在 2.5.2 | **已修复**（本轮统一到 2.5.8） |

## 未解决问题与人工步骤

1. **NEEDS HUMAN**：申请各平台 OAuth 测试凭据，跑通 5 个 provider 的完整登录。
2. **NEEDS HUMAN**：逐一点开 readme.txt External services 中的条款/隐私链接，确认内容适用。
3. **NEEDS HUMAN**：上传 `at8-moonlight-user-center-2.5.8.zip` 到 wp.org 原提交页面，并回复审核线程。
4. 已知限制（不在本轮范围）：积分兑换等级的两个并发请求可能各扣一次分并各授一次等级，
   需给兑换引入请求级幂等键才能彻底消除；详见 `docs/concurrency-ledger.md` 第五节。
