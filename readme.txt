=== AT8 Moonlight User Center ===
Contributors: x361611074
Tags: user profile, login, registration, avatar, user center
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 2.7.6
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Front-end user center: login, registration, account dashboard, avatars, social login, hidden content, membership levels and offline payments.

== Description ==

AT8 Moonlight User Center adds a complete front-end account area to any theme, without sending users to wp-admin. It builds on the WordPress user system rather than replacing it, with no analytics, telemetry or tracking. The only third-party communication is optional social login, described under External services.

**What it provides**

* Front-end login, registration (honouring "Anyone can register"), password recovery and password change
* An account dashboard with overview, profile, avatar and password sections
* An avatar library: publish a set of images and each user picks one
* Social login with WeChat, QQ, GitHub, Google and Apple, configured per provider
* Custom registration and profile fields (text, textarea, number, date, URL, email, dropdown, radio, checkbox)
* A hidden-content shortcode that reveals content after login or after an approved comment
* A points ledger per user: balance, history, unit, earn rate and welcome bonus
* Membership levels with no cap on tiers: label, colour, price, validity, sort order and point cost
* A per-post paywall with a teaser and an unlock card
* An offline payment engine: order records, admin confirmation, automatic expiry of abandoned orders and purchase history
* Elementor widgets and a card-based settings screen

**Shortcodes**

* `[at8mluc_login]`, `[at8mluc_register]`, `[at8mluc_lostpassword]`, `[at8mluc_account]`
* `[at8mluc_hide type="logged|reply"]`, `[at8mluc_oauth_buttons]`

Place each on its own page and pick it under User Center → Settings; anything unset falls back to the equivalent WordPress screen.

**Membership, points and paywall**

Members reach a level by redeeming points or by paying; both use the same order engine. Paid levels expire on their own and the free base level remains. A paywalled post shows a teaser and an unlock card, and paid content is never served in feeds.

The separate AT8 Moonlight User Center Pro add-on is optional: it adds online card payments, tiered upgrade pricing, paywall resources and extra content-gate types. The free plugin is complete on its own.

**About this plugin**

GPL-2.0-or-later. Source, issues and releases: https://github.com/361611074/at8-moonlight-user-center — author: https://www.at8.fun/. What is stored, which cookies are set and how a user's data can be exported or erased is under Privacy; every outbound request is under External services.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through Plugins → Add New.
2. Activate the plugin.
3. Create a page for the account dashboard containing the `[at8mluc_account]` shortcode, and a page each for `[at8mluc_login]`, `[at8mluc_register]` and `[at8mluc_lostpassword]`.
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

Every string is translatable and uses the `at8-moonlight-user-center` text domain, so you can translate or filter it. The `at8mluc_ui_label` filter replaces individual labels.

= What happens to my data if I delete the plugin? =

Nothing is removed unless you enable "Delete data on uninstall" in User Center → Settings. With it enabled, deleting the plugin removes its settings, the profile fields it added and the order records it created. WordPress user accounts, posts and comments are always left alone.

= Does it work with any theme? =

Yes. Styles are namespaced and inherit the theme wherever possible, and any template can be overridden from your theme.

= Does the plugin phone home? =

No. This plugin contains no telemetry, analytics, licence server or update server and sends nothing to the author. The separately distributed Pro add-on is a different plugin: it contacts https://www.at8.fun for licence activation, periodic validation and update checks, sending the product identifier, site URL, plugin version, WordPress version and licence key. It never sends visitor data, account details, post content, points ledger rows or order records. See the author's privacy policy at https://www.at8.fun/privacy-policy/ and terms of service at https://www.at8.fun/terms-of-service/.

== Privacy ==

This plugin stores what the forms collect and nothing else. It performs no analytics, no telemetry and no tracking, and it embeds no third-party trackers.

**What is stored**

* Account: the username, e-mail address and password you submit. The password is stored only as a one-way hash and is never readable by this site or by the plugin.
* Profile: display name, nickname, first and last name, website address, biography and phone number, when you fill them in.
* Avatar: the identifier of the library image you selected. Users cannot upload files.
* Membership and orders: the level held, its expiry, and the orders created when one is bought, with the amount, the gateway and the transaction reference.
* Points: a ledger row for every movement, the balance derived from those rows, and the reason recorded for each movement.
* Custom profile fields, when the administrator defines them.
* Social login: the provider account identifier, so the next sign-in recognises you. Provider tokens and API responses are never stored.

**Cookies**

