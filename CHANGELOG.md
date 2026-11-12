# Changelog

The WordPress.org `readme.txt` changelog is the authoritative, complete list.
This file summarises the 2.7.2 delivery for the GitHub repository.

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
