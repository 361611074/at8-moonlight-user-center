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

---

# 2.6.1 补充：迁移、事务与修复的失败路径

- 日期：2026-10-30
- 任务书：《AT8 Moonlight User Center 2.6.0 → 2.6.1 最终整改任务》
- 交付 ZIP：`at8-moonlight-user-center-2.6.1.zip`（50 文件 / 169,845 B / SHA-256 `305325f925731fcc225af0f10cadb184362f7f48dbf54bedcbd1e83f2066cb3d`）
- 测试脚本：`docs/tests/migration_test.php`（41 断言）

## 一、逐条对应

| 任务书 | 实现 | 位置 |
|---|---|---|
| P1-01 逐条检查 `insert_movement()` | 每条迁移流水都检查返回值；`insert_movement()` 通过出参回传驱动错误原文 | `migrate_movement()` / `insert_movement()` |
| P1-01 只有明确 UNIQUE 冲突才生成 fingerprint | 迁移**先查后写**：`has_entry()` 命中同一键才改用指纹键，不再靠"制造冲突再补救" | `migrate_movement()` |
| P1-01 连接错误 / SQL 错误 / 表不存在 / 字段不匹配必须终止 | 任何非重复类错误都返回 false → 整笔 ROLLBACK；表结构问题在写入前由 `schema_ready()` 拦下 | `migrate_movement()` / `schema_ready()` |
| P1-01 任一流水失败必须 ROLLBACK | 每条失败分支都显式 `rollback()` 后才返回错误 | `migrate_user()` |
| P1-01 流水未完整不得更新账户余额 | 只有全部流水写入成功后才执行 `UPDATE ... SET balance` | `migrate_user()` |
| P1-01 余额更新失败必须 ROLLBACK | `UPDATE ... AND fence = 0` 影响行数 ≠ 1 → ROLLBACK + 错误 | `migrate_user()` |
| P1-01 COMMIT 失败不得报成功 | `commit()` 返回实际结果；失败返回 `at8_mluc_points_tx` 且不写镜像、不返回余额 | `commit()` / `migrate_user()` |
| P1-01 失败不留半迁移状态 | 全部写入在单事务内；失败整笔回滚，账户行一起消失 | `migrate_user()` |
| P1-01 保留旧 usermeta | 迁移只读不删，`at8_mluc_points_entry`/`_log`/`at8_mluc_points` 原样保留 | `legacy_entries()` |
| P1-02 begin() 必须检查结果 | `begin()` 返回 bool，失败时 `adjust()`/`migrate_user()` 直接返回错误，不写任何东西 | `begin()` |
| P1-02 commit() 必须检查结果 | 同上；失败时不触发 `at8_mluc_points_adjusted` | `commit()` / `adjust()` |
| P1-02 事务失败必须回滚 | `commit()` 在 COMMIT 失败时补一次 ROLLBACK，避免把连接留在幽灵事务里 | `commit()` |
| P1-02 兼容 WP 请求生命周期 | 每个请求最多开一层事务；静态 `$tx_mode` 记录状态，重复 `begin()` 直接拒绝（不静默加入外层事务） | `$tx_mode` |
| P1-02 与外部插件事务冲突 | 开事务前用 `SELECT @@in_transaction` 检测；检测到外层事务时改用 `SAVEPOINT at8_mluc_points_tx`，提交/回滚只作用于自己的 SAVEPOINT | `has_open_transaction()` / `begin()` |
| P1-03 两表真实存在 | `SHOW TABLES LIKE` 逐一核对 | `schema_problems()` |
| P1-03 必须 InnoDB | `SHOW TABLE STATUS` 读 Engine，非 InnoDB 记为问题（服务器不报告引擎时跳过） | `schema_problems()` |
| P1-03 UNIQUE 索引真实存在 | `SHOW INDEX` 按 `Seq_in_index` 排序后精确比对 `(user_id, idem_key)` | `schema_problems()` |
| P1-03 account 主键存在 | `SHOW KEYS` 找 `PRIMARY` + `user_id` | `schema_problems()` |
| P1-03 字段类型与写入逻辑一致 | `SHOW COLUMNS` 逐列比对类型前缀（bigint/int/varchar） | `column_problems()` |
| P1-03 dbDelta 失败不得写 DB_OPTION | 先验证后 `update_option`；验证失败返回 `WP_Error` 且不写选项 | `install()` |
| P1-03 验证成功才 schema_ok | `self::$schema_ok = true` 只在验证通过后设置 | `install()` / `schema_ready()` |
| P1-03 不符合要求必须明确报错 | `adjust()`/`migrate_user()`/`reconcile()` 先过 `schema_ready()`，失败返回 `at8_mluc_points_schema` | `schema_ready()` |
| P2-01 不能用旧快照覆盖新余额 | `reconcile()` 读版本 → 事务内求和 → `UPDATE ... WHERE fence = 读到的版本` | `reconcile()` |
| P2-01 事务 + 版本 CAS | 同上，CAS 失败即整笔重来 | `reconcile()` |
| P2-01 修复后同步验证 | `verify_balance()`：账户余额与流水合计逐一比对 | `verify_balance()` |
| P2-01 不能丢失正在发生的变动 | CAS 失败 → 重新读取新快照重试，不覆盖 | `reconcile()` |
| P2-01 失败必须回滚 | 每次 CAS 失败与提交失败都回滚 | `reconcile()` |

