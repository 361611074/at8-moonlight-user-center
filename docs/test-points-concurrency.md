# 积分并发一致性整改 — 测试报告（2.6.0）

- 日期：2026-10-30
- 任务书：《AT8 Moonlight User Center 2.5.9 → 2.6.0 积分并发一致性整改》
- 修复版本：**2.6.0**
- 环境：本地隔离 WordPress 7.1.3 / PHP 8.3 / SQLite drop-in；并发测试使用 **10 个真实独立 PHP 进程**（`proc_open`，各自完整 bootstrap）
- 交付 ZIP：`at8-moonlight-user-center-2.6.0.zip`（50 文件 / 165,177 B / SHA-256 `0f579e15ff2d4e1b533f0e3895bb57c3ad2d19ee7025a534768cba3b49c926b0`）

## 一、任务书所指缺陷的确认

任务书 P1 描述的场景：

1. A 写入积分流水
2. A 暂停超过 `LOCK_TTL`
3. B 接管锁并读取流水
4. B 更新余额
5. A 恢复，发现锁失效，删除自己的流水
6. B 的余额仍包含 A 已删除的流水

**缺陷成立**。2.5.9 的防线是"每次写入前 `renew_lock()` 复核所有权 + 失败则删除自己刚写的流水行"。问题有两处结构性弱点：

- 复核与写入之间仍有窗口（复核通过 → 写入前进程被挂起）；
- 第 5 步"删除自己的行"本身就是对已提交数据的二次修改，而第 4 步已经把这行算进余额快照——余额缓存与流水因此可能永久不一致。

按任务书要求（"不允许在锁失效后直接删除流水，却不重新保证余额缓存正确"），本轮**没有**再加大 TTL、也没有再加一次 `renew_lock()`，而是**取消了"锁 + 事后补偿"这一整个模型**。

## 二、新模型：单事务 + 版本号写在 UPDATE 语句里

### 2.1 存储

| 表 | 用途 |
|---|---|
| `{prefix}at8_mluc_points_ledger` | 只追加的流水，一次变动一行。`UNIQUE KEY idem_key (user_id, idem_key)` 由数据库保证"同一订单只发一次" |
| `{prefix}at8_mluc_points_account` | 每用户一行：`balance`（余额）+ `fence`（版本号） |

两张表都显式 `ENGINE=InnoDB`（`AT8_MLUC_Points::install()`），因此账户行与流水行可以处在同一个事务里。

### 2.2 一次变动 = 一个事务、两条语句

```sql
START TRANSACTION;

UPDATE {prefix}at8_mluc_points_account
   SET balance = balance + :amount,
       fence   = :next
 WHERE user_id = :user
   AND fence   = :seen        -- 版本号就在写入语句的 WHERE 里
   AND balance + :amount >= 0; -- 透支守卫也是全有或全无

INSERT INTO {prefix}at8_mluc_points_ledger (..., fence, idem_key) VALUES (...);

COMMIT;
```

- **版本号在写入语句内部校验**（任务书第 4 条）。请求读到的 `fence` 与行上的 `fence` 不一致时，MySQL/InnoDB 在行锁释放后重新求值 WHERE，命中 0 行——旧请求**在数据库层**被拒绝，不存在"复核通过但写入仍发生"的窗口。
- **没有锁，也没有 TTL**（任务书第 5 条不适用：不是靠改大 TTL 解决，而是不再需要租约）。请求被挂起多久都不会持有任何可过期的东西。
- **不透支**：`balance + :amount >= 0` 是同一语句内的条件，8/10 个并发扣款只会有能装下的那一个成功。
- **不删流水**（任务书第 6 条）：失败即 `ROLLBACK`，账户行与流水行一起回退，**从不**先写后删。
- **失败回滚**（任务书第 8 条）：任何一条语句失败都 `ROLLBACK`，不留半成品。
- **幂等**：`UNIQUE (user_id, idem_key)`，重复回调的 INSERT 失败 → 回滚自己的余额变更 → 返回赢家写入的余额。

### 2.3 关于"事务是否真的生效"