Signing in uses the standard WordPress session cookies. A social-login attempt also sets three short-lived cookies of its own: a one-time state value that protects the callback against cross-site request forgery (10 minutes), the "stay signed in" choice (10 minutes), and a refusal notice carried back to the login form (5 minutes). All three are readable only by this site and are removed when the attempt finishes. There are no advertising, analytics or preference cookies.

**Your data**

The plugin registers a personal-data exporter and eraser with the WordPress privacy tools, so an account's stored data can be exported or erased from Tools → Export Personal Data and Tools → Erase Personal Data. Suggested privacy-policy wording is added to Settings → Privacy. Deleting the plugin removes its data only when "Delete data on uninstall" is enabled; WordPress user accounts, posts and comments are always left alone.

== External services ==

This plugin talks to two kinds of external service: **Gravatar**, which supplies a profile picture when an account has not chosen one from the site's own avatar library, and the **social-login providers** the administrator chooses to enable. Both are described below.

### Gravatar — profile pictures

WordPress uses Gravatar as its default source of avatars, and this plugin asks WordPress for the avatar URL (`get_avatar_url()`) and prints it on the account pages. That is the only reason Gravatar is contacted.

* Service: Gravatar, an avatar service operated by Automattic. It is used to show a profile picture for a user who has not picked one from the site's own avatar library.
* Domains contacted: `secure.gravatar.com` and its numbered mirrors such as `0.gravatar.com`, `1.gravatar.com`.
* Data sent and when: a hash of the user's e-mail address (never the address itself), the requested image size and the default-image setting. The request is made when a page showing that user's avatar is loaded — the account overview, the avatar picker and the profile form.
* When it is not contacted: no request is made for a user who has selected an avatar from the site's own library, and none at all when the administrator turns avatars off under Settings → Discussion (the account pages then show the initials fallback).
* Terms of service: https://automattic.com/terms/
* Privacy policy: https://automattic.com/privacy/

### Social login — optional, per provider

Social login is **optional**. A connection to a provider is made only when both of these are true:

1. the site administrator has enabled that provider under User Center → Settings → Social Login and saved its own app credentials (client ID / secret) issued by that provider, and
2. a visitor actively clicks that provider's button on a login or registration form.

Nothing is sent anywhere when a provider is disabled, when no button is clicked, or on any other page. The plugin contains no analytics, no telemetry, no tracking pixels, no third-party scripts or fonts, and no update or licence server. Nothing is sent to the plugin author.

Each provider is listed below with the domains that are contacted, what the request is for, the data exchanged, and the provider's own terms and privacy policy. Tokens and API responses are never written to logs or to the database; only the provider account identifier is stored, as the account binding described in the privacy section.

**Google — login with a Google account**

* Domains contacted: `accounts.google.com`, `oauth2.googleapis.com`, `www.googleapis.com`.
* What it is used for: signing in with an existing Google account instead of a local password.
* Data sent and when: an authorization code, exchanged for an access token, only after the visitor clicks "Sign in with Google". The plugin receives the account ID, display name and verified email address. Nothing is sent at any other time.
* Google Terms of Service: https://policies.google.com/terms
* Google Privacy Policy: https://policies.google.com/privacy

**GitHub — login with a GitHub account**

* Domains contacted: `github.com`, `api.github.com`.
* What it is used for: signing in with an existing GitHub account instead of a local password.
* Data sent and when: an authorization code, exchanged for an access token, only after the visitor clicks "Sign in with GitHub". The plugin receives the account ID, display name and verified email addresses. The provider's API response also carries an avatar URL; the plugin neither stores nor uses it, and no remote avatar is ever imported. Nothing is sent at any other time.
* GitHub Terms of Service: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* GitHub Privacy Statement: https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement

**WeChat Open Platform — login with a WeChat account**

* Domains contacted: `open.weixin.qq.com`, `api.weixin.qq.com`.
* What it is used for: signing in with a WeChat account through the WeChat Open Platform website-application flow (available where WeChat Open Platform access is granted).
* Data sent and when: an authorization code, exchanged for an access token, only after the visitor scans the QR code shown by "Sign in with WeChat". The plugin receives the OpenID / UnionID and display name. The provider's API response also carries an avatar URL; the plugin neither stores nor uses it, and no remote avatar is ever imported. Nothing is sent at any other time.
* Tencent WeChat Software License and Service Agreement (covers the Open Platform): https://weixin.qq.com/cgi-bin/readtemplate?uin=&stype=&promote=&fr=&lang=zh_CN&check=false&t=weixin_agreement
* WeChat Privacy Protection Guidelines: https://weixin.qq.com/cgi-bin/readtemplate?uin=&stype=&promote=&fr=&lang=zh_CN&check=false&t=weixin_agreement&s=privacy