## 二、任务书要求的 12 项测试

| # | 场景 | 结果 |
|---|---|---|
| 1 | 正常旧版本积分迁移 | **PASS**（余额一致、逐条成行、原键保留、meta 未删、版本从 0 开始、迁移后可继续变动） |
| 2 | 第 1 条流水写入失败 | **PASS**（0 条流水、无账户行、读取回落到旧 meta 合计；下一次读取迁移成功） |
| 3 | 中间一条流水写入失败 | **PASS**（已写入的前几条一并回滚，账户行未提交，重试完整迁移） |
| 4 | 历史流水存在重复幂等键 | **PASS**（两条都保留，第一条保原键、第二条用指纹键；重复回调不再加分） |
| 5 | 数据库 COMMIT 失败 | **PASS**（迁移：无残留、读取回落；`adjust()`：返回错误、**不触发成功回调**、余额不变） |
| 6 | 数据库表不存在 | **PASS**（返回 `at8_mluc_points_schema` 明确错误且不写入；恢复后自动重建并正常写入） |
| 7 | 积分表不是 InnoDB | **NOT RUN**（本机 SQLite 集成硬编码报告 InnoDB，无法造出非 InnoDB 表）；引擎检查代码已实现，实测对健康表返回 InnoDB 并通过 |
| 8 | 同一订单 10 个并发请求 | **PASS**（`concurrency_test.php` 场景 4：恰好 1 行、只加一次） |
| 9 | 同一用户 10 个并发加分 | **PASS**（场景 1：10/10 成功、10 行、合计 100） |
| 10 | 同一用户 10 个并发扣分 | **PASS**（场景 2：合计 50；2b：余额 7 时仅 1 笔成功且不破零） |
| 11 | reconcile 与 adjust 并发执行 | **PASS**（5 轮修复穿插 5 次变动：全部完成、余额 150 = 流水合计、无丢失无重复） |
| 12 | 数据库死锁后重试 | **部分 PASS / 真实死锁 NOT RUN**：用"读到过期版本 → CAS 命中 0 行 → 重试成功"复刻了重试路径（断言 2 次 CAS、最终 +5 恰好一次）；真实 InnoDB 死锁需要 MySQL 环境 |

## 三、过程中发现的环境事实（影响实现）

在 **SQLite 集成**下，事务内任一语句报错会**整笔回滚并结束事务**（后续语句自动提交）。因此：

- 原方案"先故意制造 UNIQUE 冲突，失败后再补写指纹键"会留下半迁移状态（实测：1 条流水残留、账户行消失）。
- 改为**迁移前先 `has_entry()` 查重**，从不制造该错误；运行时 `adjust()` 的冲突分支只用于并发回调，且回滚后立即返回赢家余额。
- 这也意味着 `rollback()` 在这种驱动上可能是空操作（事务已被驱动结束），代码不依赖其返回值，只依赖"失败即不推进"。

## 四、回归

| 套件 | 结果 |
|---|---|
| `migration_test.php`（本轮新增） | 41 / 0 |
| `atomicity_test.php` | 32 / 0 |
| `concurrency_test.php`（10 场景） | 54 / 0 |
| `v4_matrix_test.php` | 50 / 0 |
| Free 独立 `reg_test.php` | 76 / 0 |
| Free + Pro `pro_test.php` | 64 / 0 |
| `php -l` 全量（40 文件） | 0 失败 |
| Plugin Check 2.1.0（ZIP 解压扫描） | **0 ERROR / 25 WARNING**（与 2.5.x 基线相同，均为既有项） |
| 升级 2.6.0 → 2.6.1 | 余额 40→40、流水 1→1、`verify_balance` 通过、升级后变动正常（42） |
| MySQL/InnoDB 实机并发、PHP 7.4 实机、多站点 | **NOT RUN** |

---

# 2.6.2 补充：数据库读取错误不再被当成"零/不存在"

- 日期：2026-10-31
- 任务书：《AT8 Moonlight User Center 2.6.1 → 2.6.2 最终数据库安全整改》
- 交付 ZIP：`at8-moonlight-user-center-2.6.2.zip`（50 文件 / 170,860 B / SHA-256 `0fbbfd0d4440cac93666b2d22b7777d2c9f9f92148a935c3bc10994efc4e7265`）
- 测试脚本：`docs/tests/db_safety_test.php`（47 断言）

## 一、P1-01：reconcile() 查询失败会把余额写零

`get_var()` 在**查询失败**和**没有行**时都返回 null，而 `SUM` 外面套了 `COALESCE` 后本来必定返回一行，所以 null 只可能是失败——原代码 `(int) $wpdb->get_var(...)` 把这两种情况压成了 0，一旦读失败就会把真实余额覆盖成 0。

修复：新增 `ledger_sum()`，`null === $sum || last_error 非空` 时返回 `WP_Error('at8_mluc_points_read')`；`reconcile()` 拿到错误先 `ROLLBACK` 再返回，**不更新账户余额、不写 meta 镜像**；`verify_balance()` 在同样情况下返回 false（"无法校验"而不是"一致"）。

## 二、P1-02：引擎验证不再"查不到就放行"

