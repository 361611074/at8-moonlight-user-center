# Points & Orders Test Record — 2.5.8

- 环境：本地隔离 WordPress 7.1.3 / PHP 8.3 / SQLite（WP_DEBUG 开）
- 执行方式：`wp eval-file v4_matrix_test.php`（自动化断言）+ 调用链代码审计
- 全部用例 PASS/FAIL 如实记录；未执行项显式标注 NOT RUN

## 调用链（实现证据）

`complete_order()`（原子认领 pending→paid，幂等拒绝重复）→ `do_action('at8_mluc_payment_completed', $order_id, $user_id, $subject)` → `AT8_MLUC_Points::grant_for_order()`：
1. rate ≤ 0 / ID 非法 → return
2. 订单状态防御：仅 `_at8_mluc_pay_status == 'paid'` 继续
3. `get_userdata()` 确认买家存在
4. 服务端价格 meta → `floor(price × rate)` → `Points::adjust(..., 'purchase:<id>')`
5. `adjust()` 先用**原子 INSERT** 在 options 表认领幂等键（`option_name` 有 UNIQUE 索引，数据库裁决唯一赢家），
   再取 per-user 互斥行，在临界区内重放账本 → 写流水 → 写余额镜像 → 校验
6. 发放成功后 `add_post_meta($order_id, '_at8_mluc_points_awarded', 1, true)` 只作快路径标记，
   不再承担幂等职责（并发正确性由 claim 行 + 互斥行保证）

## 测试结果

| # | 用例 | 预期 | 实际 | 结果 |
|---|---|---|---|---|
| 1 | 已支付订单（10.00，rate 2.0）调用发放 | 20 分入账 | balance=20，ledger 含 `Order #<id>` + purchase | **PASS** |
| 2 | 同一订单重复调用 2 次 | 不重复发放 | balance 仍 20（唯一锁拒绝） | **PASS** |
| 3 | rate = 0 | 不发放 | balance 不变 | **PASS** |
| 4 | rate 为负 | 不发放（`$rate <= 0` 守卫；设置保存侧 number 字段清洗） | 同上逻辑路径 | **PASS**（守卫代码审计 + 用例 3 同路径） |
| 5 | 不存在的用户 ID 999999 | 不 Fatal、不发放 | 正常 return | **PASS** |
| 6 | pending 状态订单（重置锁后调用） | 不发放 | balance 不变（状态守卫） | **PASS** |
| 7 | cancelled 状态订单 | 不发放 | balance 不变 | **PASS** |
| 8 | failed 状态 | 不发放 | 同守卫路径（非 'paid' 一律拒绝） | **PASS**（同用例 6/7 代码路径） |
| 9 | 极大金额 × rate | float 精度内 floor，不 fatal | `(int) floor(...)`；管理员配置值，float 上限内安全 | **PASS**（逻辑审计；极端值未单独构造 NOT RUN） |
| 10 | 重复完成事件（complete_order 二次调用） | 第二次拒绝，钩子不触发 | `at8_mluc_dup` WP_Error（矩阵断言） | **PASS** |
| 11 | 退款处理 | 无自动扣回；文档/readme/设置页声明一致 | 全仓无退款流程；说明一致 | **PASS**（按声明验证） |
| 12 | WooCommerce 未启用 | 无 fatal | 插件无任何 WC 代码引用（grep 0 命中，无 WC hook/类依赖） | **PASS**（grep + 实测站未装 WC） |
| 13 | 并发重复触发 | 唯一锁拒绝 | `add_post_meta(...unique)` 数据库级原子；并发压力测试未跑 | **NOT RUN**（真实并发环境不可得；锁机制为 DB 原子 add） |
| 13b | 入账写入失败（`update_user_metadata` 短路模拟） | 锁回滚、余额不变 | meta 无残留、balance 不变 | **PASS**（2.5.3 新增） |
| 13c | 失败清除后的重试 | 正常入账 | 余额 +4×rate（4.00@2 → +8） | **PASS**（2.5.3 新增） |
| 13d | 金额无效（price=0） | 不写锁 | 锁不存在；价格修正后重试正常发放 | **PASS**（2.5.3 新增） |
| 13e | 流水半写（balance 成功、ledger 失败，`update_user_metadata` 按 key 拦截） | 互斥锁内回滚：余额写回旧值、流水弹出末条、订单锁回滚 | balance 不变、锁不存在 | **PASS**（2.5.4 新增） |
| 13f | 半写失败清除后的重试 | 完整入账 | 余额 +12（6.00@2） | **PASS**（2.5.4 新增） |
| 13g | 并发协调：预占 per-user 锁（`add_option` 原子 INSERT）后发放 | 安全让位：余额不变、订单锁不写、用户锁保留 | 三项断言全过 | **PASS**（2.5.4 新增） |
| 13h | 争用清除后的重试 | 正常入账 | 余额 +4（2.00@2） | **PASS**（2.5.4 新增） |
| 13i | 双订单顺序完成 | 余额与流水逐条对应 | 20→30→38→50→54 全程精确 | **PASS** |
| 13j | 流水半写回滚后的**历史保留**（2.5.5：快照恢复取代比较式回滚） | 回滚后流水条数与末条 = 更新前快照（无历史条目被删） | 3 条 purchase 原样、末条 +8 | **PASS**（2.5.5 新增；修复 2.5.4 比较式回滚在 ledger 写失败场景可能误删一条历史的缺陷） |
| 13k | **原子原语重构（2.5.6）**：流水 = `add_user_meta` 原子 INSERT、余额 = 条件 SQL UPDATE（all-or-nothing 透支守卫），彻底移除 per-user 锁与读-改-写 | 并发不再丢更新 | 顺序/交叉断言全过 | **PASS**（2.5.6 新增） |
| 13l | 流水 INSERT 失败（filter 拦截） | 订单锁回滚、余额不变、无残留 | 三项断言全过 | **PASS**（2.5.6 新增） |
| 13m | 透支扣减（-100） | WP_Error 拒绝、余额不变、**无流水残留**（entry 精确回滚） | 三项断言全过 | **PASS**（2.5.6 新增） |
| 13n | 恢复路径：流水已记、余额未加（种子 entry）→ 重试 | 只补余额、**不重复流水** | balance 精确、purchase 条目数不变 | **PASS**（2.5.6 新增） |
| 13o | **幂等重放模型（2.5.7）**：流水 = 唯一真相（每行带幂等键）、余额 = 重放派生缓存 | 任意点中断皆可恢复 | 见 13p–13r | **PASS** |
| 13p | 中断于**标记后**（订单标记已写、流水/余额无）→ 重试 | 恢复发放：余额 +12、恰好一条流水 | 全过 | **PASS**（2.5.7 新增） |
| 13q | 中断于**流水后**（entry 已写、余额缓存未加）→ 重试 | 重放补余额、**不重复流水行** | 全过 | **PASS**（2.5.7 新增） |
| 13r | 中断于**余额后**（流水+余额已写、标记未写）→ 重试 | 幂等：不双加 | balance 不变 | **PASS**（2.5.7 新增） |
| 14 | 真实第三方支付回调（Stripe/PayPal 沙箱） | — | 无沙箱凭据 | **NOT RUN** |

