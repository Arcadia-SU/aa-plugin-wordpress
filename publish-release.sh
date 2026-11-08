#!/usr/bin/env bash
#
# Publish a deployed version as a GitHub Release (last step of docs/deploy.md).
#
# The release is the public "latest version" source: the Arcadia app's
# workspace page links to
#   https://github.com/Arcadia-SU/aa-plugin-wordpress/releases/latest/download/arcadia-agents.zip
# and reads the version + date from the GitHub API. So "latest" must only ever
# mean "running in production": the script refuses to publish until every prod
# host in test/upgrade/deployed-versions.conf answers that version on /health.
#
# Usage: ./publish-release.sh            → the version in the plugin header
#        ./publish-release.sh 0.11.0     → that exact version
#        DRY_RUN=1 ./publish-release.sh  → run every check, publish nothing
#
set -euo pipefail

REPO="Arcadia-SU/aa-plugin-wordpress"
PLUGIN_FILE="arcadia-agents/arcadia-agents.php"
README="arcadia-agents/readme.txt"
DEPLOYED_CONF="test/upgrade/deployed-versions.conf"
ASSET_NAME="arcadia-agents.zip" # Stable name: the /releases/latest/download/ URL depends on it.

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

check() { echo -e "${BLUE}▸${NC} $1"; }
pass() { echo -e "  ${GREEN}✓${NC} $1"; }
fail() {
	echo -e "  ${RED}✗ $1${NC}"
	exit 1
}

cd "$(dirname "$0")"

# ─── 1. Version ─────────────────────────────────────────────────────────────

check "Version"
HEADER_VERSION=$(sed -n 's/^ \* Version: *//p' "$PLUGIN_FILE")
VERSION="${1:-$HEADER_VERSION}"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "'${VERSION}' is not MAJOR.MINOR.PATCH"
TAG="v${VERSION}"
pass "${TAG}"

# ─── 2. Not already published ───────────────────────────────────────────────

check "Release not already published"
if gh release view "$TAG" --repo "$REPO" >/dev/null 2>&1; then
	fail "${TAG} is already published — a release is never re-cut (bump instead)"
fi
pass "no release ${TAG} yet"

# ─── 3. The archived zip, and only that one ─────────────────────────────────

check "Archived zip (the one the sites received)"
ZIP="dist/arcadia-agents-${VERSION}.zip"
[ -f "$ZIP" ] || fail "${ZIP} missing — the release ships the dist/ zip, never a rebuild"
ZIP_VERSION=$(unzip -p "$ZIP" arcadia-agents/arcadia-agents.php | sed -n 's/^ \* Version: *//p')
[ "$ZIP_VERSION" = "$VERSION" ] || fail "${ZIP} declares version '${ZIP_VERSION}', expected ${VERSION}"
pass "${ZIP} declares ${VERSION}"

# ─── 4. Release commit, pushed ──────────────────────────────────────────────

check "Release commit on origin/main"
git fetch --quiet origin main
COMMIT=$(git log origin/main -1 --format=%H --grep "^release: ${TAG}( |$)" -E)
[ -n "$COMMIT" ] || fail "no 'release: ${TAG}' commit on origin/main — commit and push the build first"
pass "$(git log -1 --format='%h %s' "$COMMIT" | cut -c1-90)"

# ─── 5. Production gate: every prod host runs this version, live ────────────
#
# Reads the hosts tagged `prod` in deployed-versions.conf and probes them
# through ?rest_route= with a cache-buster: on o2switch hosts the /wp-json/
# path is cached and kept answering the previous version after an upgrade.

check "Every prod host runs ${VERSION} (live /health)"
PROD_HOSTS=$(grep -vE '^[[:space:]]*(#|$)' "$DEPLOYED_CONF" | awk '$3 == "prod" {print $1}')
[ -n "$PROD_HOSTS" ] || fail "no host tagged 'prod' in ${DEPLOYED_CONF}"
for host in $PROD_HOSTS; do
	body=$(curl -fsS --max-time 15 "https://${host}/?rest_route=/arcadia/v1/health&t=$(date +%s)" 2>&1) ||
		fail "${host}: /health unreachable (${body})"
	live=$(echo "$body" | sed -n 's/.*"version":"\([^"]*\)".*/\1/p')
	[ "$live" = "$VERSION" ] || fail "${host} runs '${live:-?}', not ${VERSION} — finish the prod step first"
	pass "${host} ${live}"
done

# ─── 6. Release notes = the changelog entry ─────────────────────────────────

check "Changelog entry"
NOTES=$(awk -v v="= ${VERSION} =" '$0 == v {on=1; next} on && /^= [0-9]/ {exit} on' "$README")
[ -n "$(echo "$NOTES" | tr -d '[:space:]')" ] || fail "no '= ${VERSION} =' entry in ${README}"
pass "$(echo "$NOTES" | grep -c '^\*') bullet(s)"

if [ "${DRY_RUN:-0}" = "1" ]; then
	echo -e "${GREEN}All checks passed — DRY_RUN, nothing published.${NC}"
	echo "$NOTES"
	exit 0
fi

# ─── 7. Publish ─────────────────────────────────────────────────────────────

check "Publish ${TAG}"
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
cp "$ZIP" "${STAGE}/${ASSET_NAME}"
echo "$NOTES" >"${STAGE}/notes.md"
gh release create "$TAG" "${STAGE}/${ASSET_NAME}" \
	--repo "$REPO" \
	--target "$COMMIT" \
	--title "$TAG" \
	--notes-file "${STAGE}/notes.md" \
	--latest
git fetch --quiet --tags origin
pass "https://github.com/${REPO}/releases/tag/${TAG}"

check "Stable download URL"
curl -fsSIL --max-time 30 -o /dev/null "https://github.com/${REPO}/releases/latest/download/${ASSET_NAME}" ||
	fail "releases/latest/download/${ASSET_NAME} does not resolve"
pass "https://github.com/${REPO}/releases/latest/download/${ASSET_NAME}"
