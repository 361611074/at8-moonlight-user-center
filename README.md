# AT8 Moonlight User Center

A lightweight, theme-agnostic front-end user center for WordPress. It gives a site
front-end login, registration, password recovery and a personal account area without
touching the WordPress admin.

**Version 2.5.7** · GPL-2.0-or-later · PHP 7.4+ · WordPress 5.8+

---

## What it does

| Feature | Shortcode / where |
| --- | --- |
| Login form | `[at8_mluc_login]` |
| Registration form | `[at8_mluc_register]` |
| Password recovery form | `[at8_mluc_lostpassword]` |
| Account dashboard | `[at8_mluc_account]` |
| Hidden content | `[at8_mluc_hide type="logged\|reply"]` |
| Social login buttons | `[at8_mluc_oauth_buttons]` |
| Membership purchase | Membership tab in the account dashboard |

The account dashboard has five sections:

- **Overview** — display name, email, registration date, approved comment count
- **Profile** — display name, nickname, first/last name, website, phone, bio
- **Avatar** — pick from an administrator-curated avatar library
- **Password** — change password, which signs the user out of every other device
- **Membership** — current level, a level comparison table and a buy / upgrade card

Place each shortcode on its own page, then choose those pages under
**User Center → Settings**. Any page you leave unset falls back to the equivalent
WordPress screen.

### Membership, offline payments and extras

The free plugin ships a complete membership sales flow with **no artificial
limits** — every feature below is present and unlocked:

- **Unlimited** membership levels (a free base level plus paid ones with a price
  and validity in days), edited in **User Center → Settings → Membership Levels**
- Bank transfer as the offline gateway: the buyer sees transfer instructions, the
  order stays pending, and an administrator confirms payment in
  **User Center → Orders** — the level is then granted automatically
- Paid levels expire on their own; abandoned pending orders close automatically
  after a configurable number of hours
- Purchase history is visible in the account dashboard
- Configurable registration / profile fields (nine control types) and a points
  ledger with a welcome bonus and an earn rate, redeemable for level upgrades
- A paywall for single posts: per-post price, free-read levels and unlock expiry,
  sold through the same order engine

**AT8 Moonlight User Center Pro** adds PayPal and Stripe on top of bank transfer,
tiered upgrade pricing, paywall resources (video / download / gallery), level and
purchase content gates, demo videos, downloadable materials and a purchases
shortcode. Since 2.5.0 the free plugin has no artificial caps, so Pro no longer
"lifts limits" — it only contributes what it actually adds. The free plugin stays
fully functional on its own; nothing is locked.

### Admin menu

Everything lives under one top-level **User Center** menu: Settings (a card-based
screen with sticky section navigation), Orders, Avatar Library and — when Pro is
installed — License (Pro).

### Design principles

- **The WordPress user system is the only user system.** Accounts are created with
  `wp_create_user()`, authenticated with `wp_signon()`, and reset through
  `retrieve_password()`. This plugin never reads, stores, displays or transmits a
  plain-text password, and stores no password hash of its own.
- **No analytics, no telemetry.** The plugin never sends visitor or usage data for
  analysis, advertising or tracking purposes. The only outbound requests are the
  OAuth exchanges with the social-login providers the administrator enables, made
  when a visitor actively uses that login method (see the External services
  section of readme.txt for every endpoint, payload and policy link).
  When a provider is not enabled — or a visitor never uses those buttons — no
  request to that service is made at all.
- **Nothing is locked.** Every feature described here is present and unlocked.
- **Security by default.** Per-action nonces, capability checks on every state-changing
  handler, sanitised input, escaped output, and rate limiting on public endpoints.

---

## Requirements

- WordPress 5.8 or newer
- PHP 7.4 or newer
- For registration: the **Anyone can register** option enabled in Settings → General

---

## Installation

1. Download `at8-moonlight-user-center-2.5.7.zip` from the releases, or clone this
   repository into `wp-content/plugins/at8-moonlight-user-center/`.
2. Activate the plugin under **Plugins**.
3. Create pages containing the shortcodes listed above.
4. Go to **User Center → Settings** and select those pages.
5. To offer avatars, add entries under **Avatar Library**, set a featured image on
   each, and publish.
6. To sell memberships, define levels under **Membership Levels** and enter your
   transfer instructions under **Offline Payments**.

---

## Theme overrides

Every template can be overridden from your theme. Copy a file from
`templates/` into `at8-moonlight-user-center/` inside your active theme:

```
wp-content/themes/your-theme/at8-moonlight-user-center/account-profile.php
```

Templates receive their data through an `$args` array, for example
`$args['user']` or `$args['library']`.

---

## Privacy and data

The plugin stores only what it needs:

| User meta key | Contents |
| --- | --- |
| `at8_mluc_phone` | Phone number entered in the profile |
| `at8_mluc_avatar_attachment_id` | Media ID of the chosen avatar |
| `at8_mluc_avatar_url` | URL of the chosen avatar |
| `at8_mluc_avatar_type` | How the avatar was chosen |
| `at8_mluc_last_login` | Last login timestamp |
| `at8_mluc_membership_level` | Membership level slug held by the user |
| `at8_mluc_membership_expires` | Expiry timestamp of the current level (0 = never) |

Settings live in a single `at8_mluc_options` option. Order records are stored as a
custom post type (`at8_mluc_order`) with post meta — there are **no custom database
tables**.

It registers a personal data exporter and eraser with the WordPress privacy tools, and
suggests privacy policy wording under **Settings → Privacy**. On uninstall you choose
whether the plugin's settings, profile fields and order records are removed;
WordPress user accounts, posts and comments are never deleted.

---

## Development

### Package layout

```
at8-moonlight-user-center/
├── at8-moonlight-user-center.php   # bootstrap: constants, autoloader, module wiring
├── includes/                   # AT8_MLUC_* classes + helper functions
├── templates/                  # front-end templates (theme-overridable)
├── assets/css/ · assets/js/
├── languages/                  # .pot + zh_CN (.po/.mo)
├── uninstall.php
├── readme.txt                  # WordPress.org readme
└── LICENSE                     # GPL-2.0 full text
```

The autoloader maps `AT8_MLUC_Foo_Bar` to `includes/class-foo-bar.php`, so a new class
needs no registration.

### Conventions

- Every global function is prefixed `at8_mluc_`, every class and constant `AT8_MLUC_`,
  every option and transient `at8_mluc_`.
- All user-facing strings use the `at8-moonlight-user-center` text domain with **English**
  source strings, so GlotPress and language packs work normally.
- All request input is unslashed and sanitised; all output is escaped late.
- State-changing handlers verify a nonce *and* a capability. Nonces are not authorization.

### Regenerating translations

The `.pot` was generated from source rather than hand-maintained. Regenerate it after
changing any translatable string:

```bash
# canonical method, using WP-CLI
wp i18n make-pot . languages/at8-moonlight-user-center.pot --domain=at8-moonlight-user-center

# compile an edited .po into the .mo that WordPress loads
msgfmt languages/at8-moonlight-user-center-zh_CN.po \
       -o languages/at8-moonlight-user-center-zh_CN.mo
```

`msgfmt` ships with gettext. On Windows it is available through Git for Windows or MSYS2.

---

## Project history

*The sections below are version-by-version snapshots of this repository's past.
They describe the release they are named after, not the current behaviour — for
what the plugin does today, see the feature list above and the External
services section of readme.txt.*

This repository previously hosted a **commercialised Free edition (v2.1.1)** that bundled
payment gateways (Alipay, PayPal, Stripe), a paywall, a license manager, paid membership
tiers, and a credits/balance ledger.

Version 1.0.0 is a rebuild to meet the WordPress.org Plugin Directory Guidelines. Under
guideline 5, functionality may not be restricted or locked behind payment, and premium
code belongs in a separate add-on plugin hosted off WordPress.org. The commercial code
was therefore removed entirely and the free plugin was rebuilt around login, registration,
password recovery, the account dashboard and the avatar library.

### 1.0.1 — rebrand and the activation fatal error

