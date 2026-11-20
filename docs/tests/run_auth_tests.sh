#!/usr/bin/env bash
#
# Authentication-chain suite for 2.7.2.
#
# The OAuth callback and the form handlers all end with a redirect or a JSON
# response, which means they end with `exit`. Each scenario therefore runs in
# its own PHP process and leaves its result in a JSON file, which
# oauth_chain_assert.php then checks. Nothing here inspects the source — every
# assertion is about an event the plugin actually fired.
#
set -u

# This file lives in <plugin>/docs/tests/, while the harness lives in the
# project root next to the plugin directory.
HERE="$(cd "$(dirname "$0")" && pwd)"
BASE="$(cd "$HERE/../../.." && pwd)"
PHP="/c/wptest-php/php.exe"
WPCLI="$BASE/_tools/wp-cli.phar"
SITE="$BASE/wptest/wordpress"
OUT="$BASE/_tmp_out"
CHILD="$BASE/_tools/oauth_chain_child.php"
ASSERT="$BASE/_tools/oauth_chain_assert.php"

mkdir -p "$OUT"
rm -f "$OUT"/auth_*.json

# Start from empty rate-limit buckets. The child process switches the throttle
# off, but a bucket filled by an earlier run or by hand would still make a
# scenario look broken for the wrong reason.
"$PHP" "$WPCLI" --path="$SITE" eval '
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \"\_transient\_at8mluc\_rl\_%\"" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \"\_transient\_timeout\_at8mluc\_rl\_%\"" );
' > /dev/null 2>&1

for scenario in oauth_login oauth_veto form_login weak_login register \
	oauth_native_veto oauth_user_policy_veto oauth_inactive oauth_identity_swap \
	oauth_native_sees_user oauth_already_signed_in oauth_remember \
	bind_ok bind_taken bind_conflict bind_after_veto \
	bind_retry_register_step1 bind_retry_register_step2 \
	email_unverified_no_hijack state_consume state_replay; do
	PM_SCENARIO="$scenario" \
	PM_OUT="$OUT/auth_$scenario.json" \
	"$PHP" "$WPCLI" --path="$SITE" eval-file "$CHILD" > "$OUT/auth_$scenario.out" 2>&1

	if [ ! -f "$OUT/auth_$scenario.json" ]; then
		echo "FAIL  $scenario produced no result (see $OUT/auth_$scenario.out)"
	fi
done

"$PHP" "$ASSERT" "$OUT"

# Remove the throwaway users the scenarios created.
#
# Accounts created through the OAuth register path are named
# `ml<provider>_<hash>`, so the two login prefixes below miss them. They are
# collected by their fixture binding instead, otherwise a run would leave an
# already-linked account behind and the next run's "a new account is created"
# assertions would take the reuse branch and invert.
"$PHP" "$WPCLI" --path="$SITE" eval '
foreach ( get_users( array( "search" => "pmc_*", "search_columns" => array( "user_login" ), "number" => -1 ) ) as $u ) { wp_delete_user( $u->ID ); }
foreach ( get_users( array( "search" => "pmr_*", "search_columns" => array( "user_login" ), "number" => -1 ) ) as $u ) { wp_delete_user( $u->ID ); }
global $wpdb;
$ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE \"at8mluc\_oauth\_%\" AND meta_value LIKE \"pm-%\"" );
foreach ( array_unique( array_map( "intval", (array) $ids ) ) as $id ) { if ( $id > 0 && get_userdata( $id ) ) { wp_delete_user( $id ); } }
echo "cleaned\n";
' 2>&1 | tail -1
