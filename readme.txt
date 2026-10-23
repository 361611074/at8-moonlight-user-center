=== AT8 Moonlight User Center ===
Contributors: x361611074
Tags: user profile, login, registration, avatar, user center
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 2.5.7
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Front-end user center: login, registration, account dashboard, avatars, social login, hidden content, membership levels and offline payments.

== Description ==

AT8 Moonlight User Center gives a WordPress site a complete front-end account area without touching the WordPress admin. It is deliberately small: it uses the WordPress user system for everything and stores no passwords of its own. It performs no analytics, telemetry or tracking; the only third-party communication is the optional social login (see the External services section).

**What it provides**

* Front-end login form
* Front-end registration that honours the WordPress "Anyone can register" setting
* Password recovery through the standard WordPress reset email
* An account dashboard with overview, profile, avatar and password sections
* Profile editing for the display name, nickname, first and last name, website address, phone number and biographical info
* An avatar library: an administrator publishes a set of avatars and each user picks one
* A password change form that signs the user out of every other device
* Social login: WeChat, QQ, GitHub, Google and Apple sign-in, configured per provider in the admin
* A hidden-content shortcode that reveals content after login or after an approved comment
* Custom registration and profile fields (text, textarea, number, date, URL, email, dropdown, radio, checkbox), editable per user from the profile screen
* A points ledger per user: balance, running history, configurable unit, points earned per currency unit spent, optional welcome bonus, admin adjustments
* Optional terms-of-service checkbox on registration and a password strength meter
* Membership levels with no cap on the number of tiers (label, colour, price, validity in days, sort order, point cost); members redeem a level with points or buy it from the account dashboard, and an administrator confirms the bank transfer
* A paywall for single posts and pages: set a price, pick the membership levels that read it for free, and everyone else buys it through the same order engine
* Offline payment engine: order records, admin confirmation, automatic expiry of abandoned pending orders, and purchase history in the account dashboard
* Elementor widgets for the login, registration and account forms
* A dedicated top-level "User Center" menu in wp-admin, with a card-based settings screen covering general settings, membership levels, offline payments, paywall defaults, points, registration fields, sidebar menu icons, interface text, social login and the avatar library

**Membership, points and paywall**

* Define as many membership levels as you need; there is no artificial cap and nothing is unlocked by a licence
* Members reach a level by redeeming points (each level has a point cost) or by paying; both flow through the same order engine
* Paid levels expire on their own and the free base level remains
* Abandoned pending orders are closed automatically after a configurable number of hours
* A paywalled post shows a teaser plus an unlock card; members of the chosen levels, or members who bought access, see the full content — and paid content is never served in feeds
* The separate AT8 Moonlight User Center Pro add-on (not required) contributes online card payments (PayPal, Stripe), tiered upgrade pricing, paywall resources such as videos and downloads, and extra content-gate types. The free plugin is complete on its own.

**Shortcodes**

* `[at8_mluc_login]` — login form
* `[at8_mluc_register]` — registration form
* `[at8_mluc_lostpassword]` — password recovery form
* `[at8_mluc_account]` — account dashboard
* `[at8_mluc_hide type="logged|reply"]` — hidden content, revealed after login or after an approved comment
* `[at8_mluc_oauth_buttons]` — social login buttons on any page

Place each shortcode on its own page, then choose those pages under User Center → Settings. Any page you leave unset falls back to the equivalent WordPress screen.

**How it handles your data**

* All accounts are created with the WordPress user API. Passwords are hashed by WordPress and are never read, stored, displayed or transmitted by this plugin.
* Every form submission is protected by a nonce, every action is checked against the user's capabilities, all input is sanitised and all output is escaped.
* Submission endpoints are rate limited.
* The plugin embeds no trackers or analytics and loads no third-party scripts or fonts. The only outbound requests happen when a visitor uses social login: the browser is then redirected to the chosen provider and back, and the server exchanges the authorisation code with that provider's API (WeChat, QQ, GitHub, Google or Apple, whichever the administrator enabled). No data is sent anywhere else.
* It registers a personal data exporter and eraser, so a user's information can be exported or erased through Tools → Export Personal Data and Tools → Erase Personal Data.
* On uninstall you choose whether the plugin's settings and the profile fields it added are removed. WordPress user accounts, posts and comments are never deleted.

