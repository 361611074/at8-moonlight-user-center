=== AT8 Moonlight User Center ===
Contributors: 361611074
Tags: user profile, login, registration, avatar, user center
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, theme-agnostic user center: front-end login, registration, password recovery, profile editing and an avatar library.

== Description ==

AT8 Moonlight User Center gives a WordPress site a complete front-end account area without touching the WordPress admin. It is deliberately small: it uses the WordPress user system for everything, stores no passwords of its own, and contacts no external service.

**What it provides**

* Front-end login form
* Front-end registration that honours the WordPress "Anyone can register" setting
* Password recovery through the standard WordPress reset email
* An account dashboard with overview, profile, avatar and password sections
* Profile editing for the display name, nickname, first and last name, website address, phone number and biographical info
* An avatar library: an administrator publishes a set of avatars and each user picks one
* A password change form that signs the user out of every other device

**Shortcodes**

* `[at8_mluc_login]` — login form
* `[at8_mluc_register]` — registration form
* `[at8_mluc_lostpassword]` — password recovery form
* `[at8_mluc_account]` — account dashboard

Place each shortcode on its own page, then choose those pages under Settings → User Center. Any page you leave unset falls back to the equivalent WordPress screen.

**How it handles your data**

* All accounts are created with the WordPress user API. Passwords are hashed by WordPress and are never read, stored, displayed or transmitted by this plugin.
* Every form submission is protected by a nonce, every action is checked against the user's capabilities, all input is sanitised and all output is escaped.
* Submission endpoints are rate limited.
* The plugin makes no external requests, loads no third-party scripts or fonts, and embeds no trackers or analytics.
* It registers a personal data exporter and eraser, so a user's information can be exported or erased through Tools → Export Personal Data and Tools → Erase Personal Data.
* On uninstall you choose whether the plugin's settings and the profile fields it added are removed. WordPress user accounts, posts and comments are never deleted.

**Theme compatibility**

The plugin ships minimal, namespaced CSS that inherits your theme's colours and typography. Any template can be overridden by copying it into `at8-moonlight-user-center/` inside your theme.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through Plugins → Add New.
2. Activate the plugin.
3. Create a page for the account dashboard containing the `[at8_mluc_account]` shortcode, and a page each for `[at8_mluc_login]`, `[at8_mluc_register]` and `[at8_mluc_lostpassword]`.
4. Go to Settings → User Center and select those pages.
5. For registration, make sure "Anyone can register" is enabled in Settings → General.
6. To offer avatars, add entries under Avatar Library, give each one a featured image, and publish.

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

Nothing is removed unless you enable "Delete data on uninstall" in Settings → User Center. With it enabled, deleting the plugin removes its settings and the profile fields it added. WordPress user accounts, posts and comments are always left alone.

= Does it work with any theme? =

Yes. Styles are namespaced and inherit the theme wherever possible, and any template can be overridden from your theme.

== Screenshots ==

1. The account dashboard overview.
2. The profile editor.
3. The avatar library picker.
4. The front-end login form.

== Changelog ==

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
