#!/usr/bin/env bash
#
# Upgrade-path test: proves the candidate zip survives the one operation every
# client site will actually perform — an UPGRADE, not a fresh install.
#
# Scenario (mirrors a real client deployment):
#   1. Boot an ephemeral WordPress (see docker-compose.upgrade.yml) backed by
#      a throwaway database on the DEV stack's MySQL (created/dropped here)
#   2. Install and activate the BASELINE zip (the last released version)
#   3. Seed representative data under the baseline: connection options, a
#      published post with adversarial characters, plugin meta
#   4. Upgrade to the CANDIDATE zip with `wp plugin install --force`
#      (files replaced while the plugin stays active — the client operation)
#   5. Assert: plugin active, /health returns the new version, seeded data
#      byte-identical, aa_revision CPT still registered, no PHP fatals logged
#
# Usage:
#   test/upgrade/run-upgrade-test.sh <baseline.zip> <candidate.zip> <expected_version>
#   test/upgrade/run-upgrade-test.sh --fresh-only <candidate.zip> <expected_version>
#
# Zip paths are relative to the repo root (they are read inside the containers
# through the /repo read-only mount). --fresh-only skips the baseline phase and
# validates the candidate on a virgin WordPress instead (first build, no dist/).
#
# Env: KEEP_STACK=1 keeps the stack alive after the run for debugging.
#
set -euo pipefail

BASELINE="${1:?usage: run-upgrade-test.sh <baseline.zip|--fresh-only> <candidate.zip> <expected_version>}"
CANDIDATE="${2:?missing candidate zip}"
EXPECTED_VERSION="${3:?missing expected version}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
# --progress quiet: `compose run` prints "Container ... Creating/Created"
# noise on stderr for every wp-cli call otherwise.
COMPOSE=(docker compose --progress quiet -p arcadia-upgrade -f "${SCRIPT_DIR}/docker-compose.upgrade.yml")
TEST_DB="wordpress_upgrade_test"
DEV_DB_CONTAINER="arcadia-wp-db"

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

say()  { echo -e "  ${BLUE}→${NC} $1"; }
ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
die()  { echo -e "  ${RED}✗${NC} $1"; exit 1; }

# String equality with a labeled diff on failure.
assert_eq() {
	local actual="$1" expected="$2" label="$3"
	if [ "$actual" != "$expected" ]; then
		echo -e "  ${RED}✗${NC} ${label}"
		echo "    expected: ${expected}"
		echo "    actual:   ${actual}"
		exit 1
	fi
	ok "$label"
}

# Run wp-cli inside the ephemeral stack.
wp() { "${COMPOSE[@]}" run --rm -T cli wp "$@"; }

# ─── Teardown (always, unless KEEP_STACK=1) ─────────────────────────────────

teardown() {
	if [ "${KEEP_STACK:-0}" = "1" ]; then
		echo -e "  ${BLUE}→${NC} KEEP_STACK=1 — stack left running (project arcadia-upgrade)."
		return
	fi
	"${COMPOSE[@]}" down -v --remove-orphans &>/dev/null || true
	docker exec "$DEV_DB_CONTAINER" mysql -uroot -proot \
		-e "DROP DATABASE IF EXISTS ${TEST_DB};" &>/dev/null || true
}
trap teardown EXIT

# ─── Preflight ──────────────────────────────────────────────────────────────

# The test rides on the dev stack's MySQL (a second MySQL server is unreliable
# on a loaded machine) — so the dev stack must be up, which build.sh check #1
# already guarantees.
docker exec "$DEV_DB_CONTAINER" mysqladmin -uroot -proot ping &>/dev/null \
	|| die "Dev MySQL (${DEV_DB_CONTAINER}) is not running. Run ./start.sh first."

# The compose file joins the dev stack's network to reach its MySQL; resolve
# the network name dynamically instead of hardcoding the project directory name.
ARCADIA_DEV_NETWORK=$(docker inspect "$DEV_DB_CONTAINER" \
	-f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}')
[ -n "$ARCADIA_DEV_NETWORK" ] || die "Could not resolve the dev stack's Docker network."
export ARCADIA_DEV_NETWORK

