# 积分并发锁 P1 最终整改 — 测试报告（2.5.9）

- 日期：2026-10-30
- 任务书：《AT8 Moonlight User Center 2.5.8｜P1 积分并发锁最终整改任务》
- 修复版本：**2.5.9**
- 环境：本地隔离 WordPress 7.1.3 / PHP 8.3 / SQLite drop-in（多进程测试用真实独立 PHP 进程）
- 交付 ZIP：`at8-moonlight-user-center-2.5.9.zip`（50 文件 / 165,073 B / SHA-256 `68cf0dd22a2ac8d126eb907ee3adc52e2262b3ed35cf792d5e69d26272878ccc`）

## 一、修复内容（对应任务书 P0/P1 第 1、2 条）

### 1. 锁过期后旧请求继续执行 → **fencing + 写入前复核**

| 改动 | 位置 | 说明 |
|---|---|---|
| 锁值加 fence 序号 | `lock()` | 锁值格式 `token|expiry|fence`；首次获取 fence=1，每次接管 fence+1 |
| `renew_lock()`（新增） | `class-points.php` | **CAS 续租 = 所有权验证**：`swap_row(name, 我当前的值, 新 expiry + fence+1)`。只有锁行仍持有本请求**精确值**时才成功；被接管/被删除则失败 |
| 三处写入前复核 | `apply_locked()` | ① replay 前 ② 写流水前 ③ 写余额前——每次先 `renew_lock`。任一失败 → **WP_Error(`at8_mluc_points_lock`)** 且**零写入**（不留半写流水） |
| 锁失效不误删他人锁 | `unlock()` | 仍为 CAS 删除（期望本请求的精确值）；新增句柄有效性守卫——租约已失时不做任何删除 |
| 余额写入失败检测 | `store_balance()` | 改为返回 bool（读回校验，因 `update_user_meta` 值相同时也返回 false）；失败 → **精确回滚本次流水行** + 恢复余额 + WP_Error |
| 旧持有者（过期未接管） | — | 语义明确：**过期但未被接管 = 租约可续**（renew 成功即恢复）；**被接管 = 永久失去**（fence 前进，旧值 CAS 必然失败） |
| 第三方钩子耗时 | `at8_mluc_points_lock_acquired` | 钩子在 `lock()` 内触发；钩子期间锁被接管/过期 → 后续第一次 renew 失败 → 请求中止，**不会写入** |

**没有通过改大 `LOCK_TTL` 或加注释"修复"**：TTL 仍为 10s（`LOCK_TTL`），正确性由 fence + CAS 复核保证。

### 2. 半写与回滚

- 流水行 = 原子 INSERT（`add_user_meta`），行内含 `u` 唯一令牌；
- `add_entry()` 返回**实际存储的完整行**（含 `u`），回滚按该精确值删除 → 只可能删掉本次新增的那一行；
- 余额镜像失败 → 删本次行 → replay 恢复余额 → 返回 `WP_Error('at8_mluc_points_save')`。

## 二、真实多进程并发测试（任务书第 3 条）

测试代码：`_tools/concurrency_test.php`（父进程调度）+ `_tools/conc_worker.php`（独立子进程，各自完整 bootstrap WordPress）。
观测由**独立 observer 进程**完成（模拟生产"每请求独立进程"，避免父进程对象缓存干扰）。

```
wp eval-file concurrency_test.php        →  17 passed, 0 failed
```

| # | 场景 | 期望 | 实测 |
|---|---|---|---|
| 1 | 8 进程同时为同一用户 **+10** | 8/8 成功；流水 8 条；合计 80；余额 80 | **PASS**（8/8、8 条、80、80） |
| 2 | 8 进程同时 **-5**（初值 100） | 8/8 成功；9 条流水；合计 60；余额 60 | **PASS** |
| 2b | 8 进程同时 -5（初值 7） | 仅 1 笔成功；余额 2；不破零 | **PASS**（1/8、2、2） |
| 3 | **8 个不同订单**并发发放 | 8/8；8 条；合计 160 | **PASS**（无漏发） |
| 4 | **同一订单 8 次并发回调** | 恰好 1 条流水；20 分 | **PASS**（1 条、20） |

