#!/usr/bin/env bash
#
# Regenerate the translation template (.pot) and compile .mo files.
#
# Uses the wordpress:cli image (no wp-cli in the dev container — same
# precedent as test/upgrade/docker-compose.upgrade.yml). Run from the
# plugin root (arcadia-agents/):
#
#   ./bin/make-pot.sh          # extract strings -> languages/arcadia-agents.pot
#   ./bin/make-pot.sh --mo     # also compile every languages/*.po -> .mo
#
set -euo pipefail

cd "$(dirname "$0")/.."

echo "== wp i18n make-pot =="
docker run --rm -v "$PWD":/plugin -w /plugin wordpress:cli wp i18n make-pot . languages/arcadia-agents.pot \
	--slug=arcadia-agents \
	--domain=arcadia-agents \
	--exclude=vendor,tests,test,bin,dist \
	--allow-root

if [[ "${1:-}" == "--mo" ]]; then
	echo "== wp i18n make-mo =="
	docker run --rm -v "$PWD":/plugin -w /plugin wordpress:cli wp i18n make-mo languages/ --allow-root
fi

echo "OK"