[ -f "${REPO_ROOT}/${CANDIDATE}" ] || die "Candidate zip not found: ${CANDIDATE}"
if [ "$BASELINE" != "--fresh-only" ]; then
	[ -f "${REPO_ROOT}/${BASELINE}" ] || die "Baseline zip not found: ${BASELINE}"
	BASELINE_VERSION=$(unzip -p "${REPO_ROOT}/${BASELINE}" arcadia-agents/arcadia-agents.php \
		| sed -n "s/.*define( 'ARCADIA_AGENTS_VERSION', '\([0-9.]*\)' ).*/\1/p")
	[ -n "$BASELINE_VERSION" ] || die "Could not read version from baseline zip."
fi

# ─── Boot the ephemeral stack ───────────────────────────────────────────────

say "Booting ephemeral WordPress..."
# Clean slate even if a previous run was kept or crashed.
"${COMPOSE[@]}" down -v --remove-orphans &>/dev/null || true
docker exec "$DEV_DB_CONTAINER" mysql -uroot -proot \
	-e "DROP DATABASE IF EXISTS ${TEST_DB}; CREATE DATABASE ${TEST_DB};" \
	|| die "Could not create throwaway database ${TEST_DB}."
"${COMPOSE[@]}" up -d --quiet-pull upgrade-wp &>/dev/null

# Wait for the wordpress entrypoint to finish copying core files and
# generating wp-config.php — the only asynchronous part of the boot (the
# database is the dev MySQL, already pinged in preflight). Do NOT probe with
# `wp db check`: the MariaDB client in wordpress:cli cannot talk to MySQL 8
# at all (TLS cert verification on by default → error 2026; --skip-ssl then
# hits the missing caching_sha2_password plugin → error 1045). PHP's mysqli —
# what WordPress and every other wp-cli command use — handles both fine, so
# `wp core install` below is the real end-to-end DB connectivity check.
booted=0
for _ in $(seq 1 36); do
	if "${COMPOSE[@]}" run --rm -T cli test -f /var/www/html/wp-config.php &>/dev/null; then
		booted=1
		break
	fi
	sleep 5
done
if [ "$booted" != "1" ]; then
	# Timeout diagnostics: replay the probe and dump the container logs so the
	# failure is never silent (this exact spot has already burned two debugging
	# sessions behind an &>/dev/null).
	echo "  ── diagnostics ──"
	echo "  [/var/www/html]"
	"${COMPOSE[@]}" run --rm -T cli ls -la /var/www/html 2>&1 | sed 's/^/    /' || true
	echo "  [upgrade-wp logs, last 30 lines]"
	"${COMPOSE[@]}" logs --tail=30 upgrade-wp 2>&1 | sed 's/^/    /' || true
	die "WordPress did not become ready within 180s."
fi
ok "WordPress ready (core files + throwaway database)."

install_out=$(wp core install \
	--url=http://upgrade-wp \
	--title="Arcadia Upgrade Test" \
	--admin_user=admin \
	--admin_password=admin \
	--admin_email=upgrade-test@example.com \
	--skip-email 2>&1) || die "wp core install failed: ${install_out}"
ok "WordPress core installed."

# In-network REST call; ?rest_route= works without any permalink setup.
health() {
	wp eval 'print( wp_remote_retrieve_body( wp_remote_get( "http://upgrade-wp/?rest_route=/arcadia/v1/health" ) ) );' 2>/dev/null
}

# ─── Phase 1: baseline (skipped in --fresh-only) ────────────────────────────

if [ "$BASELINE" != "--fresh-only" ]; then
	say "Installing baseline ${BASELINE} (v${BASELINE_VERSION})..."
	out=$(wp plugin install "/repo/${BASELINE}" --activate 2>&1) \
		|| { echo "$out" | sed 's/^/    /'; die "Baseline zip failed to install/activate."; }

	body=$(health)
	echo "$body" | grep -qF '"status":"ok"' || die "Baseline /health not ok: ${body}"
	echo "$body" | grep -qF "\"version\":\"${BASELINE_VERSION}\"" \
		|| die "Baseline /health version mismatch: ${body}"
	ok "Baseline active, /health ok (v${BASELINE_VERSION})."