| 情况 | 2.6.1 | 2.6.2 |
|---|---|---|
| `SHOW TABLE STATUS` 报错 | 跳过检查 | schema 不合格（并指出表名） |
| 返回空结果 | 跳过检查 | schema 不合格 |
| 无 `Engine` 字段 | 跳过检查 | schema 不合格（no Engine field） |
| `Engine != InnoDB` | 不合格 | 不合格 |
| `Engine = InnoDB` | 通过 | 通过 |

`column_problems()` 同样处理：`SHOW COLUMNS` 报错或返回空 → 直接判定"该表的列无法读取"，不再当成"没有列问题"。

## 三、P1-03：所有读取都区分"没有记录"与"查询失败"

| 方法 | 2.6.1 | 2.6.2 |
|---|---|---|
| `account()` | `get_row()` 返回 null 就当作"没有账户"→ 触发迁移 | 先看 `last_error`，失败返回 `WP_Error`，**不触发迁移**（避免重复导入） |
| `has_entry()` | 返回 bool，失败即 false（会让第二次发放通过） | 返回 `bool|WP_Error`；调用方 `adjust()`/`migrate_movement()`/`is_duplicate_error()` 分别处理，失败时拒绝写入 |
| `legacy_entries()` | 返回数组，读失败当作空历史 | 先清 `last_error` 再读（缓存命中不会带旧错误），失败返回 `WP_Error`；`migrate_user()` 直接终止 |
| `reconcile()` / `verify_balance()` | 失败当 0 | 见 P1-01 |
| `schema_problems()` / `column_problems()` | 失败跳过 | 见 P1-02 |

`balance()` 作为展示用 API 仍是 int：账户读失败时回落到旧 meta 合计（只读、不写）；双重失败才返回 0，且绝不触发任何写入。

## 四、任务书要求的 10 项测试

| # | 场景 | 结果 |
|---|---|---|
| 1 | reconcile 正常修复余额 | **PASS**（4 → 42，读回一致） |
| 2 | reconcile 查询失败 | **PASS**（`at8_mluc_points_read`；余额 25 未被清零；meta 镜像未动；流水条数不变；`verify_balance` 返回 false） |
| 3 | reconcile 与 adjust 同时执行 | **PASS**（5 轮修复穿插 5 次变动，0 错误，150 = 流水合计） |
| 4 | SHOW TABLE STATUS 查询失败 | **PASS**（写入被拒、错误码 `at8_mluc_points_schema`、余额与流水均未变） |
| 5 | Engine 字段缺失 | **PASS**（同上） |
| 6 | 表不是 InnoDB | **PASS**（伪造 Engine=MyISAM，写入被拒） |
| 7 | 表缺少唯一索引 | **PASS**（把 SHOW INDEX 指向无该索引的表，写入被拒） |
| 8 | migrate_user 中途写入失败 | **PASS**（已写入的前一条一并回滚、无账户行、读取回落 13；重试两条都迁入） |
| 9 | COMMIT 失败 | **PASS**（返回错误、**不触发成功回调**、余额不变） |
| 10 | 相同订单重复回调 | **PASS**（连发 6 次只发 20 分、1 条流水） |
| 附加 | `has_entry()` 查询失败 | **PASS**（返回 WP_Error；带幂等的加分被拒且不写行，余额不变） |

四次 schema 失败的用例都验证了同一件事：**不只是报错，而且确实没有写入**（余额不变 + 流水条数不变）。

## 五、回归

| 套件 | 结果 |
|---|---|
| `db_safety_test.php`（本轮新增） | 47 / 0 |
| `migration_test.php` | 41 / 0 |
| `atomicity_test.php` | 32 / 0 |
| `concurrency_test.php`（10 场景） | 54 / 0 |
| `v4_matrix_test.php` | 50 / 0 |
| Free 独立 `reg_test.php` | 76 / 0 |
| Free + Pro `pro_test.php` | 64 / 0 |
| `php -l` 全量（40 文件） | 0 失败 |
| Plugin Check 2.1.0（ZIP 解压扫描） | **0 ERROR / 25 WARNING**（与基线一致） |
| 升级 2.6.1 → 2.6.2 | 覆盖安装后版本 2.6.2，全部套件重跑通过 |
| MySQL/InnoDB 实机、PHP 7.4 实机、多站点 | **NOT RUN** |

---

# 2.6.3 补充：事务边界、外部事务兼容与余额读取

- 日期：2026-10-31
- 任务书：《AT8 Moonlight User Center 2.6.2 → 2.6.3 精准整改》
- 交付 ZIP：`at8-moonlight-user-center-2.6.3.zip`（50 文件 / 173,616 B / SHA-256 `10d2d8fa45f3efd471435be3c43c07b165d22e8768082e66cb8bedd533765767`）
- 测试脚本：`docs/tests/transaction_test.php`（45 断言）

## 一、先纠正一个此前写错的前提

2.6.0 的报告里写过"SQLite 的 SAVEPOINT 会自动开启事务"。**这个说法对本项目实际使用的 SQLite 集成不成立**：它按 MySQL 语义实现，事务外执行 `SAVEPOINT` 是空操作，随后 `RELEASE SAVEPOINT` 直接报 `1305 SAVEPOINT ... does not exist`。实测证据：事务外 `SAVEPOINT` → 返回 0 无错误；`RELEASE SAVEPOINT` → false + 1305。

所以 2.6.3 不依赖这个假设，改为**先探测、再决定**。

## 二、P1-01：`has_open_transaction()` 改造为可判定的事务状态