**QQ Connect — login with a QQ account**

* Domains contacted: `graph.qq.com`.
* What it is used for: signing in with a QQ account through QQ Connect.
* Data sent and when: an authorization code, exchanged for an access token, only after the visitor clicks "Sign in with QQ". The plugin receives the OpenID and display name. The provider's API response also carries an avatar URL; the plugin neither stores nor uses it, and no remote avatar is ever imported. Nothing is sent at any other time.
* Tencent Service Agreement: https://www.qq.com/contract.shtml
* Tencent Privacy Policy: https://privacy.qq.com/policy/tencent-privacypolicy

**Apple — Sign in with Apple**

* Domains contacted: `appleid.apple.com`.
* What it is used for: signing in with an Apple ID.
* Data sent and when: an authorization code, exchanged for an access token, only after the visitor clicks "Sign in with Apple". The plugin receives the Apple account identifier, the name fragments the user chooses to share, and the email address (which may be a private relay address; those are never matched against existing accounts). Nothing is sent at any other time.
* Apple Media Services Terms and Conditions: https://www.apple.com/legal/internet-services/terms/site.html
* Apple Privacy Policy: https://www.apple.com/legal/privacy/

All links above were checked on 2026-11-11 and point at the providers' own published policies.

== User accounts and authentication ==

This plugin is a front-end user center, so creating accounts and signing users in **is** its purpose rather than a side effect. It does not implement its own authentication scheme: every step goes through the WordPress APIs that other plugins already hook into.

* Registration uses `wp_create_user()` and then `wp_signon()`, so membership plugins, e-mail verification plugins and anti-spam plugins see the same events as a core registration.
* Login uses `wp_signon()` with the submitted credentials. Failed attempts are counted and throttled by the plugin, and any security plugin that hooks `authenticate` or `wp_login_failed` keeps working.
* Password recovery uses `retrieve_password()`; changing a password uses `reset_password()`, which fires `after_password_reset` and invalidates the sessions the account already had.
* Sessions are created with `wp_set_auth_cookie()` and `wp_set_current_user()`, exactly as `wp-login.php` does, so "remember me", session tokens and `wp_destroy_other_sessions()` all behave normally.
* Social login cannot use `wp_signon()`: there is no local password to verify, because the identity was checked by the provider. Every provider therefore signs in through one routine that resolves the local account, runs it through WordPress' own `authenticate` and `wp_authenticate_user` filters (so a security, two-factor, spam or ban plugin can still refuse the login), fires `wp_login_failed` on a refusal, and only then creates the session with `wp_set_auth_cookie()`. The plugin's own `at8mluc_oauth_authenticate_user` filter is applied as well.
* Social login creates an account only when the site allows registration. A provider that returns an e-mail address the provider itself reports as verified, and that matches an existing account, signs that account in — a site can switch that off with the `at8mluc_oauth_allow_email_link` filter. The same rule governs the retry path: when account creation fails because the address is already taken, the existing account is reused only under those exact conditions, so the filter cannot be bypassed. Providers that return no address (WeChat, QQ) get a local placeholder address derived from the provider account ID, which is equivalent to matching by provider ID.
* Social login never edits an account before the login policies have passed. The provider's display name is written only after authentication succeeds, only for an account this plugin created (its login name carries the provider prefix), and only while the display name is still the generated one — a name the visitor chose is never overwritten.
* The plugin never reveals whether a social callback was rejected because of the state check or for any other reason: every refusal lands on the login form with a generic notice.

No user is ever created with a role chosen by the visitor: new accounts receive the site's default role, and the plugin never grants capabilities.

== Screenshots ==

1. The account dashboard. Overview, profile, avatar and password live on one page.
2. The profile editor. Extra fields defined under User Center → Settings appear in this form.
3. The avatar picker. Visitors choose from the library the administrator curated, or keep the default avatar.
4. The front-end login form, rendered inside the theme rather than on wp-login.php.

== Changelog ==