任务书要求"不能只把 PHP 代码包在 START TRANSACTION 和 COMMIT 之间就声称已经解决"。本轮逐项验证：

| 验证项 | 方法 | 结果 |
|---|---|---|
| 表引擎 | `CREATE TABLE ... ENGINE=InnoDB`（`install()`）；生产环境 MySQL 默认 InnoDB | 代码层强制 |
| 事务是否真的回滚 | 事务内插入一行后 `ROLLBACK`，再查行数 | 0 行（`atomicity_test.php` 断言） |
| 语句失败是否连带回滚 | 用 `query` 过滤器把流水 INSERT 改成必失败 SQL，检查账户余额与版本号 | 余额、版本号均回退；无孤立流水（矩阵 + 并发套件各有一组断言） |
| 隔离级别下的旧版本写入 | 直接执行带旧 `fence` 的 UPDATE | 影响 0 行，新余额不变 |
| 崩溃中断 | 子进程在事务中途 `exit(0)`（真实进程死亡） | 余额不变、无流水行，重试恰好一次 |

**已知限制（如实记录）**：本地测试站的数据库是 SQLite drop-in（无真实 InnoDB 行锁/隔离级别），并发语义由"整库写锁 + 唯一索引 + 版本 CAS"共同保证。InnoDB 下的表现需在 MySQL 环境复核，标记为 **NOT RUN**（见第六节）。

## 三、任务书要求的 10 项并发测试（真实多进程）

测试脚本：`docs/tests/concurrency_test.php` + `docs/tests/conc_worker.php`（父进程只调度，每个写入/观测都在独立 PHP 进程中完成）。

| # | 场景 | 断言 | 结果 |
|---|---|---|---|
| 1 | 同一用户 10 个请求同时 +10 | 10/10 成功；10 行；合计 100；余额 100 | **PASS** |
| 2 | 同一用户 10 个请求同时 -5（初始 100） | 10/10 成功；11 行；合计 50；余额 50 | **PASS** |
| 2b | 余额 7，10 个并发 -5 | 仅 1 笔成功；余额 2；不为负 | **PASS** |
| 3 | 两笔不同订单同时发放（rate 2.0，各 10.00） | 2/2 成功；2 行；合计 40 | **PASS** |
| 4 | 同一订单 10 次并发回调 | 恰好 1 行；只记 20 分 | **PASS** |
| 5 | 写入者把事务持有 3 秒（远超任何 TTL），期间 3 个请求尝试写 | 持有者正常结束；余额 = 流水合计；不为负 | **PASS**（rollback / commit 各一轮） |
| 6 | 持有者"写完流水后暂停"，另一请求完成写入，持有者恢复 | 回滚轮：持有者的行完全消失（15 = 5×3）；提交轮：持有者只计一次（25 = 10 + 5×3） | **PASS** |
| 7 | 过期写者用旧版本号写余额 | UPDATE 影响 0 行；新余额不变（25） | **PASS** |
| 8 | 数据库拒绝写入（流水 INSERT 被改成必失败） | 报错；余额不变；无流水残留；重试成功 | **PASS** |
| 9 | 请求进程在事务中被杀（`exit(0)`） | 余额不变；无流水行；重试恰好一次 | **PASS** |
| 10 | 提交后崩溃（订单标记未写），回调重试 | 只发一次（20）；订单键只有 1 行 | **PASS** |

**总计 54 条断言 / 0 失败**（`==== 54 passed, 0 failed ====`）。

附加：每个场景结束都会核对两条不变量——`balance == SUM(ledger.amount)`、`balance >= 0`。

## 四、存储层套件（`docs/tests/atomicity_test.php`）

| 断言组 | 结果 |
|---|---|
| 两张表存在；`UNIQUE (user_id, idem_key)` 索引存在 | PASS |
| 版本号 0→1→2 单调；每行流水记录落到当时的版本 | PASS |
| 旧版本号写入影响 0 行、新余额不变 | PASS |
| 流水插入失败 → 余额与版本号一起回滚、无孤立行 | PASS |
| 键幂等：重复调用不写第二行；裸 INSERT 被唯一索引拒绝 | PASS |
| 旧数据迁移：余额与条数完全一致；重复读取不重复写入；迁移后账户从版本 0 开始；迁移后仍可正常变动 | PASS |
| `reconcile()` 能从流水重建被手工篡改的账户行 | PASS |
| 零金额为 no-op；透支被拒且零残留；恰好花完允许 | PASS |

