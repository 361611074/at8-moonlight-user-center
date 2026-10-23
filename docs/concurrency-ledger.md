# 积分账本的并发一致性模型 — 2.5.8

- 日期：2026-10-23
- 涉及文件：`includes/class-points.php`、`uninstall.php`
- 测试脚本：`_tools/v4_conc_run.sh`（多进程并发）、`_tools/v4_matrix_test.php`（故障与接管）
- 环境：本地隔离 WordPress 7.1.3 / PHP 8.3.35 / SQLite（WP_DEBUG 开）

---

## 一、问题复现（2.5.7 实测）

两条 P1 都不是理论推测，在 2.5.7 上用真实多进程并发直接复现：

| 场景 | 期望 | 2.5.7 实测 | 判定 |
|---|---|---|---|
| 同一已支付订单 8 个并发回调 | 1 条流水、余额 20 | **8 条流水、余额 160** | 复现 |
| 余额 100，8 个进程各扣 30 | 3 成功、余额 10 | **8 个全部成功、账本合计 -140** | 复现 |

第二条比报告描述得更严重：`recalc_balance()` 里的 `max( 0, $sum )` 把负和夹成了 0，
于是 `$new_balance < 0` 的回滚分支**永远不会触发**——透支守卫被自己的夹取逻辑废掉了。
用户用 100 分买到了 240 分的东西。

日志：`_tools/conc-2.5.7-award.log`、`_tools/conc-2.5.7-deduct.log`。

---

## 二、新模型：三个机制，没有一个是"先查后写"

### 1. 幂等键 = 数据库级唯一行（claim）

```php
// options.option_name 在 WordPress 核心 schema 里是 UNIQUE
INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
VALUES ('at8_mluc_points_claim_<md5(user|key)>', '<token>|<expiry>', 'no')
```

普通 `INSERT` 就是原子的 test-and-set：数据库决定谁赢，不是 PHP 的 `has_entry()`。
赢家写流水，输家进入 `settle_claimed()` 轮询等待（最多 2 秒），看到流水落地后
返回同一个余额——所以并发回调**全部成功且只发一次**。

claim 行带过期时间。持有者在写任何东西之前就死掉时，下一次请求用
**compare-and-swap** 接管：

```sql
UPDATE {$wpdb->options} SET option_value = %s
WHERE option_name = %s AND option_value = %s   -- 必须还是我刚读到的那个值
```

两个请求同时接管，只有一个的 UPDATE 能命中——这正是 2.5.6 那版"盲删接管"的漏洞所在。

### 2. 同一用户的变动串行化（mutex）

所有"读账本 → 算 → 写流水 → 写余额"的序列都在 per-user 互斥行内完成：

- 获取：`INSERT` 一行 `at8_mluc_points_lock_<user_id>`（同样靠 UNIQUE 索引原子）
- 续期/接管：只允许 CAS，不盲删
- 释放：`DELETE ... WHERE option_name = %s AND option_value = %s`，**只有持有者能删自己的**
- TTL 10 秒，等待上限 5 秒；等不到返回 `at8_mluc_points_busy`，调用方重试

因为临界区里只有当前这一个写者，扣分预检查与流水插入之间不可能插进另一个扣分。

### 3. 余额不再是"真相"，也不再被读来决策

- `balance()` / 扣分判定：**永远** `replay()` 求和账本行，不读 `at8_mluc_points` 镜像
- `replay()` **不夹取**，负和如实返回，透支才看得见
- 镜像只在临界区内、所有流水写完之后写一次，并**读回校验**
- 镜像写失败不影响正确性（账本是承重结构），测试里专门注入了这个故障

### 关键点：先 claim 后 lock，失败就撤回 claim

`apply_locked()` 的每一条失败路径都不会留下流水行，因此 `adjust()` 在返回错误前
调用 `drop_claim()`（CAS 删除自己的 claim），让"修正后重试"能重新拿到键。
矩阵测试里"流水写入被拦截 → 重试成功"这条断言覆盖了它。

---

## 三、崩溃/故障矩阵