**Theme compatibility**

The plugin ships minimal, namespaced CSS that inherits your theme's colours and typography. Any template can be overridden by copying it into `at8-moonlight-user-center/` inside your theme.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through Plugins → Add New.
2. Activate the plugin.
3. Create a page for the account dashboard containing the `[at8_mluc_account]` shortcode, and a page each for `[at8_mluc_login]`, `[at8_mluc_register]` and `[at8_mluc_lostpassword]`.
4. Go to User Center → Settings and select those pages.
5. For registration, make sure "Anyone can register" is enabled in Settings → General.
6. To offer avatars, add entries under Avatar Library, give each one a featured image, and publish.
7. To sell memberships, define levels under Membership Levels and enter your transfer instructions under Offline Payments.

== Frequently Asked Questions ==

= Does this plugin store passwords? =

No. Accounts are created and authenticated entirely through the WordPress user API. The plugin never reads, stores, displays or sends a password.

= Why can users not change their email address on the front end? =

Changing an email address affects account recovery and requires the WordPress confirmation flow. Ask users to contact an administrator, or manage it from Users in the admin.

= How do users get an avatar? =

Publish entries under Avatar Library and set a featured image on each one. Users then pick from that list in the account dashboard. Users cannot upload files themselves, which keeps file uploads off the front end.

= Can I change the wording? =

Every string is translatable and uses the `at8-moonlight-user-center` text domain, so you can translate or filter it. The `at8_mluc_ui_label` filter replaces individual labels.

= What happens to my data if I delete the plugin? =

Nothing is removed unless you enable "Delete data on uninstall" in User Center → Settings. With it enabled, deleting the plugin removes its settings, the profile fields it added and the order records it created. WordPress user accounts, posts and comments are always left alone.

= Does it work with any theme? =

Yes. Styles are namespaced and inherit the theme wherever possible, and any template can be overridden from your theme.

== External services ==

This plugin connects to third-party services **only** when the site administrator enables the matching social-login provider under User Center → Settings → Social Login, and **only** when a visitor actively clicks that provider's login button. No external request is made for visitors who do not use these buttons, and the plugin performs no analytics, telemetry or tracking.

**Google OAuth** (login with a Google account)

* Endpoints contacted: `accounts.google.com/o/oauth2/v2/auth` (authorization), `oauth2.googleapis.com/token` (code exchange), `www.googleapis.com/oauth2/v3/userinfo` (profile).
* Data sent: the OAuth authorization code and the plugin's own client credentials configured by the administrator; in return the plugin receives the provider account ID, display name and verified email address.
* Data is exchanged once per login attempt and is not stored beyond the account binding (provider ID) described in the privacy section. Verified emails may be used to match an existing account, as described in the plugin FAQ.

* Terms of service: https://policies.google.com/terms
* Privacy policy: https://policies.google.com/privacy

**GitHub OAuth** (login with a GitHub account)

* Endpoints contacted: `github.com/login/oauth/authorize` (authorization), `github.com/login/oauth/access_token` (code exchange), `api.github.com/user` and `api.github.com/user/emails` (profile and verified addresses).
* Data sent: the OAuth authorization code and the administrator's client credentials; in return the plugin receives the GitHub account ID, display name, avatar URL and email addresses.
* Terms of service: https://docs.github.com/en/site-policy/github-terms-of-service
* Privacy policy: https://docs.github.com/en/site-policy/privacy-policies

**WeChat Open Platform OAuth** (login with a WeChat account)

* Endpoints contacted: `open.weixin.qq.com/connect/qrconnect` (authorization), `api.weixin.qq.com/sns/oauth2/access_token` (code exchange), `api.weixin.qq.com/sns/userinfo` (profile).
* Data sent: the OAuth authorization code and the administrator's app credentials; in return the plugin receives the WeChat OpenID/UnionID, display name and avatar URL.
* Terms of service: https://weixin.qq.com/cgi-bin/readtemplate?uin=&stype=&promote=&fr=&lang=zh_CN&check=false&t=weixin_agreement (the agreement covers WeChat Open Platform, see section 2.1)
* Privacy policy: https://weixin.qq.com/cgi-bin/readtemplate?uin=&stype=&promote=&fr=&lang=zh_CN&check=false&t=weixin_agreement&s=privacy

