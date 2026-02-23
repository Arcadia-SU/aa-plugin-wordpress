#!/usr/bin/env bash
#
# Build script for arcadia-agents WordPress plugin.
#
# Runs validation checks before creating the distributable zip.
# If any check fails, the zip is NOT created.
#
# Usage: ./build.sh
#
set -euo pipefail

# ─── Configuration ──────────────────────────────────────────────────────────

PLUGIN_DIR="arcadia-agents"
ZIP_NAME="arcadia-agents.zip"
CONTAINER_PLUGIN_PATH="/var/www/html/wp-content/plugins/arcadia-agents"
MAX_ZIP_SIZE_KB=500

# ─── Colors ─────────────────────────────────────────────────────────────────

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# ─── Helpers ────────────────────────────────────────────────────────────────

step=0
pass() { echo -e "  ${GREEN}✓${NC} $1"; }
fail() { echo -e "  ${RED}✗${NC} $1"; echo -e "\n${RED}BUILD ABORTED${NC}"; exit 1; }
warn() { echo -e "  ${YELLOW}⚠${NC} $1"; }
info() { echo -e "  ${BLUE}→${NC} $1"; }

check() {
	step=$((step + 1))
	echo -e "\n${BLUE}[$step]${NC} $1"
}

docker_exec() {
	docker compose exec -T wordpress bash -c "cd ${CONTAINER_PLUGIN_PATH} && $1"
}

# ─── Trap: always restore dev dependencies ──────────────────────────────────

cleanup() {
	echo -e "\n${BLUE}[cleanup]${NC} Restoring dev dependencies..."
	if docker compose exec -T wordpress php -v &>/dev/null; then
		docker_exec "composer install --quiet 2>/dev/null" && pass "Dev dependencies restored." || warn "Failed to restore dev deps. Run manually: docker compose exec wordpress bash -c 'cd ${CONTAINER_PLUGIN_PATH} && composer install'"
	else
		warn "Docker not available for cleanup. Run manually: docker compose exec wordpress bash -c 'cd ${CONTAINER_PLUGIN_PATH} && composer install'"
	fi
}
trap cleanup EXIT

# ─── Start ──────────────────────────────────────────────────────────────────

echo -e "${BLUE}━━━ Arcadia Agents Build ━━━${NC}"

# ─── 1. Docker running ─────────────────────────────────────────────────────

check "Docker running"
if docker compose exec -T wordpress php -v &>/dev/null; then
	pass "WordPress container is running."
else
	fail "WordPress container is not running. Run ./start.sh first."
fi

# ─── 2. PHPUnit tests ──────────────────────────────────────────────────────

check "PHPUnit tests"
if docker_exec "./vendor/bin/phpunit --testdox"; then
	pass "All tests passed."
else
	fail "PHPUnit tests failed. Fix tests before building."
fi

# ─── 3. Composer install --no-dev ───────────────────────────────────────────

check "Composer install --no-dev"
if docker_exec "composer install --no-dev --quiet"; then
	pass "Production dependencies installed."
else
	fail "composer install --no-dev failed."
fi

# ─── 4. PHP lint all files ──────────────────────────────────────────────────

check "PHP lint"
lint_errors=0
while IFS= read -r file; do
	if ! docker compose exec -T wordpress php -l "${CONTAINER_PLUGIN_PATH}/${file}" &>/dev/null; then
		warn "Syntax error: ${file}"
		lint_errors=$((lint_errors + 1))
	fi
done < <(find "${PLUGIN_DIR}" -name "*.php" -not -path "*/vendor/*" -not -path "*/tests/*" -not -path "*/test/*" | sed "s|^${PLUGIN_DIR}/||")

if [ "$lint_errors" -gt 0 ]; then
	fail "${lint_errors} PHP file(s) have syntax errors."
else
	pass "All PHP files pass lint."
fi

# ─── 5. Autoloader audit ───────────────────────────────────────────────────

check "Autoloader audit"
autoload_files="${PLUGIN_DIR}/vendor/composer/autoload_files.php"
if [ -f "$autoload_files" ]; then
	if grep -qi "phpunit\|myclabs\|deep-copy" "$autoload_files"; then
		fail "autoload_files.php references dev dependencies (phpunit/myclabs). Composer --no-dev did not clean properly."
	else
		pass "autoload_files.php exists but contains no dev references."
	fi