= 2.7.6 =
* Points: redeeming a level is charged once, even when the request arrives twice. The deduction now carries an idempotency key built from the user, the level and the day, so a second click replays the balance instead of taking the cost again — the level grant itself was already idempotent. Before this, a visitor whose balance covered two redemptions could pay twice for one level. A later redemption of the same level, after it expired, falls on a different day and is charged normally.
* Account dashboard: the membership expiry now shows an actual date. `get_user_expires()` returns a timestamp, but the overview template passed it to `mysql2date()`, which expects a MySQL date string — so the note read "Valid until" with nothing after it. Accounts with no expiry showed the bare label as well, because the guard compared the value to an empty string rather than to zero. It is rendered with `date_i18n()` now, the way the membership tab already did.
* Custom fields: the profile hook now ignores a call that did not come from the profile form. The action is public, and a missing key reads as an empty value, so a programmatic caller firing it without the form would otherwise blank every custom field on the account.
* readme.txt: the description was 5,966 characters and WordPress.org renders only the first 2,500. It now fits in 2,390, so the feature list and the shortcode reference are no longer cut off.
* Social login: the profile payload no longer carries the provider's avatar URL. Nothing ever read it — the plugin has never imported a remote avatar — so it was collected and discarded. The field is gone from the payload the login filters receive.
* Housekeeping: the hourly order-autoclose event is now dropped on deactivation and on uninstall, even when the site owner chose to keep their data. WordPress keeps cron entries across a deactivation, so the event kept costing a run for a hook that no longer had a listener. The uninstall path used to clear a hook name no version of this plugin ever scheduled; it now clears the real one, plus the name used before the 2.7.0 rename.

No settings change is needed.

= 2.7.5 =
Documentation release. The only code change is the privacy wording this plugin suggests to site owners.

* Privacy: the suggested privacy-policy text said the plugin uses "no cookies of its own". That was wrong — a social-login attempt sets three short-lived cookies (the CSRF state value, the "stay signed in" choice and a refusal notice). The text now describes each one, its lifetime and its scope.
* readme.txt: a new "Privacy" section lists exactly what is stored, which cookies are set, and how a user's data can be exported or erased. The External services section pointed at a privacy section that did not exist; it does now.

No settings change is needed.

= 2.7.4 =
Security and disclosure release.

* External services: the readme now documents Gravatar, the avatar service WordPress uses by default — what it is for, the domains contacted, the data sent (a hash of the e-mail address, never the address itself), when the request is made, when it is not, and Gravatar's terms of service and privacy policy. Previously the account pages could load a Gravatar image without the readme saying so.
* Social login: the register-retry path no longer bypasses the `at8mluc_oauth_allow_email_link` filter. When account creation fails because the e-mail address is already taken, an existing account is reused only if the provider verified that address and the site has not switched e-mail linking off — the same rule the normal path uses. Otherwise the attempt is refused with a message telling the visitor to log in and link the account from their profile.
* Social login: the provider's display name is now written after authentication and binding succeed, not before, and only for an account this plugin created whose display name is still the generated login. A refused login no longer leaves a renamed account behind.
* Escaping: the custom CTA returned by the `at8mluc_hide_cta_html` filter is now passed through `wp_kses_post()`. Everything else the plugin prints was already escaped where the markup is built. The shortcode and `the_content` callbacks return the author's post content and add-on markup unchanged, and the code now says why: passing those through `wp_kses()` would strip the `<iframe>` embeds and the inline card script that an author or add-on is entitled to use.
* Custom profile fields: choice options are sanitised with `sanitize_text_field()` instead of only being trimmed, capped at 100 options per field, and a nested array in a text slot is discarded instead of being cast to the string "Array".
* Uninstall: with "Delete data on uninstall" enabled, the cleanup now sweeps every option under the plugin's prefix instead of a fixed list, so markers added by later versions (the 2.7.0 prefix migration, for example) no longer survive the uninstall.

No settings change is needed.

= 2.7.3 =
Security release. Social-login bindings are now written only after every login policy has passed, binding conflicts are refused instead of overwritten, and an unverified provider e-mail can no longer claim an existing account through the register-retry path. The points-table migration now verifies the real table structure - primary key shape, column types, NULL attributes, auto-increment flags and the idempotency unique indexes - before a single row is written, and refuses with a clear admin notice when anything disagrees. No settings change is needed.

= 2.7.2 =
Security and reliability release. Social logins now pass through one authentication adapter: it resolves the local account, checks its status, fires the core `wp_authenticate` event and lets security plugins refuse through the native `authenticate` and `wp_authenticate_user` filters. A refusal fires `wp_login_failed` and leaves no session cookie; "remember me" is off by default. The 2.7.0 points-table migration now reads the real table structure and compares every row column by column in bounded batches instead of trusting row counts; a conflict stops the run, is reported to administrators and never overwrites data. OAuth callback parameters reject arrays, objects and over-long values. Documentation: the author's published privacy-policy and terms-of-service URLs are now listed, and a FAQ entry explains that only the separately distributed Pro add-on performs licence checks against at8.fun. No settings change is needed.

