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

BASE="$(cd "$(dirname "$0")/.." && pwd)"
PHP="/c/wptest-php/php.exe"
WPCLI="$BASE/_tools/wp-cli.phar"
SITE="$BASE/wptest/wordpress"
OUT="$BASE/_tmp_out"
CHILD="$BASE/_tools/oauth_chain_child.php"

mkdir -p "$OUT"
rm -f "$OUT"/auth_*.json

for scenario in oauth_login oauth_veto form_login weak_login register \
	oauth_native_veto oauth_user_policy_veto oauth_inactive oauth_identity_swap \
	oauth_native_sees_user oauth_already_signed_in oauth_remember; do
	PM_SCENARIO="$scenario" \
	PM_OUT="$OUT/auth_$scenario.json" \
	"$PHP" "$WPCLI" --path="$SITE" eval-file "$CHILD" > "$OUT/auth_$scenario.out" 2>&1

	if [ ! -f "$OUT/auth_$scenario.json" ]; then
		echo "FAIL  $scenario produced no result (see $OUT/auth_$scenario.out)"
	fi
done

"$PHP" "$BASE/_tools/oauth_chain_assert.php" "$OUT"

# Remove the throwaway users the scenarios created.
"$PHP" "$WPCLI" --path="$SITE" eval '
foreach ( get_users( array( "search" => "pmc_*", "search_columns" => array( "user_login" ), "number" => -1 ) ) as $u ) { wp_delete_user( $u->ID ); }
foreach ( get_users( array( "search" => "pmr_*", "search_columns" => array( "user_login" ), "number" => -1 ) ) as $u ) { wp_delete_user( $u->ID ); }
echo "cleaned\n";
' 2>&1 | tail -1