else
	say "Fresh-only mode: installing candidate on a virgin WordPress..."
	out=$(wp plugin install "/repo/${CANDIDATE}" --activate 2>&1) \
		|| { echo "$out" | sed 's/^/    /'; die "Candidate zip failed to install/activate on a fresh site."; }
fi

# ─── Phase 2: seed representative data ──────────────────────────────────────
#
# Written under the OLD version (or fresh install), read back under the NEW
# one. The content deliberately carries the wp_slash bug-class corpus:
# backslashes, quotes, accents, emoji.

say "Seeding representative data..."

wp option update arcadia_agents_connected 1 &>/dev/null
wp option update arcadia_agents_site_id "upgrade-test-site" &>/dev/null
wp option update arcadia_agents_issuer "https://api.arcadia.example" &>/dev/null

SEED_CONTENT='<!-- wp:paragraph --><p>Corpus: backslash \n \\ quotes "d" '"'"'s'"'"' accents éàç emoji ✅ &amp; fin</p><!-- /wp:paragraph -->'
POST_ID=$(wp post create \
	--post_title="Upgrade seed post" \
	--post_status=publish \
	--post_content="$SEED_CONTENT" \
	--porcelain)
wp post meta update "$POST_ID" arcadia_upgrade_seed '{"origin":"arcadia","note":"Backslash \\ et \"quotes\""}' &>/dev/null

# Snapshot AFTER writing: the invariant under test is that the upgrade does not
# alter stored data, not how wp-cli encodes it on the way in.
PRE_CONTENT=$(wp post get "$POST_ID" --field=post_content)
PRE_META=$(wp post meta get "$POST_ID" arcadia_upgrade_seed)
PRE_SITE_ID=$(wp option get arcadia_agents_site_id)

wp post-type list --field=name 2>/dev/null | grep -qx "aa_revision" \
	|| die "aa_revision CPT not registered before upgrade — seed phase broken."
ok "Seed in place (post ${POST_ID}, options, meta)."

# ─── Phase 3: upgrade to the candidate ──────────────────────────────────────

if [ "$BASELINE" != "--fresh-only" ]; then
	say "Upgrading to candidate ${CANDIDATE} (files replaced, plugin stays active)..."
	out=$(wp plugin install "/repo/${CANDIDATE}" --force 2>&1) \
		|| { echo "$out" | sed 's/^/    /'; die "Candidate zip failed to install over the baseline."; }
fi

# ─── Phase 4: assertions ────────────────────────────────────────────────────

say "Asserting post-upgrade state..."

status=$(wp plugin list --name=arcadia-agents --field=status 2>/dev/null | tr -d '[:space:]')
assert_eq "$status" "active" "Plugin is still active"

body=$(health)
echo "$body" | grep -qF '"status":"ok"' || die "/health not ok after upgrade: ${body}"
echo "$body" | grep -qF "\"version\":\"${EXPECTED_VERSION}\"" \
	|| die "/health reports wrong version (expected ${EXPECTED_VERSION}): ${body}"
ok "/health ok, version ${EXPECTED_VERSION}."

assert_eq "$(wp post get "$POST_ID" --field=post_content)" "$PRE_CONTENT" "Post content byte-identical"
assert_eq "$(wp post meta get "$POST_ID" arcadia_upgrade_seed)" "$PRE_META" "Post meta byte-identical"
assert_eq "$(wp option get arcadia_agents_site_id)" "$PRE_SITE_ID" "Connection options intact"

wp post-type list --field=name 2>/dev/null | grep -qx "aa_revision" \
	|| die "aa_revision CPT no longer registered after upgrade."
ok "aa_revision CPT still registered."

# Any PHP fatal logged during the run is a failure, even if requests recovered.
fatals=$(wp eval 'echo file_exists( WP_CONTENT_DIR . "/debug.log" ) ? file_get_contents( WP_CONTENT_DIR . "/debug.log" ) : "";' 2>/dev/null \
	| grep -i "PHP Fatal" || true)
if [ -n "$fatals" ]; then
	echo "$fatals"
	die "PHP fatal error(s) logged during the test."
fi
ok "No PHP fatals logged."

echo -e "  ${GREEN}✓${NC} Upgrade path validated."