= 2.7.1 =
* Social login: all five providers sign in through one routine that fires the standard `wp_login` action and offers a veto filter (`at8mluc_oauth_authenticate_user`), so security, two-factor and activity-log plugins see — and can refuse — a social login the same way they see a password login. Previously the session was created directly and no plugin was told.
* Social login: the OAuth state now has 32 random characters and its shape is checked before use, so a callback can only present a value this site minted.
* Social login: matching a verified provider e-mail to an existing account can be switched off with the `at8mluc_oauth_allow_email_link` filter for sites that would rather link every account by hand.
* Registration: the automatic sign-in after creating an account now goes through `wp_signon()` with the submitted credentials instead of setting the cookie directly, so the `authenticate` chain and `wp_login` run as they do for a normal login. If another plugin refuses the automatic sign-in, the account is still created and the visitor is sent to the login form.
* Login: removed a duplicate `wp_set_current_user()` / `wp_set_auth_cookie()` pair that ran after `wp_signon()` had already done both.
* Upgrade: the 2.7.0 rename migration now reports success, skipped, conflict or failed per step and writes its completion marker only when no step failed. A failed run is retried after 15 minutes instead of scanning the tables on every page view, and concurrent requests are serialised by a lease in the options table that is taken and released with compare-and-swap.
* Upgrade: a table that cannot be inspected is no longer read as "nothing to migrate", and a copied points table is verified by row count before the migration is called complete.

= 2.7.0 =
* Naming: every function, class, constant, option, hook, meta key, post type, shortcode, script handle and CSS class now uses the prefix `at8mluc` (previously `at8_mluc`) and `at8mlucp` for the add-on. The prefix is now long enough for the directory naming rules, and the old names never appear outside the one-time migration.
* Upgrade: a one-time migration copies the previous options, renames the meta keys and the avatar/order post types, and moves the two points tables. Nothing is deleted — old option rows and old tables are left in place — so an interrupted run can simply be repeated.
* Social login: callback parameters are read in one place, and a refused attempt leaves its notice in a short-lived cookie instead of the query string, so the login page no longer reads request input.
* Security: every AJAX handler now stops on a failed nonce check, including the two handlers that previously ignored the result (buy level, paywall purchase).
* Security: changing a password goes through `reset_password()`, so the core hooks and session cleanup run as they do in wp-admin.
* Points: transaction control statements are literal SQL inside a single switch, so no variable statement reaches the database.
* readme.txt: the external-services section now lists the domains contacted, what each request is for, the data exchanged and each provider's own terms and privacy links; a new "User accounts and authentication" section explains which core APIs are used and why.

