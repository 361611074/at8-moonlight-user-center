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