The plugin shipped under the generic `MLUC_` / `mluc_` / `moonlight-user-center` namespace,
which the author's own shop plugin (`at8-moonlight-shop`) also defines when the old user
center is absent: the shop pre-defines `MLUC_VERSION` and `MLUC_PLUGIN_DIR` (pointing at
the shop's own directory) and registers its own `MLUC_` autoloader. Activating the
rebuilt plugin on such a site produced:

```
PHP Fatal error: Uncaught TypeError: call_user_func_array(): Argument #1 ($callback)
must be a valid callback, class "MLUC_Activator" not found
```

The autoloader resolved `includes/class-activator.php` through the stolen
`MLUC_PLUGIN_DIR`, looked inside the shop plugin, found nothing, and the activation
hook died. Version 1.0.1 renames every identifier to the `AT8_MLUC_` / `at8_mluc_` /
`at8-moonlight-user-center` namespace, which no other plugin defines, and the
autoloader now resolves classes from `__DIR__` so no shared constant can redirect
class loading. Shortcodes became `[at8_mluc_login]`, `[at8_mluc_register]`,
`[at8_mluc_lostpassword]` and `[at8_mluc_account]`.

**The previous version is preserved.** It is reachable at any time:

```bash
git fetch --tags
git checkout legacy-v2.1.1     # the old commercial Free edition
```

Paid features continue in the separate repository
[at8-moonlight-user-center-pro](https://github.com/361611074/at8-moonlight-user-center-pro),
which this plugin does not depend on. All extension points are plain WordPress hooks
(`at8_mluc_loaded`, `at8_mluc_account_tabs`, `at8_mluc_template_args`, `at8_mluc_after_register`, …).

### 2.5.0 — no artificial ceilings

WordPress.org guidelines prohibit holding free functionality behind a quantity cap
that a paid add-on lifts. Version 2.5.0 therefore removes every artificial ceiling:
membership levels, published avatars and payment gateways are **unlimited** in the
free plugin, the single-post paywall accepts any number of free-read levels, and the
Pro comparison table was rewritten to describe what Pro actually adds instead of
marketing lifted limits. The old `at8_mluc_membership_level_limit`,
`at8_mluc_limit_avatar_count`, `at8_mluc_limit_gateway_count` and
`at8_mluc_paywall_level_limit` filters no longer exist, and Pro 1.6.x no longer
references them. Historical tables below still mention the old caps — they describe
the version they are named after, not the current one.

### 2.4.0 — the free version gets the user centre it was missing

Version 2.4.0 rebalances the split again, this time along the axis that matters
to a site owner: **the free version has every feature, and Pro widens the paths
and the ceilings.**

| What | Free 2.4.0 | Pro |
| --- | --- | --- |
| Custom registration / profile fields | unlimited | unlimited |
| Points ledger, welcome bonus, earn rate | yes | yes |
| Ways to upgrade a member | redeem points | points, direct payment, tier ladders |
| Paywall for single posts | one level per post | any levels, videos, downloads |
| Membership levels | 3 | 8 |
| Published avatars | 12 | unlimited |
| Payment gateways | bank transfer | bank transfer, PayPal, Stripe |
| Content gates | login, comment | login, comment, level, purchase |

*(Historical record of 2.4.0. Since 2.5.0 the three quantity caps in this table are
gone — levels, avatars and gateways are unlimited in the free plugin.)*

New modules: `class-fields.php` (configurable fields, nine control types),
`class-points.php` (ledger with a hard floor of zero), `class-upgrade-paths.php`
(the registry the add-on extends) and `class-paywall.php` (the single-post
core, which stands down entirely when the add-on is active so two content
filters never run on the same post).

Two long-standing defects surfaced while testing this and are fixed here:

* **Factory levels carried no `price`, `validity` or `points`.** A site that
  never saved the level editor got arrays without those keys, so validity and
  price silently read as zero. Every consumer now gets the full key set.
* **Paywall orders were never marked paid.** `complete_order()` called
  `mark_paid()` on the paywall branch but never the atomic claim that flips
  pending to paid, so a paid order still read as pending, could be confirmed
  twice, and could be closed by the abandoned-order sweeper. The claim now
  lives inside the delivery handler — whoever delivers also claims, so the
  free core and the add-on can never double-claim each other.

### 2.3.0 — one boundary API, a rebuilt admin and a rebuilt front end

Version 2.3.0 introduces `AT8_MLUC_Upgrade`, the single source of truth for the
Free/Pro boundary. Every limit is a *quantity*, filterable by Pro:

| Resource | Free | Pro |
| --- | --- | --- |
| Membership levels | 3 | 8 |
| Published avatars | 12 | unlimited |
| Payment gateways | bank transfer | bank transfer, PayPal, Stripe |

*(Historical record of 2.3.0. These caps were removed in 2.5.0.)*

The ceiling is now **enforced**, not just advertised: `AT8_MLUC_Payments::get_gateways()`
trims the shelf to the licensed count and always keeps bank transfer, and avatars past
the cap stay drafts with an explanation instead of silently failing.

Also in 2.3.0: a redesigned settings screen (branded header, dismissible Pro
comparison table, shortcode cheat sheet, sticky save bar), an accent colour setting
exposed as `--at8-mluc-accent`, order list columns with inline confirm/cancel, front
end styles rebuilt on neutral translucent surfaces, and a fix for every membership
badge sharing one colour because the factory defaults carried none.

### 2.2.1 — admin menu registration order

Version 2.2.1 fixes an admin-menu bug. `add_menu_page()` populates WordPress's
`$admin_page_hooks`, and only afterwards does `add_submenu_page()` derive the same
page hook that core computes when the page is opened. Because Pro bootstraps on
`plugins_loaded` priority 6 and the free plugin on the default 10, Pro used to add
its submenus *first* — it got `admin_page_at8-mlucp-license` where core later looked
for `user-center_page_at8-mlucp-license`, so every Pro submenu page was rejected with
"Sorry, you are not allowed to access this page" (HTTP 403) even though the menu link
rendered fine.

The User Center menu is now built on `admin_menu` priority 5 and fires a dedicated
`at8_mluc_admin_menu` action afterwards; extensions should hook that instead of
`admin_menu`.

### 2.2.0 — membership, offline payments and the User Center menu

Version 2.2.0 rebalances the Free/Pro split so the free version offers the whole
feature set with **quantity limits**, not a crippled subset:

- **Membership levels** moved into the free plugin (up to three; Pro lifts the cap
  to eight through the `at8_mluc_membership_level_limit` filter). The level
  editor, the membership account tab and the purchase card ship unlocked.
  *(Historical: that filter and the cap were removed in 2.5.0.)*
- **Offline payments** moved in as well: the order engine (custom post type
  `at8_mluc_order` with an atomic pending→paid claim), the bank-transfer gateway
  and admin confirmation. Pro contributes PayPal / Stripe through the
  `at8_mluc_payment_gateways` and `at8_mluc_payment_order_response` filters.
- **The admin menu** became a single top-level **User Center** screen with a
  card-based settings page (general settings, membership levels, offline
  payments, sidebar menu icons, interface text, social login, avatar library)
  and sticky section navigation. Pro renders its own sections on the same screen
  through the `at8_mluc_register_settings` action.
- Pro is a pure add-on: no shared classes, no duplicated data. Pro-only order
  types (paywall) complete through the `at8_mluc_complete_paywall_order` filter
  so every payment type shares one atomic claim path.

### 1.0.2 — WordPress Plugin Check clean-up

Version 1.0.2 addresses every finding of the Plugin Check (PCP) report so the plugin is
ready for the WordPress.org directory:

- **Removed the `load_plugin_textdomain()` call.** Since WordPress 4.6 core loads the
  translation catalog for directory-hosted plugins from the translate server by itself;
  the manual call is discouraged there.
- **Nonce verification is inline.** Each AJAX handler calls
  `check_ajax_referer( …, false )` directly instead of routing the check through a
  helper method, so the verification is visible to static analysis. The two nonce
  helpers on `AT8_MLUC_Security` were removed; capability, login and rate-limit checks
  still go through that class.
- **Template variables are prefixed.** The account templates' local variables are now
  `$at8_mluc_user`, `$at8_mluc_avatar_url`, `$at8_mluc_library`, … matching the rest of
  the codebase.
- **The login redirect hint is passed as a parameter** to `resolve_redirect()` instead
  of being read from `$_POST` inside a helper, and both query-string readers
  (`current_view()`, `current_tab()`) now reject non-string input.
- **The release zip no longer contains `.gitignore`** — hidden files are not permitted
  in distributed plugins.

### Security fixes carried into 1.0.0

The rebuild also fixes defects found while auditing the previous code:

- **Passwords were read without `wp_unslash()`** in login, registration, password change
  and password reset — any password containing a backslash locked the user out.
- **The post-login redirect was validated with `wp_http_validate_url()`**, which allows
  external hosts, turning the login form into an open redirect.
- **All seven AJAX endpoints shared one nonce**, including the password-change endpoint.
  Every action now has its own nonce.
- **`uninstall.php` deleted meta with `LIKE 'mluc_%'`**, which would have destroyed other
  plugins' `mluc_*` keys. It now removes an explicit list of keys.
- **Every translatable string had a Chinese msgid**, which made language packs and
  GlotPress unusable. Source strings are now English.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

WordPress itself is licensed under the GPL, and plugins distributed through the
WordPress.org directory must be GPL-compatible.