**32 条断言 / 0 失败**。

## 五、旧数据迁移（不丢失历史积分）

- 首次读取某用户时按需迁移（`migrate_user()`），迁移本身在事务内完成，账户行 `PRIMARY KEY` 即互斥：并发首读只会有一个成功，其余等它提交后读同一行。
- 迁移后余额与 2.5.9 的 `balance()` **逐用户相等**：本次对 23 个老用户实测 `users_with_entries_same=23 mismatched=0 total_before=2669 total_after=2669`。
- 只有 `at8_mluc_points` 余额、没有任何流水行的极老账户：导入为一条 `Opening balance`（source=`migrate`），余额不丢。
- **旧 meta 一律保留不删**（`at8_mluc_points_entry` / `at8_mluc_points_log` / `at8_mluc_points`）。
- **迁移保留原始幂等键**：已发放过的订单迁移后 `has_entry('purchase:<id>')` 仍为真，重复回调不会再发一次（升级实测 70 → 70）。
- 升级实测（2.5.9 → 2.6.0）：余额 70 → 70，流水 2 → 2 行，`key_kept=true`，重发不变，升级后新增变动正常（→ 75）。
- **过程记录**：本轮第一次升级测试发现迁移把原始键改写成了 `legacy:<hash>`，导致升级后同一订单可再发一次 —— 已修复（保留原键；若旧数据里同键重复，第二条回退到 `legacy:` 指纹键以免丢历史），并补了 2 条回归断言。

## 六、其它验收项

| 项 | 结果 |
|---|---|
| `php -l` 全量（40 个 PHP 文件） | **0 失败** |
| Plugin Check 2.1.0（对 ZIP 解压目录扫描） | **0 ERROR / 25 WARNING**（余下均为插件既有项：`$_POST` 读取、payments 直连查询、`meta_query`） |
| Free 独立回归 | **76 / 0** |
| Free + Pro 联合回归 | **64 / 0** |
| 后端矩阵（含积分/P0 项） | **50 / 0** |
| 并发套件 | **54 / 0** |
| 存储层套件 | **32 / 0** |
| 卸载（delete_data=1） | 两张表被 DROP、`at8_mluc_%` usermeta 归零、`at8_mluc_points_db` 删除；核心数据不变（users 26→26、posts 30→30、comments 1→1） |
| 卸载（默认保留） | 两张表与数据保留，仅清运行态 |
| 隐私导出/擦除 | 导出含积分流水分组；擦除同时删除该用户的流水与账户行 |
| PHP 7.4 实机 | **NOT RUN**（无 7.4 环境；代码未使用 7.4 以上语法） |
| MySQL/InnoDB 实机并发 | **NOT RUN**（本地为 SQLite drop-in；见 2.3 的限制说明） |
| 多站点 | **NOT RUN** |

## 七、改动清单

| 文件 | 改动 |
|---|---|
| `includes/class-points.php` | 存储层重写：两张表、事务、版本 CAS、唯一幂等键、懒迁移、`reconcile()`；删除 per-user 锁 / claim 行 / TTL / fence 租约 / 事后删行补偿 |
| `includes/class-activator.php` | 激活时建表；文件头说明同步更新 |
| `at8-moonlight-user-center.php` | bootstrap 在 `plugins_loaded` 调 `maybe_install()`，插件原地升级也能建表 |
| `includes/class-privacy.php` | 导出增加积分流水分组；擦除同时删流水与账户行 |
| `uninstall.php` | `delete_data=1` 时 DROP 两张表并删 `at8_mluc_points_db` |
| `readme.txt` / `README.md` | 版本 2.6.0 + changelog |
| `docs/tests/*` | 新增 `concurrency_test.php`（10 场景）、`atomicity_test.php`；移除已无意义的 `lock_semantics_test.php` |
