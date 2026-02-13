#!/usr/bin/env bash
# Main test runner for Arcadia Agents integration tests

# Requires bash 4+ for associative arrays
if [[ "${BASH_VERSINFO[0]}" -lt 4 ]]; then
    echo "Error: This script requires bash 4.0 or higher"
    echo "Current version: $BASH_VERSION"
    echo "On macOS, install with: brew install bash"
    exit 1
fi

set -e

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Load config
if [[ -f "$SCRIPT_DIR/config.sh" ]]; then
    source "$SCRIPT_DIR/config.sh"
else
    echo "Error: config.sh not found. Copy config.example.sh to config.sh and configure."
    exit 1
fi

# Set paths
export RESULTS_DIR="$SCRIPT_DIR/results"
export FIXTURES_DIR="$SCRIPT_DIR/fixtures"
export LIB_DIR="$SCRIPT_DIR/lib"

# Load libraries
source "$LIB_DIR/assertions.sh"
source "$LIB_DIR/api-client.sh"
source "$LIB_DIR/setup-teardown.sh"

# Parse arguments
SUITE=""
STOP_ON_FAIL=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --suite=*)
            SUITE="${1#*=}"
            shift
            ;;
        --verbose)
            VERBOSE=true
            export VERBOSE
            shift
            ;;
        --stop-on-fail)
            STOP_ON_FAIL=true
            shift
            ;;
        --help)
            echo "Usage: $0 [options]"
            echo ""
            echo "Options:"
            echo "  --suite=NAME     Run only specific test suite (auth, scopes, posts, media, etc.)"
            echo "  --verbose        Show detailed test output"
            echo "  --stop-on-fail   Stop on first failure"
            echo "  --help           Show this help"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

# Map suite names to directories
declare -A SUITE_DIRS=(
    ["auth"]="01-auth"
    ["scopes"]="02-scopes"
    ["posts"]="03-posts"
    ["media"]="04-media"
    ["taxonomies"]="05-taxonomies"
    ["pages"]="06-pages"
    ["blocks"]="07-blocks"
    ["site-info"]="08-site-info"
    ["blocks-custom"]="09-blocks-custom"
)

# Header
echo "========================================"
echo "  Arcadia Agents Integration Tests"
echo "========================================"
echo "Target: $API_BASE"
echo ""

# Setup
setup_tests

# Check connectivity
echo -n "Checking API connectivity... "
HEALTH_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$WP_URL/wp-json/arcadia/v1/health" 2>/dev/null || echo "000")
if [[ "$HEALTH_RESPONSE" == "200" ]]; then
    echo "OK"
else
    echo "FAILED (HTTP $HEALTH_RESPONSE)"
    echo "Make sure WordPress is running and the plugin is activated."
    exit 1
fi

# Find test files to run
if [[ -n "$SUITE" ]]; then
    if [[ -n "${SUITE_DIRS[$SUITE]}" ]]; then
        TEST_DIRS=("$SCRIPT_DIR/integration/${SUITE_DIRS[$SUITE]}")
    else
        echo "Unknown suite: $SUITE"
        echo "Available suites: ${!SUITE_DIRS[*]}"
        exit 1
    fi
else
    # Run all suites in order
    TEST_DIRS=()
    for dir in "$SCRIPT_DIR"/integration/*/; do
        TEST_DIRS+=("$dir")
    done
fi

# Run tests
TOTAL_PASSED=0
TOTAL_FAILED=0

for test_dir in "${TEST_DIRS[@]}"; do
    if [[ ! -d "$test_dir" ]]; then
        continue
    fi

    suite_name=$(basename "$test_dir")
    if [[ "$VERBOSE" != "true" ]]; then
        echo -n "Suite $suite_name: "
    else
        echo ""
        echo "========================================"
        echo "  Suite: $suite_name"
        echo "========================================"
    fi

    # Reset counters for this suite
    TESTS_PASSED=0
    TESTS_FAILED=0

    # Run each test file in the suite
    for test_file in "$test_dir"/*.sh; do
        if [[ ! -f "$test_file" ]]; then
            continue
        fi

        before_test_file "$test_file"

        # Source and run the test file
        (
            source "$test_file"
        )

        after_test_file

        # Check for stop-on-fail
        if [[ $STOP_ON_FAIL == true && $TESTS_FAILED -gt 0 ]]; then
            echo ""
            echo "Stopping on first failure."
            break 2
        fi
    done

    # Update totals
    TOTAL_PASSED=$((TOTAL_PASSED + TESTS_PASSED))
    TOTAL_FAILED=$((TOTAL_FAILED + TESTS_FAILED))

    if [[ "$VERBOSE" != "true" ]]; then
        echo " (${TESTS_PASSED} passed, ${TESTS_FAILED} failed)"
    fi
done

# Final summary
echo ""
echo "========================================"
echo "  Final Results"
echo "========================================"
echo -e "Passed: ${GREEN}$TOTAL_PASSED${NC}"
echo -e "Failed: ${RED}$TOTAL_FAILED${NC}"
echo "Total:  $((TOTAL_PASSED + TOTAL_FAILED))"
echo "========================================"

# Show failures if any
if [[ $TOTAL_FAILED -gt 0 && -f "$RESULTS_DIR/failures.log" ]]; then
    echo ""
    echo "Failures:"
    cat "$RESULTS_DIR/failures.log"
fi

# Exit with appropriate code
if [[ $TOTAL_FAILED -gt 0 ]]; then
    exit 1
fi
exit 0
