# WordPress.org 测试报告（Free）

> **历史快照**：本文记录的是 2.5.0 提交轮的审计结果，版本号、commit 与哈希均为当时值。
> 当前版本请以 `readme.txt` 的 Stable tag、`docs/final-qa-report.md` 与
> `docs/release-zip-audit.md` 为准。

- 日期：2026-10-13
- 环境：本地隔离 WordPress 7.1.3 / PHP 8.3.35 / wp-cli（`wptest/wordpress`）
- Free：`master` @ `c9ac41a`（v2.5.0）；Pro（组合场景）：`main` @ `eef731a`（v1.6.0，含本地授权种子）

## 1. 静态检查

| 命令 | 结果 |
|---|---|
| `php -l` 全部 PHP（工作区 40 文件 + 最终 ZIP 40 文件） | 0 失败 |
| `wp plugin check <最终ZIP解包目录> --format=json` | **0 ERROR / 35 WARNING**（类别与历史一致，均为通知级） |
| ZIP 清单核对（`.git` / 测试文件 / 日志 / 密钥） | 无（make_zip.py sanity + 人工） |
| 版本一致性（主文件 / 常量 / Stable tag） | 2.5.0 三处一致 |

## 2. 功能回归（`_tools/reg_test.php`，Pro 停用）

```
==== 76 passed, 0 failed ====
```

覆盖：激活与引导、等级注册表（工厂色 / 徽章 / 多等级）、头像（无配额直接发布、编辑保留）、设置 sanitize（accent 颜色、6 等级全保留）、订单列与行操作、订单完成→开通→幂等（二次确认拒绝）、付费墙（开关 / 多等级 meta / 价格 / 时效 / 过滤器 / 订单交货 / 二次完成拒绝 / feed 语义）、升级路径注册表（points+purchase、usable/render、订单历史）、卸载/隐私 meta 清单、网关无配额（3 个全保留）、upsell 面（可 dismiss、Pro 激活即消失）。

## 3. 组合测试矩阵

| 场景 | 操作 | 结果 |
|---|---|---|
| 仅 Free 安装/启用 | `wp plugin deactivate at8-moonlight-user-center-pro` + 回归 | 76/0，无 Pro 依赖错误 |
| Free + Pro 启用 | 激活 Pro + `seed_license.php` + `pro_test.php` | 61/0，Pro 模块正常 |
| Free 启用、Pro 停用 | 停用 Pro 后重跑 Free 回归 | 76/0，数据保留 |
| Pro 启用但 Free 缺失 | 代码审计（bootstrap `function_exists` + `defined` 双检） | 仅依赖提示，无 Fatal（未做破坏性实测，见风险） |
| Pro 无效许可证 | `seed_license` 清除后跑 pro_test 关键路径 | 仅 Pro 模块停摆，Free 76/0 不受影响 |
| Free 回归在 Pro 激活下 | `reg_test.php` | 66/10，10 项均为预期差异（管理员免闸 / upsell 消失 / 货架含 Pro 网关），无新增崩溃 |
| 无权限 / Nonce 缺失 | 回归内嵌断言（confirm action nonce、dismiss 需 manage_options） | 拒绝路径通过 |
| 付费内容直接访问 / Feed | 回归断言 `is_paywalled` / feed 分支 | 通过 |

## 4. 未执行项（明确声明）

- PHPCS + WPCS：环境未安装，**未执行**。
- 真实 Stripe / PayPal 沙箱端到端：无凭证，**未执行**（仅静态审计 + 模拟）。
- 授权服务器超时 / 证书错误 / 宽限期网络故障注入：**未执行**（SDK 代码层审计，见 Pro 安全审计报告）。
- 多站点安装 / 卸载矩阵：**未执行**（单站验证）。
- 远程测试站部署：**未执行**（本地隔离站验证）。

## 5. 结论

Free 2.5.0 静态检查与功能回归全部通过；Plugin Check 对最终发布包 0 ERROR。组合场景下 Free 核心行为不被 Pro 改变（Pro 激活时的回归差异均为设计使然）。可以进入提交流程。