旧实现只看 `@@in_transaction`，查询失败时返回 false（= 没有外部事务），正是任务书指出的"把失败当成没有外部事务"。

新实现 `probe_transaction_state()` 两级探测：

| 步骤 | 做法 | 结论 |
|---|---|---|
| 1 | `SELECT @@in_transaction` 返回 0 / 1 且无错误 | 直接采用（MySQL 5.7.36+ / MariaDB 10.3+ 精确） |
| 2 | 否则用一次性 SAVEPOINT 探测：`SAVEPOINT x` → `ROLLBACK TO SAVEPOINT x` | 能回滚 = **在事务中** → `savepoint` 模式；报错 = **无事务** → `own` 模式（SAVEPOINT 本身就是空操作，未改动任何数据） |
| 3 | 两级都拿不到答案（SAVEPOINT 都建不出来） | 返回 `WP_Error('at8_mluc_points_tx')`，**不写入** |

这样既满足"查询结果为 NULL 或无法识别时必须返回明确错误"（第 3 级），也不会因为 SQLite 不支持 `@@in_transaction` 而把整个插件判死（第 2 级兜底），且**任何情况下都不会盲目 `START TRANSACTION`**——那会隐式提交别人的事务。

## 三、P1-02：事务生命周期

| 要求 | 实现 |
|---|---|
| 自己的事务自己提交/回滚 | `begin()` 记录 `$tx_mode`（own / savepoint），`commit()`/`rollback()` 按模式走不同语句 |
| 外部事务必须用 SAVEPOINT | 探测到在事务中即走 `SAVEPOINT at8_mluc_points_tx` |
| SAVEPOINT 创建失败必须终止 | `begin()` 返回 `WP_Error`，调用方立即返回，零写入 |
| RELEASE 失败必须返回错误 | 返回 `at8_mluc_points_tx`，并**补一次 `ROLLBACK TO SAVEPOINT`** 撤销本次变动，避免留下半笔 |
| ROLLBACK TO 失败必须记录明确错误 | 触发 `at8_mluc_points_transaction_error` 动作 + `WP_DEBUG` 下写 error_log |
| 外部事务未提交时不得宣称已持久化 | savepoint 模式：**不写 usermeta 镜像**（缓存会活过回滚）、**不触发 `at8_mluc_points_adjusted`**，改发 `at8_mluc_points_adjusted_deferred` |
| 成功钩子与镜像同步时机 | 同上，只在 own 模式下执行 |
| 外部事务回滚不得留下镜像/副作用 | `grant_for_order()` 的 `_at8_mluc_points_awarded` 快路径标记在 deferred 模式下也跳过（否则缓存会残留"已发放"） |

## 四、P2-01：余额读取

- `balance()` 改为返回 `int|WP_Error`：账户读失败 → 回落旧 meta；旧 meta 也读失败 → **返回 WP_Error，不再返回 0**。
- `can_afford()` 读不到余额时返回 false（不能确认就不放行兑换）。
- 账户页积分显示：读失败显示 `—`；兑换卡显示 "temporarily unavailable"；后台用户编辑页显示错误信息而不是 0。
- `legacy_entries()` 改为**直接查 usermeta 表**（此前走 meta API，失败与空值无法区分，且会拿到缓存副本）；`legacy_total()` 同样直接读，失败返回 WP_Error。

## 五、任务书要求的 12 项测试

| # | 场景 | 结果 |
|---|---|---|
| 1 | 正常独立事务加分 | **PASS**（+40，1 行，镜像写入） |
| 2 | 正常独立事务扣分 | **PASS**（-15 → 25，2 行，余额=流水合计） |
| 3 | 外部事务中执行积分操作 | **PASS**（外层事务内 +10 生效于事务内；只触发 deferred，不触发 confirmed；镜像未写） |
| 4 | 外部事务最终 COMMIT | **PASS**（提交后 35 分、3 行、余额一致） |
| 5 | 外部事务最终 ROLLBACK | **PASS**（回到 35、3 行、镜像未被污染、confirmed 动作未触发） |
| 6 | `SELECT @@in_transaction` 查询失败 | **PASS**（回落到 SAVEPOINT 探测仍正确加分；两级探测都失败时返回 `at8_mluc_points_tx` 且零写入） |
| 7 | SAVEPOINT 创建失败 | **PASS**（返回事务错误，余额不变） |
| 8 | RELEASE SAVEPOINT 失败 | **PASS**（返回未确认错误、触发 transaction_error、本次变动被撤销、无多余流水） |
| 9 | ROLLBACK TO SAVEPOINT 失败 | **PASS**（报错并触发 transaction_error；外层回滚后余额不变） |
| 10 | 旧版 usermeta 查询失败 | **PASS**（`balance()` 返回 `at8_mluc_points_read` 而不是 0；未发生迁移；恢复后正常迁移 7 分） |
| 11 | reconcile 与 adjust 并发执行 | **PASS**（5 轮交替，0 失败，150 = 流水合计） |
| 12 | 同一订单 10 次回调 | **PASS**（只发 20 分、1 条流水） |

真实多进程并发（10 进程）在 `concurrency_test.php`：**54/0**，其中场景 4 就是 10 个进程同时打同一订单。

## 六、回归