**QQ Connect OAuth** (login with a QQ account)

* Endpoints contacted: `graph.qq.com/oauth2.0/authorize`, `graph.qq.com/oauth2.0/token`, `graph.qq.com/oauth2.0/me`, `graph.qq.com/user/get_user_info`.
* Data sent: the OAuth authorization code and the administrator's app credentials; in return the plugin receives the QQ OpenID, display name and avatar URL.
* Terms of service: https://www.qq.com/contract/
* Privacy policy: https://privacy.qq.com/

**Sign in with Apple** (login with an Apple ID)

* Endpoints contacted: `appleid.apple.com/auth/authorize` (authorization, form_post supported), `appleid.apple.com/auth/token` (code exchange), `appleid.apple.com/auth/keys` (public keys for JWT verification).
* Data sent: the OAuth authorization code and the administrator's client credentials; in return the plugin receives the Apple account identifier, name fragments (when the user shares them) and email address (possibly a private relay address, which is never matched against existing accounts).
* Terms of service: https://www.apple.com/legal/internet-services/terms/site.html
* Privacy policy: https://www.apple.com/legal/privacy/

No access tokens, authorization codes or provider API responses are written to logs or persistent storage; only the provider account identifier is stored as the account binding (see the privacy section below). Policy links were verified on 2026-10-23 (Google, GitHub, Apple, WeChat — including the WeChat agreement's Open Platform coverage; QQ uses Tencent's general service agreement and privacy policy at the same official domains).

== Screenshots ==

1. The account dashboard overview.
2. The profile editor.
3. The avatar library picker.
4. The front-end login form.

== Changelog ==

= 2.5.7 =
* Points: the ledger is now the single source of truth. Every movement is its own row (atomic INSERT) carrying an idempotency key, and the stored balance is a cache replayed from those rows — so an award interrupted at any point (after the order marker, after the ledger row, or after the balance) resumes correctly on the next trigger without duplicating the award, without losing it, and without ever removing a historical ledger entry. add_entry() now returns the exact stored row so an exact-row rollback is always possible.

= 2.5.6 =
* Points: balance and ledger writes are now built on atomic primitives only — the ledger entry is an INSERT (add_user_meta) and the balance moves with a conditional SQL UPDATE, so concurrent awards for the same user can no longer lose updates and no per-user lock is needed. A failed balance bump rolls back exactly the one ledger row it added, and an idempotency key on each entry lets an interrupted award resume without duplicating history.

= 2.5.5 =
* Points: the failure rollback now restores an exact pre-update snapshot of the balance and the ledger, so a failed write can never remove a ledger entry that predates the adjustment (the previous comparison-based rollback could drop one historical entry in a specific failure case).

= 2.5.4 =
* Points: balance and ledger writes are now guarded by a per-user atomic lock and verified by read-back; a half-written award (balance saved, ledger not, or the reverse) is rolled back inside the lock and the retry re-awards exactly once. Concurrent completions for the same user no longer risk lost updates.

= 2.5.3 =
* Points: the award is now failure-safe. The idempotency lock is written only after the amount and rate checks pass, and if the balance write fails the lock rolls itself back so the next trigger retries instead of losing the award. Verified with forced-failure and retry tests.

= 2.5.2 =
* Points: a defensive status guard means only paid orders ever earn points, even if the completion hook is fired by another plugin out of order.
* Documentation: removed absolute "no external service" claims that contradicted the optional social login; the description, the privacy-policy suggestion and the developer README now state precisely when third-party communication happens.

= 2.5.1 =
* Points: purchases now really award points — every completed order grants floor(price x rate) through the ledger with an auditable order reference and a per-order unique lock, so repeated or racing triggers cannot double-award. A zero or negative rate awards nothing; refunds do not remove earned points (documented).
* Uninstall: the documented "Delete data on uninstall" choice is now a real checkbox on the settings screen, defaulting to keeping data.
* Scripts: the editor i18n payload and the profile points buttons travel through the standard script API instead of hand-echoed script tags, loaded only where needed.
* OAuth: provider SVG icons pass a strict element/attribute allow-list before output; the deprecated openssl_free_key() call was removed.
* readme.txt: a full External services section discloses every social-login endpoint, the data exchanged and each provider's terms and privacy policies.
* Packaging: local .po/.mo files are no longer bundled; language packs come from translate.wordpress.org.

