# Points & Orders Test Record — 2.5.2

- 环境：本地隔离 WordPress 7.1.3 / PHP 8.3 / SQLite（WP_DEBUG 开）
- 执行方式：`wp eval-file v4_matrix_test.php`（自动化断言）+ 调用链代码审计
- 全部用例 PASS/FAIL 如实记录；未执行项显式标注 NOT RUN

## 调用链（实现证据）

`complete_order()`（原子认领 pending→paid，幂等拒绝重复）→ `do_action('at8_mluc_payment_completed', $order_id, $user_id, $subject)` → `AT8_MLUC_Points::grant_for_order()`：
1. rate ≤ 0 / ID 非法 → return
2. 订单状态防御：仅 `_at8_mluc_pay_status == 'paid'` 继续
3. `get_userdata()` 确认买家存在
4. 幂等锁：`add_post_meta($order_id, '_at8_mluc_points_awarded', 1, true)`（unique，并发下仅一次成功）
5. 服务端价格 meta → `floor(price × rate)` → `Points::adjust()` 记 ledger（source=purchase，含订单号）

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
| 14 | 真实第三方支付回调（Stripe/PayPal 沙箱） | — | 无沙箱凭据 | **NOT RUN** |

## 备注

- 折扣/税/运费：插件订单金额为管理员确认或支付网关返回的单一实付值，无折扣/税费拆分概念；计算依据 = 订单 meta 实付价（与设置页文案一致）
- 退款：管理员在网关后台退款后权限与积分均不自动回收（readme/设置页/整改报告三处声明）
