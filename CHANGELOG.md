# Changelog

The WordPress.org `readme.txt` changelog is the authoritative, complete list.
This file summarises each release for the GitHub repository.

## 2.7.6 — one charge per redemption, no phantom writes (2026-11-13)

### P2 — Points: a redemption is charged once
- `ajax_redeem()` now passes an idempotency key to `AT8MLUC_Points::adjust()`,
  built from the user, the level and the current day. A double click, a
  double submit or a replayed request replays the balance instead of taking
  the cost again. The level grant was already idempotent, so the two halves
  now agree. A later redemption of the same level, after it expired, lands
  on a different day and is charged normally.

### P2 — Custom fields: only the form may write
- `save_after_profile()` returns early when the profile nonce is absent from
  the request. The `at8mluc_after_profile_update` action is public, and a
  missing key reads as an empty value, so a programmatic caller firing it
  without the form would otherwise blank every custom field on the account.

### Documentation
- `readme.txt`: the description was 5,966 characters; WordPress.org renders
  the first 2,500. It is now 2,390, so the feature list and the shortcode
  reference are no longer cut off. An `== Upgrade Notice ==` entry exists for
  this version.
- `readme.txt`: the External services and Privacy sections are new since
  2.7.4 — see those entries below.

### Removed
- The OAuth profile payload no longer carries the provider's avatar URL.
  Nothing read it; the plugin has never imported a remote avatar. It is
  gone from the array the login filters receive.
- `uninstall.php` no longer clears `at8mluc_daily_maintenance`, a scheduled
  event no version of this plugin ever scheduled.

### Fixed — scheduled events outlive the plugin
- `AT8MLUC_Activator::deactivate()` now clears `at8mluc_pay_autoclose`.
  WordPress keeps cron entries across a deactivation, so the hourly event
  kept costing a run for a hook with no listener. `register_cpt()` schedules
  it again on the first `init` after reactivation.
- `uninstall.php` clears that hook unconditionally, including the
  `at8_mluc_pay_autoclose` name from before the 2.7.0 rename. It used to be
  cleared only when the site owner asked for their data to be deleted.

## 2.7.5 — the privacy text told the wrong story (2026-11-13)

### P2 — Privacy: cookies are disclosed
- The suggested privacy-policy text claimed the plugin uses "no cookies of
  its own". A social-login attempt sets three short-lived cookies: the CSRF
  state value (10 minutes), the "stay signed in" choice (10 minutes) and a
  refusal notice (5 minutes). The text now names each one, its lifetime and
  its scope.
- `readme.txt`: a `== Privacy ==` section lists what is stored per feature,
  which cookies are set, and how a user's data is exported or erased. The
  External services section pointed at a privacy section that did not exist.

## 2.7.4 — the WordPress.org pre-review findings (2026-11-13)

### P1 — Social login: the retry path could bypass a site policy
- `login_or_register()` and the register-retry path now share one decision
  function, `may_reuse_on_email_collision()`. When a provider profile
  collides with an existing address, reuse requires both that the site allows
  e-mail linking and that the provider marked the address verified. Before
  this, the retry path reached the same account without either condition.
- The display name from the provider is written only for an account this
  plugin created, only after authentication and binding have passed, and only
  while the account still shows its generated login as the display name. It
  can no longer overwrite a name an administrator set before the account was
  ever signed into.

### P2 — Output escaping
- The `at8mluc_hide_cta_html` filter output is passed through
  `wp_kses_post()`. The two seams that return post content keep it intact on
  purpose and are annotated: `wp_kses()` has no `iframe`, and the add-on
  appends video embeds and an inline card script there.

### P2 — Input sanitisation
- Custom-field choice options are sanitised per line with
  `sanitize_text_field()` and capped at 100 entries; a nested array in a text
  slot falls back instead of rendering as "Array".

### Disclosure
- `readme.txt`: an `== External services ==` section documents Gravatar —
  the two host families contacted, that only the e-mail hash is sent, when
  the service is and is not contacted, and its terms and privacy links.

## 2.7.3 — binding order and migration structure (2026-11-12)

### P1 — OAuth: authenticate first, bind afterwards
- `login_or_register()` no longer writes `update_user_meta()` bindings
  directly. Every path now hands the pending binding to `do_login()`, which
  writes it only after `authenticate_oauth_user()` has passed. A refused
  sign-in writes no binding, sets no cookie and fires no success action.
- Two binding conflicts are refused instead of silently overwritten: the
  third-party identity already belongs to another local account
  (`bind_taken`), or the local account already links a different identity of
  the same provider (`bind_conflict`). The login template shows a clear
  message for both.
- Register-retry: when a first-time sign-up is created but a login policy
  refuses it, the account stays unlinked and sessionless. A returning
  visitor is matched back to that account (by the provider-derived local
  e-mail, or by a provider-verified address) instead of failing forever.
  An unverified provider e-mail can never claim an existing account.

### P1 — Migration: verify the structure before writing
- `table_schema()` reads `SHOW FULL COLUMNS` and `SHOW INDEX` together;
  a failure to read either is an error, never an empty answer.