= 2.5.0 =
* Removed every artificial ceiling from the free plugin. Membership levels are no longer capped at three, the avatar library is no longer capped at twelve, and every registered payment gateway is offered instead of trimming the shelf to one. The filters that let an add-on raise those caps (`at8_mluc_membership_level_limit`, `at8_mluc_limit_avatar_count`, `at8_mluc_limit_gateway_count`) and the unused `at8_mluc_paywall_level_limit` filter are gone; the free plugin is complete on its own and nothing is unlocked by a licence.
* The paywall meta box now accepts several free-read levels per post (multi-select), and level meta written by older single-select versions keeps working.
* The Pro comparison table now describes only what the add-on adds — online gateways, tiered upgrades, paywall resources and extra content gates — instead of quota differences.
* Documentation updated: the feature list covers the paywall, points, custom fields and terms-of-service, and the data section names the social-login providers the server talks to.
* The Offline Payments settings section now states that refunds for the Pro online gateways are handled in the gateway dashboard and do not revoke a member's access automatically.

= 2.4.1 =
* New: the paywall core is now an extension surface. The gate always runs and an add-on extends the same post fields through the `at8_mluc_paywall_enabled`, `at8_mluc_paywall_levels`, `at8_mluc_paywall_price`, `at8_mluc_paywall_days`, `at8_mluc_paywall_unlock_expire`, `at8_mluc_paywall_locked_html`, `at8_mluc_paywall_unlocked_html`, `at8_mluc_paywall_meta_extra` and `at8_mluc_paywall_can_purchase` filters instead of shipping its own content filter, so two gates can never double-lock the same post. An add-on with a richer paywall box takes the meta box over through `at8_mluc_paywall_meta_box`.
* New: every upgrade method now renders from one registry. The Membership tab draws the points redemption card, the offline purchase card and any add-on path side by side through the `at8_mluc_upgrade_paths` filter.
* New: `at8_mluc_order_price` lets an add-on charge a differential for upgrade orders, and the purchase script binds every card on the page (purchase, upgrade ladders), not just the first.
* Improved: order history moved into its own block below the upgrade paths.
* Improved: paid content is never served in feeds.
* Fixed: `claim_order()` is public so the delivery handler owns the pending → paid transition; a second confirmation can never grant twice.

= 2.4.0 =
* New: custom registration and profile fields. Define any number of extra fields in User Center → Settings → Registration & Profile Fields, choose where each one appears (registration form, profile form, or both), and administrators edit the values on the user profile screen. Text, multi-line, number, date, website, email, dropdown, radio group and single checkbox are supported.
* New: points. A ledger per user (balance, running history, never below zero), a configurable unit name, points earned per currency unit spent and an optional welcome bonus. Administrators grant or deduct points from the user profile screen.
* New: points are the free version's upgrade path. Give a membership level a point cost and members redeem it from the Membership tab; the level, its validity and the ledger entry are written in one request.
* New: paywall for single posts and pages in the free version. Set a price and the one membership level that reads it for free; everyone else buys it through the same order engine, and members who already hold the level never see the gate.
* New: optional terms-of-service checkbox on the registration form (label and terms page are configurable) and a password strength meter on the password field.
* Improved: the account dashboard overview now shows the current membership with its expiry, the point balance and paid-order totals.
* Improved: the Pro comparison table states what each side actually does, including the free version's upgrade path.
* Fixed: paywall orders were never marked paid. The paywall branch of `complete_order()` skipped the atomic claim that flips pending to paid, so a paid order still read as pending, could be confirmed twice and could be closed by the abandoned-order sweeper. It now claims first and delivers second.

= 2.3.0 =
* New: a redesigned settings screen — a branded header with the current plan, a collapsible Pro comparison table (dismissible, shown only on this screen), a shortcode cheat sheet and a sticky save bar.
* New: an accent colour setting. One colour tints buttons, active states and membership cards, and is exposed as `--at8-mluc-accent` for custom CSS.
* New: a payment gateway shelf on the Offline Payments section, which states plainly what bank transfer does today and what PayPal / Stripe add.
* New: the order list now shows status, member, item, gateway and amount columns, with confirm / cancel actions on pending orders.
* Improved: front-end styles rebuilt on neutral translucent surfaces, so login, the account dashboard, membership cards and content gates look considered on light and dark themes alike. Membership badges now use each level's own colour.
* Improved: the avatar library is capped at twelve published avatars in the free version (unlimited in Pro). New avatars beyond the cap stay as drafts with an explanation, and editing existing avatars is never blocked.
* Fixed: built-in membership levels carried no colour of their own, so every badge fell back to the same blue. The factory defaults now define one, and the level editor reuses the same source of truth.
* Changed: membership level colours, order status labels and the level badge markup each had two definitions; they now have one.

