# Moonlight User Center

A lightweight, theme-agnostic front-end user center for WordPress. It gives a site
front-end login, registration, password recovery and a personal account area without
touching the WordPress admin.

**Version 1.0.0** · GPL-2.0-or-later · PHP 7.4+ · WordPress 5.8+

---

## What it does

| Feature | Shortcode |
| --- | --- |
| Login form | `[mluc_login]` |
| Registration form | `[mluc_register]` |
| Password recovery form | `[mluc_lostpassword]` |
| Account dashboard | `[mluc_account]` |

The account dashboard has four sections:

- **Overview** — display name, email, registration date, approved comment count
- **Profile** — display name, nickname, first/last name, website, phone, bio
- **Avatar** — pick from an administrator-curated avatar library
- **Password** — change password, which signs the user out of every other device

Place each shortcode on its own page, then choose those pages under
**Settings → User Center**. Any page you leave unset falls back to the equivalent
WordPress screen.

### Design principles

- **The WordPress user system is the only user system.** Accounts are created with
  `wp_create_user()`, authenticated with `wp_signon()`, and reset through
  `retrieve_password()`. This plugin never reads, stores, displays or transmits a
  plain-text password, and stores no password hash of its own.
- **No external communication.** No external HTTP requests, no third-party scripts
  or fonts, no trackers, no analytics, no license or update server.
- **No telemetry.** Nothing about your site or your users leaves the server.
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

1. Download `moonlight-user-center-1.0.0.zip` from the releases, or clone this
   repository into `wp-content/plugins/moonlight-user-center/`.
2. Activate the plugin under **Plugins**.
3. Create pages containing the shortcodes listed above.
4. Go to **Settings → User Center** and select those pages.
5. To offer avatars, add entries under **Avatar Library**, set a featured image on
   each, and publish.

---

## Theme overrides

Every template can be overridden from your theme. Copy a file from
`templates/` into `moonlight-user-center/` inside your active theme:

```
wp-content/themes/your-theme/moonlight-user-center/account-profile.php
```

Templates receive their data through an `$args` array, for example
`$args['user']` or `$args['library']`.

---

## Privacy and data

The plugin stores only what it needs:

| User meta key | Contents |
| --- | --- |
| `mluc_phone` | Phone number entered in the profile |
| `mluc_avatar_attachment_id` | Media ID of the chosen avatar |
| `mluc_avatar_url` | URL of the chosen avatar |
| `mluc_avatar_type` | How the avatar was chosen |
| `mluc_last_login` | Last login timestamp |

Settings live in a single `mluc_options` option. There are **no custom database tables**.

It registers a personal data exporter and eraser with the WordPress privacy tools, and
suggests privacy policy wording under **Settings → Privacy**. On uninstall you choose
whether the plugin's settings and profile fields are removed; WordPress user accounts,
posts and comments are never deleted.

---

## Development

### Package layout

```
moonlight-user-center/
├── moonlight-user-center.php   # bootstrap: constants, autoloader, module wiring
├── includes/                   # MLUC_* classes + helper functions
├── templates/                  # front-end templates (theme-overridable)
├── assets/css/ · assets/js/
├── languages/                  # .pot + zh_CN (.po/.mo)
├── uninstall.php
├── readme.txt                  # WordPress.org readme
└── LICENSE                     # GPL-2.0 full text
```

The autoloader maps `MLUC_Foo_Bar` to `includes/class-foo-bar.php`, so a new class
needs no registration.

### Conventions

- Every global function is prefixed `mluc_`, every class and constant `MLUC_`,
  every option and transient `mluc_`.
- All user-facing strings use the `moonlight-user-center` text domain with **English**
  source strings, so GlotPress and language packs work normally.
- All request input is unslashed and sanitised; all output is escaped late.
- State-changing handlers verify a nonce *and* a capability. Nonces are not authorization.

### Regenerating translations

The `.pot` was generated from source rather than hand-maintained. Regenerate it after
changing any translatable string:

```bash
# canonical method, using WP-CLI
wp i18n make-pot . languages/moonlight-user-center.pot --domain=moonlight-user-center

# compile an edited .po into the .mo that WordPress loads
msgfmt languages/moonlight-user-center-zh_CN.po \
       -o languages/moonlight-user-center-zh_CN.mo
```

`msgfmt` ships with gettext. On Windows it is available through Git for Windows or MSYS2.

---

## Project history

This repository previously hosted a **commercialised Free edition (v2.1.1)** that bundled
payment gateways (Alipay, PayPal, Stripe), a paywall, a license manager, paid membership
tiers, and a credits/balance ledger.

Version 1.0.0 is a rebuild to meet the WordPress.org Plugin Directory Guidelines. Under
guideline 5, functionality may not be restricted or locked behind payment, and premium
code belongs in a separate add-on plugin hosted off WordPress.org. The commercial code
was therefore removed entirely and the free plugin was rebuilt around login, registration,
password recovery, the account dashboard and the avatar library.

**The previous version is preserved.** It is reachable at any time:

```bash
git fetch --tags
git checkout legacy-v2.1.1     # the old commercial Free edition
```

Paid features continue in the separate repository
[moonlight-user-center-pro](https://github.com/361611074/moonlight-user-center-pro),
which this plugin does not depend on. All extension points are plain WordPress hooks
(`mluc_loaded`, `mluc_account_tabs`, `mluc_template_args`, `mluc_after_register`, …).

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