= 2.6.6 =
* Social login: the callback parameters (provider, code, state, Apple's posted name) are now read in one place instead of a dozen scattered superglobal reads. The state parameter stays the CSRF token - a nonce cannot be used on a redirect that comes back from another domain - and every value is sanitised once, in the same helper.
* Social login: an existing binding is looked up with one indexed statement on the usermeta table instead of a full user query filtered by meta value, which is faster on sites with many accounts.
* Orders: the two conditional status updates and the two order queries now carry a written justification where they bypass the meta API, so the "direct query" and "slow meta query" reports point at a decision instead of at an oversight.
* Plugin Check: 0 errors and 0 warnings on the release package.

= 2.6.5 =
* Points: a refused COMMIT is now followed by a checked ROLLBACK. If that rollback also fails the error says the transaction state is unknown instead of pretending the change was undone, and nothing is retried automatically - a movement that may still be open must not be replayed.
* Points: every migration failure path closes its scope the same way and checks the result. A history that could not be imported is never reported as partly imported: either the whole import is rolled back or the caller is told the state is unknown.
* Points: all transaction failures carry the same structured data - operation, failure, transaction state, the original error, the rollback error, the user and the context - so a caller can tell "failed and rolled back" from "failed and unknown". Driver text stays out of the message shown to visitors and is recorded with the report instead.
* Points: the savepoint probe now needs two independent signals before it concludes that no transaction is open, and it checks creating, rewinding and releasing the probe. Any statement it cannot verify ends as an error rather than as an assumption.

= 2.6.4 =
* Points: an undeterminable transaction state is now an error instead of an assumption. The savepoint probe only accepts the documented "that savepoint does not exist" answer (1305 / SQLSTATE 3B001) as proof that no transaction is open; a savepoint that cannot be created, or one that cannot be rewound for any other reason, aborts the movement before anything is written.
* Points: committing a savepoint checks its own cleanup. When releasing it fails the movement is undone inside the enclosing transaction, and if that undo fails too the error says so plainly - the enclosing transaction must not be treated as if the movement were gone. Either way no success action fires and no balance mirror is written.
* Points: a repair that cannot write no longer looks like a busy account. reconcile() tells a refused statement (a database error, reported as such and never retried) from a version mismatch (a real conflict, retried a bounded number of times), and reports a failed rollback instead of continuing.
* Removed the obsolete has_open_transaction() helper that reported "no transaction" whenever the probe failed.

= 2.6.3 =
* Points: transaction ownership is decided instead of assumed. The plugin asks the database whether a transaction is already open, and when that answer is unavailable it probes with a throwaway savepoint; if neither tells it, the movement is refused rather than risking a START TRANSACTION that would silently commit another plugin's work.
* Points: a movement that only released a savepoint is no longer described as done. The balance and the ledger are written, but the balance meta is left alone and the success action is replaced by a "deferred" notice, so an outer transaction that later rolls back cannot leave a mirror or a side effect behind.
* Points: every transaction step reports its own failure. A savepoint that cannot be created aborts the movement, a release that fails undoes the movement inside the caller's transaction, and a rollback that fails raises a transaction error instead of being swallowed.
* Points: the balance is no longer faked as 0 when it cannot be read. balance() returns an error, can_afford() refuses, and the account screens show "unavailable" instead of a confident zero; the legacy history is also read straight from the table so a broken read is told apart from an empty one.

= 2.6.2 =
* Points: a failed ledger read can no longer be mistaken for "this account has no points". Balancing up against the ledger now aborts with an error, rolls back and leaves both the account row and the meta mirror untouched, instead of writing a zero over a real balance.
* Points: every read that can fail now tells "no such row" apart from "the query failed". The account lookup, the idempotency key lookup and the legacy history import return an explicit error rather than pretending the data does not exist, so a temporary database problem cannot trigger a second migration or a second award.
* Points: the schema check no longer waves a table through when the engine cannot be read. A failed or empty SHOW TABLE STATUS, a missing Engine field, and anything other than InnoDB all count as a broken schema, and the message names the table that could not be verified.

= 2.6.1 =
* Points: the legacy import is all-or-nothing now. Every movement is checked, the account row is only written once all of them are in, and a database error, a refused balance update or a failed COMMIT rolls the whole migration back instead of leaving a half-migrated account. While the tables are unavailable, reads fall back to the old meta total, so points never look lost.
* Points: transaction control is checked instead of assumed. A failed BEGIN or COMMIT returns an error and never fires the "balance changed" action, and when another plugin already has a transaction open a savepoint is used so committing cannot destroy its work.
* Points: the table structure is verified before the first write — both tables exist, both are InnoDB, the UNIQUE (user_id, idem_key) index and the account primary key are present, and the column types match what the writer expects. The schema option is only stored when that verification passes, and a broken schema returns a clear error instead of silently writing nothing.
* Points: reconcile() now uses the same versioned update as a normal movement, so a repair running next to live traffic can never overwrite a newer balance.

= 2.6.0 =
* Points: balances and movements moved out of user meta into two dedicated database tables. A movement is one transaction: a versioned UPDATE of the account row (the version is part of the WHERE clause, so a request whose snapshot is stale writes nothing) plus the ledger INSERT, which carries a UNIQUE (user_id, idem_key) idempotency key. The previous per-user lock and its TTL are gone with it — there is no lock to expire, no stale owner to take over, and no ledger row that is ever deleted after the fact.
* Points: every movement is recorded with the account version it was applied at, and reconcile() can rebuild an account row from the ledger if one is ever damaged.
* Upgrade: existing points, including the very old balance-only meta, are migrated lazily on the first read of each account. Legacy meta is kept, never deleted, and the migrated balance is identical to the previous one.
* Privacy: the personal-data exporter now includes point movements, and the eraser also removes the account and ledger rows.
* Uninstall: with "Delete data on uninstall" enabled, the two tables are dropped together with the rest of the plugin's data.

= 2.5.9 =
* Points: the per-user lease is now fenced. Every critical write is preceded by an atomic renewal whose compare-and-swap expects this request's exact lock value (token|expiry|fence), so a request that lost the lease — taken over after the TTL by another process, or swept while a slow third-party hook was running — is rejected with a WP_Error and writes nothing. The fence counter only moves forward, a stale owner can never release the new owner's lock, and a failed balance write rolls the ledger row back precisely.
* Verified with a real multi-process suite (8 concurrent awards, 8 concurrent deductions, overdraft contention, 8 different orders, 8 callbacks for one order) and a lease-semantics suite (takeover, stale writer, hook overrun, crash recovery, fence monotonicity).

= 2.5.8 =
* Points: exactly-once awarding is now enforced by the database instead of a read-then-write check. A movement with an idempotency key first creates a claim row in the options table, whose option_name column is UNIQUE, so two concurrent callbacks for the same order cannot both decide to write it: one wins, the others wait for the resulting ledger row and report the same balance. Previously two simultaneous callbacks could each pass the "no entry yet" check and both award points.
* Points: every movement for one user now runs inside a per-user mutex, taken with an atomic INSERT and released only by the request that owns it (compare-and-swap, no blind delete). The read-compute-write sequence can therefore no longer be interleaved, which closes two holes: a concurrent deduction could pass the overdraft check on a stale balance, and a concurrent recompute could write back a total that was already out of date.
* Points: the stored balance is no longer read to decide anything. It is a mirror written last and verified by read-back; balances and overdraft checks are always replayed from the ledger rows, and the replay is no longer clamped to zero, so a ledger that went negative is visible instead of hidden behind a 0.
* Points: the mutex and claim rows expire, so a request that dies while holding one cannot block an account; an abandoned claim is taken over through a compare-and-swap and the award completes exactly once. Both row types are removed on uninstall.
* Tests: added a real multi-process concurrency suite (8 simultaneous callbacks / deductions / mixed movements) plus fault-injection cases for a failed ledger write, a failed balance write, and an abandoned claim.

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
* Removed every artificial ceiling from the free plugin. Membership levels are no longer capped at three, the avatar library is no longer capped at twelve, and every registered payment gateway is offered instead of trimming the shelf to one. The filters that let an add-on raise those caps (`at8mluc_membership_level_limit`, `at8mluc_limit_avatar_count`, `at8mluc_limit_gateway_count`) and the unused `at8mluc_paywall_level_limit` filter are gone; the free plugin is complete on its own and nothing is unlocked by a licence.
* The paywall meta box now accepts several free-read levels per post (multi-select), and level meta written by older single-select versions keeps working.
* The Pro comparison table now describes only what the add-on adds — online gateways, tiered upgrades, paywall resources and extra content gates — instead of quota differences.
* Documentation updated: the feature list covers the paywall, points, custom fields and terms-of-service, and the data section names the social-login providers the server talks to.
* The Offline Payments settings section now states that refunds for the Pro online gateways are handled in the gateway dashboard and do not revoke a member's access automatically.

= 2.4.1 =
* New: the paywall core is now an extension surface. The gate always runs and an add-on extends the same post fields through the `at8mluc_paywall_enabled`, `at8mluc_paywall_levels`, `at8mluc_paywall_price`, `at8mluc_paywall_days`, `at8mluc_paywall_unlock_expire`, `at8mluc_paywall_locked_html`, `at8mluc_paywall_unlocked_html`, `at8mluc_paywall_meta_extra` and `at8mluc_paywall_can_purchase` filters instead of shipping its own content filter, so two gates can never double-lock the same post. An add-on with a richer paywall box takes the meta box over through `at8mluc_paywall_meta_box`.
* New: every upgrade method now renders from one registry. The Membership tab draws the points redemption card, the offline purchase card and any add-on path side by side through the `at8mluc_upgrade_paths` filter.
* New: `at8mluc_order_price` lets an add-on charge a differential for upgrade orders, and the purchase script binds every card on the page (purchase, upgrade ladders), not just the first.
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
* New: an accent colour setting. One colour tints buttons, active states and membership cards, and is exposed as `--at8mluc-accent` for custom CSS.
* New: a payment gateway shelf on the Offline Payments section, which states plainly what bank transfer does today and what PayPal / Stripe add.
* New: the order list now shows status, member, item, gateway and amount columns, with confirm / cancel actions on pending orders.
* Improved: front-end styles rebuilt on neutral translucent surfaces, so login, the account dashboard, membership cards and content gates look considered on light and dark themes alike. Membership badges now use each level's own colour.
* Improved: the avatar library is capped at twelve published avatars in the free version (unlimited in Pro). New avatars beyond the cap stay as drafts with an explanation, and editing existing avatars is never blocked.
* Fixed: built-in membership levels carried no colour of their own, so every badge fell back to the same blue. The factory defaults now define one, and the level editor reuses the same source of truth.
* Changed: membership level colours, order status labels and the level badge markup each had two definitions; they now have one.

= 2.2.1 =
* Fixed: the User Center menu is now built before other plugins add entries to it (`admin_menu` priority 5 plus a new `at8mluc_admin_menu` action). Previously a child page registered on the default priority got a different page hook at registration time than WordPress computed when the page was opened, and every Pro submenu page (License, the old Pro settings screen) was rejected with "Sorry, you are not allowed to access this page" (HTTP 403).

= 2.2.0 =
* New: top-level "User Center" menu in wp-admin (settings, orders, avatar library and the Pro license page all live under it) with a card-based settings screen and sticky section navigation.
* New: membership levels with a free base level, priced paid levels, automatic expiry, a level editor in the settings screen and a Membership tab with a comparison table and a buy / upgrade card in the account dashboard. The free version supports up to three levels; Pro lifts the cap to eight.
* New: offline payments — a bank-transfer gateway, order records, admin confirmation, automatic closing of abandoned pending orders and purchase history in the account dashboard.
* New: settings sections for membership levels, offline payments, sidebar menu icons, interface text, social login and the avatar library, all on one screen.
* AT8 Moonlight User Center Pro now contributes its own sections (online payment gateways, order management) to this screen instead of shipping a separate settings page.

= 2.1.0 =
* New: social login (WeChat, QQ, GitHub, Google, Apple) with per-provider credentials in Settings → User Center; buttons render under the login and registration forms.
* New: `[at8mluc_hide type="logged|reply"]` hidden-content shortcode with an editor button; Pro add-ons extend it with membership and purchase gates through the `at8mluc_hide_types` filter.
* New: login/register/profile Elementor widgets.
* The `[at8mlucp_hide]` shortcode name from the Pro 1.0.x era is accepted as an alias, so old content never breaks.

= 2.0.0 =
* New: menu items can be shown to logged-in users only or logged-out visitors only, and custom links accept the magic placeholders #at8mluc-login, #at8mluc-register, #at8mluc-account, #at8mluc-lostpassword and #at8mluc-logout.
* New: documented extension points for the commercial add-on AT8 Moonlight User Center Pro (`at8mluc_is_pro`, `at8mluc_account_tabs`, `at8mluc_login_form_bottom`, `at8mluc_register_form_bottom`). The free plugin itself remains complete and functional on its own and ships no locked code.
* Fix: the front-end stylesheet and script were still enqueued from their pre-rename paths, so the dashboard had no styling since 1.0.1.

= 1.0.2 =
* Plugin Check clean-up: removed the discouraged load_plugin_textdomain() call, every AJAX handler now verifies its nonce inline with check_ajax_referer(), and the account templates use prefixed local variables.
* The release zip no longer ships .gitignore: hidden files are not permitted in distributed plugins.

= 1.0.1 =
* Rebranded to AT8 Moonlight User Center (plugin slug, text domain and all internal identifiers).
* Fixed a fatal error on activation when another plugin had already defined the shared MLUC_* constants: every constant, class, function, option, meta key, shortcode and AJAX action now uses the collision-free AT8MLUC_ / at8mluc_ namespace, and the autoloader resolves classes from its own directory.
* Fixed the password recovery shortcode rendering nothing: the template file is now lostpassword.php to match the view slug.
* Renamed the front-end shortcodes to [at8mluc_login], [at8mluc_register], [at8mluc_lostpassword] and [at8mluc_account].

= 1.0.0 =
* First release.
* Front-end login, registration and password recovery.
* Account dashboard with overview, profile, avatar and password sections.
* Admin-curated avatar library.
* Personal data exporter and eraser, plus suggested privacy policy text.
* Uninstall option to remove the plugin's data.

== Upgrade Notice ==

= 2.7.6 =
Maintenance release: redeeming a level can no longer be charged twice, the profile hook ignores calls that did not come from the profile form, and the readme description now fits the 2,500-character limit WordPress.org renders. No settings change is needed.


= 2.7.3 =
Social-login account links are now written only after every login policy has passed, and the points-table migration checks the real table structure before writing anything. No settings change is needed.


= 2.7.2 =
Recommended for every site upgrading from 2.6.6 or earlier. The points-table migration is now verified row by row; if it finds a conflict it keeps all existing data, schedules a retry and shows an administrator notice instead of claiming success.


= 2.7.1 =
Security release: social logins now fire the standard `wp_login` action and other plugins can veto one, and the registration form signs in through `wp_signon()`. The 2.7.0 rename migration no longer reports success when a step failed. No settings change needed.

= 1.0.0 =
First release.