- `structure_problems()` compares primary key shape, every source column's
  type (display width folded), NULL attribute and auto-increment flag, plus
  the required unique indexes. The ledger's idempotency uniqueness
  `UNIQUE(user_id, idem_key)` must exist on both tables or the run reports
  `CONFLICT` before writing anything.
- The source table must be self-consistent too: a source that lost its own
  idempotency uniqueness is refused, because no migration can silently fix
  duplicate awards. Nothing is overwritten on refusal; the old tables stay,
  a retry is scheduled and the admin notice names the failing check.

### P1 — Documentation
- `CHANGELOG.md` no longer claims the policy pages are missing: both are
  live since 2026-11-12 and the entry now matches `readme.txt`.

### Tests
- 32 new auth-chain assertions (binding order, conflicts, register retry,
  unverified e-mail, state replay): 75 total.
- 25 new migration structure assertions on throwaway tables: primary key
  shape, types, NULL, auto-increment, unique indexes, 100-row bulk,
  equal-count/different-content, repeat-run idempotency.

## 2.7.2 — security / reliability (2026-11-11)

### Documentation (completed 2026-11-12)
- Published the author's real privacy policy at
  https://www.at8.fun/privacy-policy/ and terms of service at
  https://www.at8.fun/terms-of-service/ on the author's own WordPress site.
- `readme.txt`: added both URLs alongside the source repository and author
  website. A new FAQ entry explains that the free plugin has no phone-home,
  while the separately distributed Pro add-on contacts `www.at8.fun` for
  licence activation, validation and update checks, and lists exactly which
  fields that request carries.
- The release ZIP was regenerated after these documentation-only changes; its
  SHA-256 differs from the earlier 2.7.2 archive. No PHP code changed.

### P0 — OAuth joins the WordPress authentication chain
- `includes/class-oauth.php`: new `authenticate_oauth_user()` adapter separates
  provider verification, local account resolution, local login policy and
  session creation. It refuses a missing account, a non-member account on
  multisite and any account rejected by `at8mluc_oauth_user_is_active`.
- The adapter fires the core `wp_authenticate` event, keeps the 2.7.1 veto
  filter `at8mluc_oauth_authenticate_user`, and bridges into the native
  `authenticate` and `wp_authenticate_user` filters with the already resolved
  `WP_User`. Core's password validators short-circuit on a `WP_User`, so no
  password is forged and no password check is performed.
- `oauth_denied()` fires the core `wp_login_failed` event and the new
  `at8mluc_oauth_login_denied` action; the visitor only sees a generic message.
  A filter that swaps the identity for another user is refused.
- `do_login()` no longer sets a second cookie when the visitor is already
  signed in as the same account, and no longer hard-codes `remember = true`.
  `at8mluc_oauth_remember` and the new short-lived click cookie control it.
- `assets/js/at8mluc.js`: forwards the login form's "remember me" checkbox to
  the callback in a 10-minute first-party cookie, read and cleared server-side.

### P1 — points-table migration verifies every row
- `includes/class-prefix-migration.php`: `move_points_table()` now reads the
  real columns and primary key via `SHOW COLUMNS`, then uses three bounded
  passes (read-only inspection, insert missing keys only, final re-verification)
  comparing every column of every row. Row counts are no longer proof of
  success. An atomic `RENAME TABLE` is reported as such.
- Conflicts (same primary key, different data), missing columns, SQL errors and
  read-back mismatches stop the run: the old table is kept, nothing is
  overwritten, a retry is scheduled, and `admin_notices` shows the failing
  steps. `at8mluc_prefix_migration_verified` publishes a report containing
  only counts and column names.
- `maybe_run()` treats a `CONFLICT` on the `tables` step as unfinished while
  leaving the benign "both names already exist" conflicts on meta/options alone.

### P2 — OAuth callback input validation
- `callback_param()` accepts only string/int/float scalars and enforces
  per-parameter length caps; arrays, nested arrays, objects, booleans,
  non-finite floats and over-long values are refused without a cast.
- `requested_redirect()`, `callback_user_names()` and the state cookie use the
  same scalar checks; `at8mluc_oauth_malformed_input` reports the parameter
  name and reason without logging the value.

### Documentation
- `readme.txt`: added a "Project and author" section with the real repository,
  author website and the author's published privacy policy and terms of
  service (`https://www.at8.fun/privacy-policy/` and
  `https://www.at8.fun/terms-of-service/`, both live since 2026-11-12).
  Plugin URI is deliberately left unset because there is no dedicated
  product page for this plugin yet. (History note: an earlier draft of this
  entry said the two pages were missing and BLOCKED; the pages were published
  on the author's site the next day, so the URLs above are the current,
  verified state.)

### Tests added
- `_tools/points_verify_test.php` (33 assertions): 10 migration scenarios.
- `_tools/oauth_param_test.php` (27 assertions): type/length inputs, no warnings.
- `_tools/payment_regression_test.php` (20 assertions): order/payment security.
- `_tools/run_auth_tests.sh` + child/assert (43 assertions): real processes,
  including native-filter refusals and the no-cookie guarantee.
- `_tools/prefix_migration_test.php` (31 assertions): migration orchestration.