| 套件 | 结果 |
|---|---|
| `transaction_test.php`（本轮新增） | 45 / 0 |
| `db_safety_test.php` | 47 / 0 |
| `migration_test.php` | 41 / 0 |
| `atomicity_test.php` | 32 / 0 |
| `concurrency_test.php`（10 场景，真实进程） | 54 / 0 |
| `v4_matrix_test.php` | 50 / 0 |
| Free 独立 `reg_test.php` | 76 / 0 |
| Free + Pro `pro_test.php` | 64 / 0 |
| `php -l` 全量（40 文件） | 0 失败 |
| Plugin Check 2.1.0（ZIP 解压扫描） | **0 ERROR / 25 WARNING**（与基线一致） |
| MySQL/InnoDB 实机、PHP 7.4 实机、多站点 | **NOT RUN** |

---

# 2.6.4 补充：事务状态未知即拒绝、SAVEPOINT 清理校验、reconcile 错误分类

- 日期：2026-10-31
- 任务书：《AT8 Moonlight User Center 2.6.3 → 2.6.4 事务边界最终整改》
- 交付 ZIP：`at8-moonlight-user-center-2.6.4.zip`（50 文件 / 174,946 B / SHA-256 `1e52171f58596619c72f39e92b27dcbc95ae8818d649941af7c29df2b1af02b3`）
- 测试脚本：`docs/tests/tx_boundary_test.php`（58 断言）

## 一、先记录本次实测到的驱动行为（方案依据）

在本项目测试站的 SQLite 集成（MySQL 语义兼容层）上实测：

| 语句 | 事务外 | 事务内 |
|---|---|---|
| `SELECT @@in_transaction` | `NULL`，无错误（不支持该变量） | `NULL`，无错误 |
| `SAVEPOINT x` | 返回 0，无错误（空操作） | 返回 0，无错误 |
| `ROLLBACK TO SAVEPOINT x` | `false` + `SQLSTATE[42000] … 1305 SAVEPOINT x does not exist` | 返回 0，无错误 |
| `RELEASE SAVEPOINT x` | `false` + 1305 | 返回 0，无错误 |
| `UPDATE …` 未命中行 | 返回 0，无错误 | 返回 0，无错误 |

`1305 / SQLSTATE 3B001` 是 MySQL 官方对"该 SAVEPOINT 不存在"的答案，而事务外 `SAVEPOINT` 恰恰是空操作——所以这个**特定错误**是"当前没有事务"的确定性证据；其他任何错误都不能这样解释。2.6.4 的实现与判定都建立在这一点上。

## 二、P1-01：`probe_transaction_state()`

| 任务书要求 | 2.6.3 行为 | 2.6.4 实现 |
|---|---|---|
| 区分"查询失败"与"状态为 0" | 失败 → 落到 SAVEPOINT 探测 | 失败只当失败；只有 `'0'` / `'1'` 且无错误才采信 |
| 无法解释的数据库错误返回 WP_Error | — | 新增 `unknown_transaction_state()`，统一返回 `at8_mluc_points_tx`，消息含 "could not be determined" 与原始驱动错误 |
| SAVEPOINT 创建失败不得假定无事务 | 已返回 WP_Error | 保持，并在消息中带上驱动原文或 "the database refused to create a savepoint" |
| **SAVEPOINT 回滚失败不得返回 own** | **返回 `own`（缺陷）** | 仅当错误是 1305 / 3B001 / "savepoint … does not exist" 时返回 `own`；其余一律 WP_Error |
| 无法确定时禁止 START TRANSACTION | 部分 | `begin()` 拿到 WP_Error 立即返回，零写入 |
| 不误提交调用方事务 | 是 | 是（`own` 只可能来自确定性证据） |
| 删除/修正误导性的 `has_open_transaction()` | 仍存在（探测失败即 false） | **已删除**；断言用 `get_class_methods()` 确认不再存在 |

探测失败且无法判定时，会先 `RELEASE SAVEPOINT at8_mluc_points_probe` 清掉探针（结果忽略，因为它没有携带任何数据变更），再返回错误，避免在调用方事务里留下残留标记。

## 三、P1-02：`commit()` 的 SAVEPOINT 清理

| 要求 | 实现 |
|---|---|
| RELEASE 失败时执行 ROLLBACK TO | 是 |
| 检查 ROLLBACK TO 的结果 | 是（`run_sql()` 判定，含 `last_error` 检查） |
| 回滚失败调用 `report_tx_error()` | 是（两条分支都走） |
| 明确告知外层事务可能仍含未确认变动 | 消息原文：`… undoing the movement inside the enclosing transaction failed as well (…). That transaction may still carry the unconfirmed points movement, so it must not be treated as if the movement were gone.` |
| 不得伪装成普通积分失败 | 错误码固定 `at8_mluc_points_tx`，并触发 `at8_mluc_points_transaction_error` |
| 不得触发成功钩子 | 调用方在 `commit()` 返回错误后立即返回，不触发 `at8_mluc_points_adjusted`（测试断言 confirmed 未触发） |
| 不得同步 usermeta 镜像 | 同上（测试断言镜像值未变） |

`rollback()` 侧保持原有严格性（ROLLBACK TO / ROLLBACK 失败都 `report_tx_error()`）。

## 四、P2-01：`reconcile()` 的 CAS 结果分类