else
	pass "No autoload_files.php (expected for --no-dev build)."
fi

# ─── 6. Vendor completeness ────────────────────────────────────────────────

check "Vendor completeness"
if [ -d "${PLUGIN_DIR}/vendor/firebase/php-jwt" ]; then
	pass "firebase/php-jwt is present."
else
	fail "firebase/php-jwt is missing from vendor/."
fi

# ─── 7. Boot test ──────────────────────────────────────────────────────────

check "Boot test (autoloader loads without error)"
if docker_exec "php -r \"require 'vendor/autoload.php';\""; then
	pass "Autoloader boots successfully."
else
	fail "Autoloader fails to boot. A required class/file is missing."
fi

# ─── 8. Create zip ─────────────────────────────────────────────────────────

check "Create zip"
rm -f "$ZIP_NAME"
if zip -r "$ZIP_NAME" "$PLUGIN_DIR/" \
	-x "${PLUGIN_DIR}/tests/*" \
	-x "${PLUGIN_DIR}/test/*" \
	-x "${PLUGIN_DIR}/.phpunit*" \
	-x "${PLUGIN_DIR}/phpunit.xml" \
	-x "${PLUGIN_DIR}/composer.json" \
	-x "${PLUGIN_DIR}/composer.lock" \
	-x "${PLUGIN_DIR}/.git/*" \
	-x "${PLUGIN_DIR}/.env" \
	-x "${PLUGIN_DIR}/CLAUDE.md" \
	> /dev/null; then
	pass "Zip created: ${ZIP_NAME}"
else
	fail "Failed to create zip."
fi

# ─── 9. Zip content audit ──────────────────────────────────────────────────

check "Zip content audit"
zip_issues=0

if unzip -l "$ZIP_NAME" | grep -qi "phpunit"; then
	warn "Zip contains phpunit references."
	zip_issues=$((zip_issues + 1))
fi

if unzip -l "$ZIP_NAME" | grep -qi "myclabs"; then
	warn "Zip contains myclabs references."
	zip_issues=$((zip_issues + 1))
fi

if unzip -l "$ZIP_NAME" | grep -q "composer\.json"; then
	warn "Zip contains composer.json."
	zip_issues=$((zip_issues + 1))
fi

if unzip -l "$ZIP_NAME" | grep -q "/tests/"; then
	warn "Zip contains tests/ directory."
	zip_issues=$((zip_issues + 1))
fi

if unzip -l "$ZIP_NAME" | grep -q "\.git/"; then
	warn "Zip contains .git/ directory."
	zip_issues=$((zip_issues + 1))
fi

if unzip -l "$ZIP_NAME" | grep -q "\.env"; then
	warn "Zip contains .env file."
	zip_issues=$((zip_issues + 1))
fi

if unzip -l "$ZIP_NAME" | grep -q "CLAUDE\.md"; then
	warn "Zip contains CLAUDE.md."
	zip_issues=$((zip_issues + 1))
fi

if [ "$zip_issues" -gt 0 ]; then
	rm -f "$ZIP_NAME"
	fail "Zip contains ${zip_issues} forbidden item(s). Zip deleted."
else
	pass "Zip content is clean."
fi

# ─── 10. Zip size ──────────────────────────────────────────────────────────

check "Zip size"
zip_size_kb=$(du -k "$ZIP_NAME" | cut -f1)
if [ "$zip_size_kb" -gt "$MAX_ZIP_SIZE_KB" ]; then
	warn "Zip is ${zip_size_kb}KB (threshold: ${MAX_ZIP_SIZE_KB}KB). Check for unnecessary files."
else
	pass "Zip is ${zip_size_kb}KB (< ${MAX_ZIP_SIZE_KB}KB)."
fi

# ─── Done ───────────────────────────────────────────────────────────────────

echo -e "\n${GREEN}━━━ BUILD SUCCESSFUL ━━━${NC}"
echo -e "  Output: ${ZIP_NAME} (${zip_size_kb}KB)"
echo -e "  Ready to deploy.\n"
