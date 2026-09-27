=== 漫步白月光用户中心 ===

Contributors: 漫步白月光
Tags: user, login, register, account, profile, elementor, astra
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Author: 漫步白月光
Author URI: https://www.at8.fun/

轻量、主题无关的 WordPress 用户中心，兼容 Astra 主题与 Elementor 页面构建器。

== 功能 ==
* 前端登录 / 注册 / 找回密码（短代码驱动，任意主题可用）
* 账户中心仪表盘：侧栏导航 + 概览 / 资料编辑 / 退出
* 资料编辑：昵称、个人网站、简介、密码修改
* 前端头像上传
* Elementor 组件：登录表单、注册表单、用户资料卡
* 账户 Tab 通过过滤器 `mluc_account_tabs` 可扩展（如挂接商城订单）

== 在线支付 ==
* 后台「用户中心 → 设置 → 在线支付」配置 PayPal（Smart Buttons，服务端 capture）与 Stripe（Checkout 托管结账）
* PayPal：前台弹窗付款，服务端捕获并校验金额后自动开通会员
* Stripe：跳转 Stripe 托管页支付，回跳自动校验开通；可选 Webhook（checkout.session.completed）兜底，端点 /wp-json/mluc/v1/stripe-webhook
* 货币代码后台可配（默认 USD），零小数货币（JPY/KRW 等）自动处理
* 线下转账流程保持不变；双插件同装走商城支付时本流程不启用

== 配置说明 ==
【第 0 步：通用设置】
1. 后台「用户中心 → 设置」找到「在线支付（PayPal / Stripe）」区块。
2. 「货币代码」填 ISO 4217 三位代码（如 USD / EUR / HKD / JPY），两个网关共用；
   务必与会员等级价格的币种一致，否则金额校验会拒绝开通。
3. 勾选要启用的网关，保存后会员购买卡片才会出现对应支付按钮。

【第 1 步：PayPal 凭据获取】
A. 沙盒测试（sandbox）：
   1. 打开 https://developer.paypal.com/dashboard/ 用 PayPal 账号登录；
   2. 「Apps & Credentials」→ 右上角开关切到「Sandbox」；
   3. 「Create App」随便起个名字（如 my-site-membership）→ 创建；
   4. 复制页面上显示的「Client ID」和「Secret」；
   5. 后台填入对应输入框，「模式」选 Sandbox，勾选启用，保存。
B. 正式上线（live）：
   1. 需要 PayPal 商家账号（Business account），在 https://www.paypal.com/ 免费升级；
   2. 回到 developer dashboard，「Apps & Credentials」切到「Live」；
   3. 「Create App」创建正式应用，复制 Client ID / Secret；
   4. 后台填入新凭据，「模式」切换为 Live，保存即可收款。
C. 沙盒测试方法：
   1. developer dashboard「Testing Tools → Sandbox accounts」里有自动生成的
      「Personal（买家）」和「Business（卖家）」两个测试账号；
   2. 前台用买家账号在 PayPal 弹窗里付款，走完整流程；
   3. 付款成功会员自动开通即代表配置正确，再切 Live。

【第 2 步：Stripe 配置（可选，与 PayPal 互不影响）】
1. https://dashboard.stripe.com/register 注册（需支持 Stripe 的地区账号）；
2. 「开发者 → API 密钥」复制「可发布密钥 pk_...」和「私密密钥 sk_...」；
3. 后台填入并勾选启用。pk 以 test_ 开头为测试模式，正式收款换 pk_live/sk_live；
4. Webhook（推荐，兜底回调）：
   a. 「开发者 → Webhooks」→「添加端点」，URL 填插件设置页显示的
      https://你的域名/wp-json/mluc/v1/stripe-webhook
   b. 监听事件勾选 checkout.session.completed；
   c. 创建后复制「签名密钥 whsec_...」填入后台「Webhook 签名密钥」。

【常见问题】
* 前台看不到 PayPal 按钮：检查是否勾选「启用」、凭据是否为空、以及当前会员等级
  是否允许购买（已是更高等级会被预检拦截，这是正常保护）。
* PayPal 报「凭据校验失败」：Client ID / Secret 复制不完整，或凭据与所选模式
  （Sandbox/Live）不匹配——沙盒凭据只能配 Sandbox 模式。
* 付款成功但会员没开通：确认货币代码与等级价格币种一致；Stripe 用户确认 Webhook
  是否配置；两站/多站部署时凭据各站独立填写。
* 切换 Sandbox ↔ Live 后报 401：清一下缓存重试（token 缓存按模式独立，正常不会
  串；仍报错到后台重存一次凭据即可）。
* 退款：在 PayPal / Stripe 商户后台手动操作，插件侧可在订单编辑页改状态并备注。

== 界面文案 ==
* 后台「用户中心 → 设置 → 界面文案（账户中心）」可自定义账户中心全部前台文字（v1.4.3 起全量开放）：
  侧栏标签、概览页（时段问候/快捷操作/账户统计）、个人资料页、会员等级页、已购内容页、
  购买卡（付款提示/购买记录表头/网关提示）、登录/注册/找回密码页、保存与改密等操作提示。
* 填英文或其他语言即可，留空使用默认值；v1.4.1 起「已购教材」默认更名为「已购内容」。

== 短代码 ==

* [mluc_login]        登录表单
* [mluc_register]     注册表单
* [mluc_lostpassword] 找回密码
* [mluc_account]      账户中心

== 兼容性 ==
* 与 Astra 主题无冲突（样式作用域限定 .mluc-）
* 与 Elementor 集成，提供专用组件
* 不依赖主题全局函数，可独立运行

== 版权 ==
本插件为原创实现，采用 GPL-2.0-or-later 授权，作者：漫步白月光。
官网：https://www.at8.fun/