| UPDATE 返回 | 2.6.3 | 2.6.4 |
|---|---|---|
| `false`（驱动拒绝） | 当并发冲突 → 重试 40 次 → 报 `at8_mluc_points_busy` | **回滚后返回 `at8_mluc_points_db`**，只执行一次，不重试 |
| `0`（CAS 不匹配） | 回滚后重试 | 回滚后重试（检查回滚结果，失败即返回） |
| `1` | 成功 | 成功 |
| 其他（>1） | 归入"非 1"重试 | 回滚后返回 `at8_mluc_points_db`（"touched N rows instead of one"） |

重试上限沿用 `MAX_ATTEMPTS = 40`（配 `RETRY_USLEEP = 15ms`，递增至 4 倍），有界、不无限。

## 五、任务书要求的 12 项测试

| # | 场景 | 结果 |
|---|---|---|
| 1 | `@@in_transaction` 返回 0 | **PASS**（加分成功、走 own 路径、**未发起 SAVEPOINT 探测**、镜像写入） |
| 2 | `@@in_transaction` 返回 1 | **PASS**（外层事务内加分、只触发 deferred、镜像不写、外层 COMMIT 后持久） |
| 3 | `@@in_transaction` 查询失败 | **PASS**（回落到 SAVEPOINT 探测并正确加分；两级都失败时返回 `at8_mluc_points_tx` 且零写入） |
| 4 | SAVEPOINT 创建失败 | **PASS**（返回事务错误、余额不变、无新增流水） |
| 5 | SAVEPOINT 创建成功但 ROLLBACK TO 失败（非 1305 的"表不存在"错误） | **PASS**（返回事务错误、消息含"could not be determined"、余额不变、无新增流水） |
| 6 | RELEASE SAVEPOINT 失败 | **PASS**（返回未确认错误、消息含"undone inside the enclosing transaction"、transaction_error 触发 1 次、无成功钩子、镜像未写、外层 COMMIT 后余额仍为 10） |
| 7 | RELEASE 与 ROLLBACK TO 均失败 | **PASS**（错误消息含"may still carry the unconfirmed points movement"、transaction_error 触发 1 次） |
| 8 | 外部事务最终 COMMIT | **PASS** |
| 9 | 外部事务最终 ROLLBACK | **PASS**（变动消失、无 confirmed 钩子、账目仍一致） |
| 10 | reconcile 的 UPDATE 返回 false | **PASS**（`at8_mluc_points_db`，**不是** `at8_mluc_points_busy`；UPDATE 只执行 1 次） |
| 11 | reconcile 的 UPDATE 返回 0 | **PASS**（确定性地造出过期版本 → 重试 → 返回流水合计 100，账户被读取 2 次） |
| 12 | reconcile 的 UPDATE 返回 1 | **PASS**（返回 100、UPDATE 恰好 1 次、`verify_balance` 通过、镜像刷新） |

附加断言：`is_missing_savepoint_error()` 的分类（1305 ✅ / 3B001 ✅ / "表不存在" ✅否 / 空错误 ✅否 / 锁超时 ✅否）；`has_open_transaction()` 已从类中移除。

## 六、回归

| 套件 | 结果 |
|---|---|
| `tx_boundary_test.php`（本轮新增） | 58 / 0 |
| `transaction_test.php` | 45 / 0 |
| `db_safety_test.php` | 47 / 0 |
| `migration_test.php` | 41 / 0 |
| `atomicity_test.php` | 32 / 0 |
| `concurrency_test.php`（10 场景，真实 10 进程） | 54 / 0 |
| `v4_matrix_test.php` | 50 / 0 |
| Free 独立 `reg_test.php` | 76 / 0 |
| Free + Pro `pro_test.php` | 64 / 0 |
| `php -l` 全量（40 文件） | 0 失败 |
| Plugin Check 2.1.0（ZIP 解压扫描） | **0 ERROR / 25 WARNING**（与基线一致） |
| 升级 2.6.3 → 2.6.4 | 余额 65→65、流水 2→2、`verify_balance` 通过、升级后新增变动 70、`reconcile` 70 且一致 |
| MySQL/InnoDB 实机（`@@in_transaction` 主路径、真实死锁）、PHP 7.4 实机、多站点 | **NOT RUN** |

---

# 2.6.5 补充：事务失败分类、迁移回滚校验、SAVEPOINT 双信号探测

- 日期：2026-11-01
- 任务书：《AT8 Moonlight User Center 2.6.5 WordPress.org 官方终审整改任务书》
- 交付 ZIP：`at8-moonlight-user-center-2.6.5.zip`（50 文件 / 177,510 B / SHA-256 `a5b24600e7f1c5be56287943b7531cf8f2fc78ca56acd6a3f2fbeb8aa3d0423a`）
- 新增测试：`docs/tests/tx_final_test.php`（114 断言）

## 一、P1-01：COMMIT 失败后的 ROLLBACK 检查

`commit()` 原实现在 COMMIT 失败后无条件 `run_sql('ROLLBACK')`，不看结果。现在：

| 情况 | 处理 | 返回 |
|---|---|---|
| COMMIT 成功 | — | `true` |
| COMMIT 失败，ROLLBACK 成功 | 记录 COMMIT 原始错误；`transaction_state = rolled_back`；不触发成功钩子；不写镜像 | `WP_Error('at8_mluc_points_tx')`，`failure = commit_failed` |
| COMMIT 失败，ROLLBACK 失败 | 同时记录两个错误；`transaction_state = unknown`；`failure = transaction_state_unknown`；调用 `report_tx_error()` | 同上，并明确"状态未知、不自动重试" |

