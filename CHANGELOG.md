# Changelog

The WordPress.org `readme.txt` changelog is the authoritative, complete list.
This file summarises the 2.7.2 delivery for the GitHub repository.

## 2.7.2 — security / reliability (2026-11-11)

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
- `readme.txt`: added a "Project and author" section with the real repository
  and author website. **No privacy-policy or terms-of-service URL was added:
  those pages do not exist on at8.fun (both return 404) and inventing a URL is
  forbidden by the task brief.** This is tracked as BLOCKED in the delivery
  report. Plugin URI is deliberately left unset because there is no dedicated
  product page for this plugin yet.

### Tests added
- `_tools/points_verify_test.php` (33 assertions): 10 migration scenarios.
- `_tools/oauth_param_test.php` (27 assertions): type/length inputs, no warnings.
- `_tools/payment_regression_test.php` (20 assertions): order/payment security.
- `_tools/run_auth_tests.sh` + child/assert (43 assertions): real processes,
  including native-filter refusals and the no-cookie guarantee.
- `_tools/prefix_migration_test.php` (31 assertions): migration orchestration.