## 三、锁语义测试（任务书第 3 条 5–8、10）

测试代码：`_tools/lock_semantics_test.php`（单进程内用 SQL 直接模拟竞争进程对锁行的操作）

```
wp eval-file lock_semantics_test.php     →  15 passed, 0 failed
```

| 用例 | 期望 | 实测 |
|---|---|---|
| T1 锁被接管后原持有者写入 | 拒绝（`at8_mluc_points_lock`）、无流水、余额不动 | **PASS** |
| T1 旧持有者释放锁 | 不能删除新持有者的锁 | **PASS** |
| T2 钩子耗时期间锁被接管（`lock_acquired` 内接管） | 请求中止、零半写（流水 0、余额 0） | **PASS** |
| T3 进程异常退出（锁残留、未解锁） | 过期后新请求 CAS 接管 → 正常发放 20 分 → 锁释放 | **PASS** |
| T4 fence 单调性 | 每次续租 +1（1→2→3） | **PASS** |
| T4 持有者释放自己的锁 | 可以释放 | **PASS** |
| T5 进程退出后的 claim 接管 | CAS 接管成功 | **PASS** |

## 四、其余发布检查（任务书第 4、5 条）

| 检查 | 结果 |
|---|---|
| 全部 PHP 文件 `php -l` | **PASS**（0 失败） |
| Plugin Check（2.1.0，扫描最终代码） | **PASS**：0 ERROR / 25 WARNING（均为已归档的结构性项） |
| 版本一致（头 / 常量 / Stable tag / README / CHANGELOG） | **PASS**（全部 2.5.9） |
| 安装（ZIP） | **PASS** |
| **升级 2.5.8 → 2.5.9** | **PASS**：余额 42、流水 1 条、rate 1.5、订单、幂等键 `upgrade:demo` 全部保留；升级后发放正常（42 + 15 = 57） |
| 停用 / 重新启用 | **PASS**（数据无变化） |
| 卸载（`delete_data=1`） | **PASS**：核心数据不变（users 23→23 / posts 22→22 / comments 1→1）；插件残留清零（`claim=0`、`lock=0`、`usermeta=0`） |
| 默认卸载（保留数据） | **PASS**（沿用既有逻辑：`delete_data` 默认 0） |
| 兼容性（任务书第 4 条） | 未删除历史流水（旧 `at8_mluc_points_log` 仍参与 replay 与展示）；未改数据结构；`at8_mluc_payment_completed` 钩子签名不变（3 参数）；`Points::adjust()` 前 4 参数签名不变，第 5 参数 `$key` 为可选新增 |

## 五、已知限制（如实记录）

1. **SQLite drop-in 环境的观测限制**：父进程在 spawn 多个子进程后直接读取自己的对象缓存会看到过期数据；测试因此统一用独立 observer 进程观测（等同于生产每请求独立进程）。生产 MySQL 环境不受此限。
2. **真实高并发压测规模**：8 进程 × 多个场景，未做 100+ 并发的长时间 soak 测试（本地 SQLite 不支持高并发写压测）。
3. **数据库层并发语义**：`insert_row/swap_row/delete_row` 依赖 `option_name` 的 UNIQUE 索引（WordPress 标准 schema）；MySQL `InnoDB` 行锁保证 CAS 原子性。SQLite 下为库级写锁，语义等价但吞吐不同。
4. 未在 PHP 7.4 实机验证（代码语法与 7.4 兼容，运行环境为 8.3）。

## 六、结论

任务书要求的"失去锁的请求不能继续执行关键积分写入""不能让旧锁持有者删除新请求的锁""续租失败不能继续写入""失败不留半写流水""真实并发测试"均已实现并有可复现的测试证据（并发 17/0、锁语义 15/0、矩阵 50/0、Free 独立 76/0、Pro 联合 64/0、PCP 0 ERROR）。
