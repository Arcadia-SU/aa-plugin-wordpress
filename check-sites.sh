#!/usr/bin/env bash
#
# Check every client site in test/upgrade/deployed-versions.conf: the version it
# really runs (live /health) and whether this machine holds its access secrets.
#
# The conf is the only list of sites. Each site's secrets live in one file on the
# Mac mini, named after its host in that list:
#   ~/Developer/secrets/wp-sites/<host>.env      (chmod 600, outside every repo)
# Oscar writes them from the Air, never through a chat:
#   scripts/secret.sh <KEY> mini:secrets/wp-sites/<host>.env   (Arcadia monorepo)
#
# Keys (KEY=value, one per line, value unquoted — this script parses, never sources):
#   BASIC_AUTH_USER, BASIC_AUTH_PASS  preprod: the site's HTTP protection
#   WP_USER, WP_PASS                  preprod: the Editor account arcadia-check
#                                     (login password: an application password
#                                     cannot pass the HTTP protection, both use
#                                     the Authorization header)
#   prod: no WordPress account, by decision. Its key will be the dedicated
#   read secret of the plugin's read-only content route (checklist Phase 54).
#
# A site in the list without its file, or with a missing key, is reported in
# plain words. Exit 1 when a site answers another version than the conf says,
# is unreachable, or a preprod lacks its access.
#
# Usage: ./check-sites.sh            → every site
#        ./check-sites.sh <host>     → that site only
#
set -uo pipefail

CONF="test/upgrade/deployed-versions.conf"
SECRETS_DIR="${ARCADIA_WP_SECRETS:-$HOME/Developer/secrets/wp-sites}"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'
ok() { echo -e "  ${GREEN}✓${NC} $1"; }
warn() { echo -e "  ${YELLOW}!${NC} $1"; }
bad() {
	echo -e "  ${RED}✗ $1${NC}"
	FAILED=1
}

cd "$(dirname "$0")"
FAILED=0
ONLY="${1:-}"
SCRATCH=$(mktemp -d)
trap 'rm -rf "$SCRATCH"' EXIT

# Value of KEY in a secrets file, without sourcing it (values hold ! ^ spaces).
secret() { [ -f "$2" ] && sed -n "s/^$1=//p" "$2" | head -1; }

while read -r host version env; do
	[ -n "$ONLY" ] && [ "$host" != "$ONLY" ] && continue
	echo "${host}  (${env}, ${version} attendue)"
	file="${SECRETS_DIR}/${host}.env"
	curl_auth=()

	# ─── Access secrets ──────────────────────────────────────────────────
	if [ "$env" = "preprod" ]; then
		if [ ! -f "$file" ]; then
			bad "pas de fichier d'accès ${file}"
		else
			missing=""
			for k in BASIC_AUTH_USER BASIC_AUTH_PASS WP_USER WP_PASS; do
				[ -n "$(secret "$k" "$file")" ] || missing="${missing} ${k}"
			done
			[ -z "$missing" ] && ok "fichier d'accès complet" || bad "clés manquantes dans ${file} :${missing}"
			bu=$(secret BASIC_AUTH_USER "$file")
			[ -n "$bu" ] && curl_auth=(-u "${bu}:$(secret BASIC_AUTH_PASS "$file")")
		fi
	else
		[ -f "$file" ] && ok "fichier d'accès présent" ||
			warn "pas de fichier d'accès (normal tant que la route de lecture n'existe pas, Phase 54)"
	fi

	# ─── Live version ────────────────────────────────────────────────────
	# ?rest_route= with a cache-buster: o2switch hosts cache /wp-json/.
	body=$(curl -sS --max-time 20 "${curl_auth[@]}" "https://${host}/?rest_route=/arcadia/v1/health&t=$(date +%s)" 2>&1)
	live=$(echo "$body" | sed -n 's/.*"version":"\([^"]*\)".*/\1/p')
	if [ -z "$live" ]; then
		bad "/health illisible : $(echo "$body" | tr '\n' ' ' | cut -c1-80)"
	elif [ "$live" = "$version" ]; then
		ok "/health ${live}"
	else
		bad "/health ${live}, la conf dit ${version} — mettre la conf à jour (ou le site)"
	fi

	# ─── WordPress account (preprod) ─────────────────────────────────────
	wu=$(secret WP_USER "$file")
	if [ "$env" = "preprod" ] && [ -n "$wu" ]; then
		jar="${SCRATCH}/${host}.jar"
		curl -sS --max-time 30 "${curl_auth[@]}" -c "$jar" -o /dev/null "https://${host}/wp-login.php"
		curl -sS --max-time 30 "${curl_auth[@]}" -b "$jar" -c "$jar" -o /dev/null \
			-b 'wordpress_test_cookie=WP+Cookie+check' \
			--data-urlencode "log=${wu}" --data-urlencode "pwd=$(secret WP_PASS "$file")" \
			-d 'testcookie=1' "https://${host}/wp-login.php"
		nonce=$(curl -sS --max-time 20 "${curl_auth[@]}" -b "$jar" "https://${host}/wp-admin/admin-ajax.php?action=rest-nonce")
		acf=$(curl -sS --max-time 30 "${curl_auth[@]}" -b "$jar" -H "X-WP-Nonce: ${nonce}" \
			"https://${host}/?rest_route=/wp/v2/block-types&per_page=100" |
			grep -oE '"name":"acf(\\)?/' | wc -l | tr -d ' ') # WP escapes / as \/ in JSON
		if [ "${acf:-0}" -gt 0 ]; then
			ok "connexion ${wu} OK, ${acf} blocs ACF enregistrés"
		else
			bad "connexion ${wu} refusée, ou aucun bloc ACF (mot de passe changé ? ACF Pro désactivé ?)"
		fi
	fi
done < <(grep -vE '^[[:space:]]*(#|$)' "$CONF")

if [ "$FAILED" = 0 ]; then
	echo -e "${GREEN}Tous les sites sont conformes.${NC}"
else
	echo -e "${RED}Au moins un site demande une action (voir ✗ ci-dessus).${NC}"
fi
exit "$FAILED"