`adjust()` / `migrate_user()` / `reconcile()` 在 `commit()` 返回错误时直接返回，**不重试**——状态未知时重放可能重复入账。测试用「COMMIT 语句被改写为必失败」实测：COMMIT 只执行 1 次。

## 二、P1-02：migrate_user() 全部失败路径

四处失败分支（账户行创建、任一条历史流水、开户余额流水、余额写入）全部改走 `fail_migration()`：

```
失败 → rollback()
     ├─ 回滚成功 → 返回原始错误（业务失败照旧，如 at8_mluc_points_save / at8_mluc_points_schema）
     └─ 回滚失败 → 返回 transaction_state_unknown，同时携带 original_error + rollback_error
```

迁移的"全或无"再次实测：断掉第 1 条 / 中间一条 / 最后一条流水 → 已写入的行**一并消失**、账户行不存在、旧 meta 一条不删；恢复后重试完整导入且余额 == 流水合计。

## 三、P1-03：统一的事务错误结构

所有事务错误统一为 `WP_Error('at8_mluc_points_tx')`，`get_error_data()` 固定包含：

| 字段 | 说明 |
|---|---|
| `operation` | begin / commit / rollback / release / probe / migrate |
| `failure` | begin_failed / commit_failed / rollback_failed / release_failed / transaction_state_unknown |
| `transaction_state` | committed / rolled_back / unknown |
| `original_error` | 最初 SQL 或业务错误（清洗后 200 字符） |
| `rollback_error` | 回滚失败原因 |
| `user_id` | 涉及用户 |
| `context` | adjust / migrate / reconcile / probe |

**驱动原文不再进入面向用户的消息**，只进错误数据与 `WP_DEBUG` 日志；日志只记录上述结构，不含 SQL 参数、密码、OAuth token 等敏感值；日志写入失败不会影响返回值。`at8_mluc_points_transaction_error` 动作新增第二个参数（结构数组），旧的一参回调不受影响。

## 四、P2-01：SAVEPOINT 全生命周期 + 探针双信号

- 创建、`ROLLBACK TO`、`RELEASE` 三处全部检查结果。
- `commit()`：RELEASE 失败 → 回滚到 SAVEPOINT（检查结果）。回滚成功但残留标记释放失败 → 同一条报告里带上 `release_error`（不产生第二条报告）；回滚也失败 → `unknown` + 明确"外层事务可能仍含未确认变动"。
- `rollback()`：`ROLLBACK TO` 失败 → `unknown`；成功后释放标记，释放失败单独上报但不改变"已撤销"的事实。
- **探测不再依赖单一错误码**：`@@in_transaction` 不可用时，要求两个独立信号一致——`ROLLBACK TO probe` 报"不存在" **且** `RELEASE probe` 也报"不存在"，才判定"无事务"。若回滚报不存在而释放成功（矛盾），或任一环节出现其他错误，一律 fail-closed 返回错误。探测失败时先尝试清除探针再返回。

## 五、任务书 A–E 测试（`tx_final_test.php`，114 断言）

| 组 | 覆盖 | 结果 |
|---|---|---|
| A COMMIT | 正常 / COMMIT 失败 / COMMIT+ROLLBACK 双失败 / 无成功钩子 / 无镜像 / 不重试（COMMIT 仅 1 次）/ 未知后连接仍可用 | **PASS** |
| B ROLLBACK | 普通失败+回滚成功（保留业务错误码）/ 回滚失败（tx + unknown + 双错误） | **PASS** |
| C migrate_user | 正常 / 重复迁移 / 账户初始化失败 / 首条·中间·最后一条流水失败 / 开户余额失败 / COMMIT 失败 / ROLLBACK 失败 / 两次读取并发迁移 / 余额==流水 | **PASS** |
| D SAVEPOINT | 外层事务内加分（deferred）/ **插件从不发 COMMIT·ROLLBACK**、只用自有 savepoint / 创建失败 / RELEASE 失败 / RELEASE+回滚双失败 | **PASS** |
| E 业务回归 | 加分 / 扣分 / 透支拒绝 / 同幂等键重复 / reconcile 正常 / reconcile 读失败不清零 | **PASS** |

## 六、WordPress 审核项自查

| 项 | 结果 |
|---|---|
| AJAX 处理器（11 个）/ nonce | 全部 `check_ajax_referer`（13 处） |
| 能力检查 | 21 处 `current_user_can` / 登录与节流守卫 |
| 输出转义 | 502 处 esc_* / wp_kses |
| SQL 参数化 | 未参数化的仅为事务控制语句与 `{$wpdb->prefix}` 拼接（无用户输入） |
| 任意文件写入 | 0 处 |
| readme 头 | Contributors x361611074 / Requires 5.8 / Tested up to 7.1（实机 WP 7.1.3）/ Requires PHP 7.4 / License GPL-2.0-or-later + URI |
| 主文件头 | Version 2.6.5 / Text Domain at8-moonlight-user-center / Domain Path /languages |
| Uninstall | `delete_data` 开关；开启时仅删本插件 option/meta/两张表，核心数据不动 |
| 外部服务披露 / 隐私 | readme External services + 隐私导出/擦除覆盖积分表 |

## 七、回归