= 2.2.1 =
* Fixed: the User Center menu is now built before other plugins add entries to it (`admin_menu` priority 5 plus a new `at8_mluc_admin_menu` action). Previously a child page registered on the default priority got a different page hook at registration time than WordPress computed when the page was opened, and every Pro submenu page (License, the old Pro settings screen) was rejected with "Sorry, you are not allowed to access this page" (HTTP 403).

= 2.2.0 =
* New: top-level "User Center" menu in wp-admin (settings, orders, avatar library and the Pro license page all live under it) with a card-based settings screen and sticky section navigation.
* New: membership levels with a free base level, priced paid levels, automatic expiry, a level editor in the settings screen and a Membership tab with a comparison table and a buy / upgrade card in the account dashboard. The free version supports up to three levels; Pro lifts the cap to eight.
* New: offline payments — a bank-transfer gateway, order records, admin confirmation, automatic closing of abandoned pending orders and purchase history in the account dashboard.
* New: settings sections for membership levels, offline payments, sidebar menu icons, interface text, social login and the avatar library, all on one screen.
* AT8 Moonlight User Center Pro now contributes its own sections (online payment gateways, order management) to this screen instead of shipping a separate settings page.

= 2.1.0 =
* New: social login (WeChat, QQ, GitHub, Google, Apple) with per-provider credentials in Settings → User Center; buttons render under the login and registration forms.
* New: `[at8_mluc_hide type="logged|reply"]` hidden-content shortcode with an editor button; Pro add-ons extend it with membership and purchase gates through the `at8_mluc_hide_types` filter.
* New: login/register/profile Elementor widgets.
* The `[at8_mlucp_hide]` shortcode name from the Pro 1.0.x era is accepted as an alias, so old content never breaks.

= 2.0.0 =
* New: menu items can be shown to logged-in users only or logged-out visitors only, and custom links accept the magic placeholders #at8-mluc-login, #at8-mluc-register, #at8-mluc-account, #at8-mluc-lostpassword and #at8-mluc-logout.
* New: documented extension points for the commercial add-on AT8 Moonlight User Center Pro (`at8_mluc_is_pro`, `at8_mluc_account_tabs`, `at8_mluc_login_form_bottom`, `at8_mluc_register_form_bottom`). The free plugin itself remains complete and functional on its own and ships no locked code.
* Fix: the front-end stylesheet and script were still enqueued from their pre-rename paths, so the dashboard had no styling since 1.0.1.

= 1.0.2 =
* Plugin Check clean-up: removed the discouraged load_plugin_textdomain() call, every AJAX handler now verifies its nonce inline with check_ajax_referer(), and the account templates use prefixed local variables.
* The release zip no longer ships .gitignore: hidden files are not permitted in distributed plugins.

= 1.0.1 =
* Rebranded to AT8 Moonlight User Center (plugin slug, text domain and all internal identifiers).
* Fixed a fatal error on activation when another plugin had already defined the shared MLUC_* constants: every constant, class, function, option, meta key, shortcode and AJAX action now uses the collision-free AT8_MLUC_ / at8_mluc_ namespace, and the autoloader resolves classes from its own directory.
* Fixed the password recovery shortcode rendering nothing: the template file is now lostpassword.php to match the view slug.
* Renamed the front-end shortcodes to [at8_mluc_login], [at8_mluc_register], [at8_mluc_lostpassword] and [at8_mluc_account].

= 1.0.0 =
* First release.
* Front-end login, registration and password recovery.
* Account dashboard with overview, profile, avatar and password sections.
* Admin-curated avatar library.
* Personal data exporter and eraser, plus suggested privacy policy text.
* Uninstall option to remove the plugin's data.

== Upgrade Notice ==

= 1.0.0 =
First release.