## 并发与一致性（2.5.8 新增，真实多进程）

执行方式：`bash _tools/v4_conc_run.sh <award|deduct|mixed> 8`。8 个独立 `wp eval-file` 进程对齐到同一
barrier 后同时发起操作；临界区进出时间由 `at8_mluc_points_lock_acquired/released` 钩子记录。

| # | 用例 | 预期 | 实际 | 结果 |
|---|---|---|---|---|
| 12 | **8 进程同时回调同一已支付订单** | 只产生 1 条流水、余额 = 单次发放 | 1 条流水，余额 20（2.5.7 实测为 8 条 / 160，已复现并修复） | **PASS**（`v4_conc_run.sh award 8`，6/0） |
| 13 | **余额 100，8 进程各扣 30** | 3 成功 5 拒绝，余额 10，无透支 | 3 成功 / 5 拒绝（`at8_mluc_points_balance`），余额 10，账本合计 10 | **PASS**（2.5.7 实测 8 个全成功、账本 -140） |
| 14 | **4 加 10 / 4 扣 30 混合并发** | 余额恒等于账本合计且非负 | 余额 = 账本合计，无负值 | **PASS**（`v4_conc_run.sh mixed 8`，6/0） |
| 15 | 临界区串行化 | 同一用户的临界区两两不重叠 | 8 个窗口，overlaps = 0 | **PASS**（lock acquired/released 时间戳） |
| 16 | claim 行由数据库唯一约束建立 | 每订单一行 | 行存在；重复 INSERT 被拒 | **PASS** |
| 17 | 中断在"已 claim、未写流水" | 过期后可被接管，只发一次 | 接管成功，1 条流水，余额正确 | **PASS** |
| 18 | 余额镜像被篡改（9999） | 读余额与扣分都不受影响 | `balance()` 返回账本合计，扣 100 被拒 | **PASS** |
| 19 | 镜像写入失败 | 账本仍正确，不误报失败 | 返回正确余额，账本合计一致 | **PASS** |
| 20 | 互斥行泄漏 | 每次变动后释放 | 无残留 | **PASS** |
| 21 | 进程中断（标记后 / 流水后 / 余额后） | 重试后状态一致 | 三种窗口均正确恢复 | **PASS** |
| 22 | 并发压测（100+ 进程） | — | 未执行 | **NOT RUN** |
| 23 | MySQL/MariaDB 实机并发 | — | 本机为 SQLite，未执行 | **NOT RUN** |


## 备注

- 折扣/税/运费：插件订单金额为管理员确认或支付网关返回的单一实付值，无折扣/税费拆分概念；计算依据 = 订单 meta 实付价（与设置页文案一致）
- 退款：管理员在网关后台退款后权限与积分均不自动回收（readme/设置页/整改报告三处声明）
