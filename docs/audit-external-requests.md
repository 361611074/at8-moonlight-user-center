# External Requests Audit — AT8 Moonlight User Center 2.5.2

- 审计日期：2026-10-23；对象：`master` @ 整改提交（v2.5.2）
- 搜索范围：`includes/`、根 PHP、模板；模式：`wp_remote_get`、`wp_remote_post`、`wp_remote_request`、`curl_init`、URL 形式 `file_get_contents`、`fetch(`、`XMLHttpRequest`、`sendBeacon`、`analytics`、`telemetry`、`tracking`、`beacon`、`pixel`、`usage`、全部 OAuth 域名

## 结论

插件**仅有的出站 HTTP 请求**集中在 `includes/class-oauth.php`，全部属于社交登录的 OAuth2 协议交换；除此之外插件无任何外部请求、无分析/遥测/跟踪代码（搜索证据见下）。

## 逐项清单

| # | 文件 / 方法 | 域名 / 端点 | 方法 | 触发条件 | 发送数据 | 接收数据 | 个人/认证数据 | 用途 |
|---|---|---|---|---|---|---|---|---|
| 1 | class-oauth.php:388 `exchange_token()` | provider token 端点（Google `oauth2.googleapis.com/token`、GitHub `github.com/login/oauth/access_token`、微信 `api.weixin.qq.com/sns/oauth2/access_token`、QQ `graph.qq.com/oauth2.0/token`、Apple `appleid.apple.com/auth/token`） | POST | 管理员已启用该 provider 且访客用其按钮登录后，回调携带授权码 | 授权码、`client_id`、`client_secret`（管理员配置）、`redirect_uri` | access token（仅内存使用，不持久化、不落日志） | 是（认证凭据） | OAuth 必需交换 |
| 2 | class-oauth.php:431 | `graph.qq.com/oauth2.0/me` | GET | 同上（QQ 流程内） | access token（query） | OpenID | 是（账号标识） | OAuth 必需 |
| 3 | class-oauth.php:612 `fetch_profile()` | Google `www.googleapis.com/oauth2/v3/userinfo`、GitHub `api.github.com/user` 与 `api.github.com/user/emails`、微信 `api.weixin.qq.com/sns/userinfo`、QQ `graph.qq.com/user/get_user_info` | GET | 同上 | access token（Bearer header） | 账号 ID、昵称、头像、邮箱 | 是（账号标识 + 邮箱） | OAuth 必需 |
| 4 | class-oauth.php:601 附近 | `appleid.apple.com/auth/keys` | GET | Apple 登录流程内（取公钥验 JWT） | 无 | Apple JWKS | 否 | Apple token 验签必需 |

（授权端点 `accounts.google.com/o/oauth2/v2/auth`、`open.weixin.qq.com/connect/qrconnect`、`github.com/login/oauth/authorize`、`graph.qq.com/oauth2.0/authorize`、`appleid.apple.com/auth/authorize` 由浏览器重定向到达，服务端不发请求；数据为 client_id + redirect_uri + state。）

## 反向证明（未发现项）

- `wp_remote_request` / `curl_init` / URL 形式 `file_get_contents`：**0 命中**
- `analytics` / `telemetry` / `tracking` / `beacon` / `pixel` / `sendBeacon` / `XMLHttpRequest`（PHP 端）：**0 命中**（前端 fetch 仅指向本站 admin-ajax.php）
- 非插件自身域名的外部资源（脚本/字体/图片）：0（无第三方资产入队）

## 凭据与日志

- client secret 仅在 token 交换请求头/体内发送，不写日志、不写 options（仅存于管理员配置的 `at8_mluc_options` 中，与 WP 其他凭据同生命周期）
- access token 仅存在于请求周期内存变量；OAuth 失败路径返回 `WP_Error` 人类可读消息，不包含 token 或完整 API 响应体（`oauth_fail()` 只输出固定文案）
- 安全验证：回调强制 state transient（一次性消费）+ provider 绑定校验 + 同源 cookie；redirect 目标经白名单校验；失败降级为跳转错误提示页，不泄露内部错误