| 套件 | 结果 |
|---|---|
| `tx_final_test.php`（新增） | 114 / 0 |
| `tx_boundary_test.php` | 58 / 0 |
| `transaction_test.php` | 45 / 0 |
| `db_safety_test.php` | 47 / 0 |
| `migration_test.php` | 41 / 0 |
| `atomicity_test.php` | 32 / 0 |
| `concurrency_test.php`（10 场景，真实 10 进程） | 54 / 0 |
| `v4_matrix_test.php` | 50 / 0 |
| Free 独立 `reg_test.php` | 76 / 0 |
| Free + Pro `pro_test.php` | 64 / 0 |
| `php -l`（40 文件） | 0 失败 |
| Plugin Check 2.1.0（ZIP 解压扫描） | **0 ERROR / 25 WARNING** |
| 升级 2.6.4 → 2.6.5 | 余额 100→100、流水 2→2、幂等键保留、重发不变、升级后变动 105、`reconcile` 105 且一致 |
| **真实 MySQL/MariaDB 环境** | **未执行**（本机为 SQLite 的 MySQL 语义兼容层，无 mysqld）；`@@in_transaction` 主路径、真实死锁、行锁隔离级别仍需真机复核 |

---

# 2.6.6 补充：Plugin Check 警告清零（0 ERROR / 0 WARNING）

- 日期：2026-11-01
- 触发：后台 Plugin Check 报表（2.6.5，25 WARNING，集中在 class-oauth.php 21 条、class-payments.php 4 条）
- 交付 ZIP：`at8-moonlight-user-center-2.6.6.zip`（50 文件 / 179,250 B / SHA-256 `9c515975c876bc5bf8eed81b5032b2955b68b4165725efe0828e1cd97f13706d`）

## 一、25 条警告的构成与处置

| 文件 | 条数 | 代码 | 处置 |
|---|---|---|---|
| class-oauth.php | 19 | `NonceVerification.Recommended/Missing` | **重构**：回调参数统一由 `callback_param()` 读取、Apple 姓名由 `callback_user_names()` 读取、跳转地址由 `requested_redirect()` 读取；调用处不再出现超全局变量 |
| class-oauth.php | 2 | `SlowDBQuery.slow_db_query_meta_key/meta_value` | **重构**：按 provider id 查找绑定改为 `usermeta` 上的一条索引查询（`find_bound_user()`），不再走"整批用户查询 + meta 过滤" |
| class-payments.php | 2 | `DirectDatabaseQuery.DirectQuery` | **保留 + 写明理由**：状态流转必须是条件更新（`meta_value='pending'` 才改），先读后写在并发回调下会双认领，meta API 无法表达 |
| class-payments.php | 2 | `SlowDBQuery.slow_db_query_meta_query` | **保留 + 写明理由**：一处每次最多 100 单、一处单个用户且条数受 `$limit` 限制，均走 postmeta 索引 |

结论：19 + 2 条通过**改代码**消除；4 条是 WP_Query / 原子条件更新的固有形态，保留并逐处注明理由（不是用注释掩盖，而是说明为什么必须这样写）。

## 二、过程中确认的两条 PCP 行为（影响后续所有清理）

1. **`phpcs:ignore` 确实有效**，但只对**赋值语句所在行**可靠；写在 `if (...)` / `elseif` 条件行的上一行时**不会生效**（2.6.5 里 19 条警告的真正原因就是这些 ignore 都贴在条件行上）。
2. **PCP 必须对"ZIP 解压目录"运行，不能对仓库目录运行**——仓库里的 `docs/tests/*.php`、`.gitignore` 会被一起扫描，凭空多出 200+ 条（含 ERROR）。`sync_local.py` 会把 `docs/` 同步进测试站，所以对测试站目录跑也会失真。

## 三、行为验证（重构后的针对性测试）

`oauth_refactor_test.php`（22 断言，未随包发布，放在 `_tools`）：

- `callback_param()`：GET 优先于 POST、值被 trim、缺省空串、标记被剥离、纯值不变
- `callback_user_names()`：两个字段读取与清洗、缺项为空、非数组载荷不报警、标记被剥离
- `requested_redirect()`：合法地址保留、`javascript:` 被丢弃、缺省空串
- `find_bound_user()`：命中用户、未知 id 返回 0、provider 键不匹配返回 0、空值不查询、**引号注入无效**（`x' OR '1'='1` 返回 0）、重复查询稳定、解绑后返回 0

## 四、回归

| 套件 | 结果 |
|---|---|
| `oauth_refactor_test.php`（新增） | 22 / 0 |
| `final_checklist_test.php`（OAuth state 重放/跨身份） | 6 / 0 |
| `tx_final_test.php` | 114 / 0 |
| `tx_boundary_test.php` | 58 / 0 |
| `transaction_test.php` | 45 / 0 |
| `db_safety_test.php` | 47 / 0 |
| `migration_test.php` | 41 / 0 |
| `atomicity_test.php` | 32 / 0 |
| `concurrency_test.php`（10 场景，10 进程） | 54 / 0 |
| `v4_matrix_test.php` | 50 / 0 |
| Free 独立 `reg_test.php` | 76 / 0 |
| Free + Pro `pro_test.php` | 64 / 0 |
| `php -l`（40 文件） | 0 失败 |
| **Plugin Check 2.1.0（ZIP 解压扫描）** | **0 ERROR / 0 WARNING** |
| 升级 2.6.5 → 2.6.6 | 余额 50→50、流水 1→1、幂等键保留、OAuth 绑定可查、`verify_balance` 通过、升级后变动 55 |