| 中断点 | 状态 | 下一次触发的行为 |
|---|---|---|
| claim 已写、流水未写（进程死） | claim 过期 | CAS 接管 → 补写流水，只发一次 |
| 流水已写、镜像未写 | 账本有行、镜像旧 | 重放账本得正确余额；镜像下次变动时刷新 |
| 流水与镜像都写、订单标记未写 | 完整 | 命中 claim + `has_entry()` → 重放，不重复 |
| 镜像写入失败 | 账本有行 | 返回重放余额（正确），不报假失败 |
| 流水写入失败 | 无残留 | claim 已撤回，余额不变，重试成功 |
| 持锁进程死亡 | 锁行残留 | TTL 到期后 CAS 接管，不误删他人锁 |
| 扣分导致负余额 | 无残留 | 临界区内拒绝，精确回滚本条 |

---

## 四、测试证据

### 多进程并发（真实进程，不是模拟交错）

```bash
bash _tools/v4_conc_run.sh award  8   # 6 passed, 0 failed
bash _tools/v4_conc_run.sh deduct 8   # 7 passed, 0 failed
bash _tools/v4_conc_run.sh mixed  8   # 6 passed, 0 failed
```

做法：8 个独立 `wp eval-file` 进程先对齐到同一个 barrier 文件，再同时冲；
子进程通过 `at8_mluc_points_lock_acquired/released` 钩子记录临界区进出时间。

| 场景 | 结果 |
|---|---|
| 8 并发回调同一订单 | 1 条流水、余额 20、无任何进程观察到双发 |
| 100 分余额，8 × 扣 30 | 3 成功 / 5 被拒（`at8_mluc_points_balance`）、余额 10、账本合计 10 |
| 4 加 10 / 4 扣 30 混合 | 余额恒等于账本合计（本轮 20 = 100+40−120），无负余额 |
| 临界区重叠检测 | 8 个窗口，**overlaps = 0**（可串行化的直接证据） |

### 故障与接管（`_tools/v4_matrix_test.php`）

新增 13 条断言，覆盖：

- claim 行确实由数据库唯一约束建立
- 重复回调不新增流水
- 镜像被改成 9999 时，`balance()` 仍返回账本合计；扣 100 仍被拒
- 互斥行在变动后已释放（不会拖死账号）
- 过期 claim 被接管，且只产生一条流水
- 镜像写入失败后余额仍正确、账本合计等于余额

### 回归

| 套件 | 结果 |
|---|---|
| Free 独立 `reg_test.php` | 76 / 0 |
| 安全功能矩阵 `v4_matrix_test.php` | Free 独立 **49 / 0**（原 36 + 新增 13）；Pro 激活态 50 / 0 |
| Pro 联合 `pro_test.php` | 64 / 0 |
| 干净站安装 `v4_clean_install.php` | 12 / 0 |
| 升级 2.5.7 → 2.5.8 | 数据计数不变，`at8_mluc_version` 自愈 |
| 卸载（delete_data=1 / 默认保留） | 插件状态清零 / 数据保留，核心数据均不变 |
| Plugin Check（最终 ZIP） | 0 ERROR / 25 WARNING，改动文件零告警 |
| debug.log 增量 | 0 行（7 个落败 INSERT 的错误被 `suppress_errors` 吞掉） |

---

## 五、边界与已知限制

1. **MySQL 未在本机实测**：本机测试站是 SQLite。模型依赖的是
   `wp_options.option_name` 的 UNIQUE 索引——这是 WordPress 核心 schema 自带的
   （`UNIQUE KEY option_name (option_name)`），MySQL/MariaDB 同样具备；SQLite 侧
   实测唯一约束生效（重复 INSERT 返回 false）。
2. **claim 行常驻**：每个发过积分的订单留一行 `autoload=no` 的选项行，作为幂等
   登记簿（数量级 = 订单数）。卸载时按前缀删除。
3. **临界区是阻塞式**：同一用户的并发变动会排队（最多等 5 秒）。积分变动是低频
   操作，排队优于静默丢更新；等不到会返回明确错误让调用方重试。
4. **不在本轮范围**：`at8_mluc_redeem_level` 的两个并发请求可能各自扣分并各自
   授予同一等级（钱不会丢，但用户可能为同一个等级付两次）。要彻底解决需要给兑换
   也引入请求级幂等键（例如客户端一次性 token），属于另一条独立改动。
5. **多站点**：锁与 claim 行落在当前站点的 options 表，天然按站点隔离。
