#!/bin/bash
# Test assertion functions

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Counters
TESTS_PASSED=0
TESTS_FAILED=0
CURRENT_TEST=""

# Start a test
test_start() {
    CURRENT_TEST="$1"
    if [[ "$VERBOSE" == "true" ]]; then
        echo -n "  Testing: $CURRENT_TEST ... "
    fi
}

# Pass a test
test_pass() {
    ((TESTS_PASSED++))
    if [[ "$VERBOSE" == "true" ]]; then
        echo -e "${GREEN}PASS${NC}"
    else
        echo -n "."
    fi
}

# Fail a test
test_fail() {
    local message="$1"
    ((TESTS_FAILED++))
    if [[ "$VERBOSE" == "true" ]]; then
        echo -e "${RED}FAIL${NC}"
        echo -e "    ${RED}$message${NC}"
    else
        echo -n "F"
    fi
    # Log failure
    echo "[FAIL] $CURRENT_TEST: $message" >> "$RESULTS_DIR/failures.log"
}

# Assert HTTP status code
# Usage: assert_status expected actual test_name
assert_status() {
    local expected="$1"
    local actual="$2"
    local test_name="$3"

    test_start "$test_name"

    if [[ "$actual" == "$expected" ]]; then
        test_pass
        return 0
    else
        test_fail "Expected status $expected, got $actual"
        return 1
    fi
}

# Assert string equality
# Usage: assert_equals expected actual test_name
assert_equals() {
    local expected="$1"
    local actual="$2"
    local test_name="$3"

    test_start "$test_name"

    if [[ "$actual" == "$expected" ]]; then
        test_pass
        return 0
    else
        test_fail "Expected '$expected', got '$actual'"
        return 1
    fi
}

# Assert string contains
# Usage: assert_contains needle haystack test_name
assert_contains() {
    local needle="$1"
    local haystack="$2"
    local test_name="$3"

    test_start "$test_name"

    if [[ "$haystack" == *"$needle"* ]]; then
        test_pass
        return 0
    else
        test_fail "Expected to contain '$needle'"
        return 1
    fi
}

# Assert JSON field equals value
# Usage: assert_json_field json field expected test_name
assert_json_field() {
    local json="$1"
    local field="$2"
    local expected="$3"
    local test_name="$4"

    test_start "$test_name"

    local actual
    actual=$(echo "$json" | jq -r "$field" 2>/dev/null)

    if [[ "$actual" == "$expected" ]]; then
        test_pass
        return 0
    else
        test_fail "Field $field: expected '$expected', got '$actual'"
        return 1
    fi
}

# Assert JSON field exists
# Usage: assert_json_exists json field test_name
assert_json_exists() {
    local json="$1"
    local field="$2"
    local test_name="$3"

    test_start "$test_name"

    local value
    value=$(echo "$json" | jq -e "$field" 2>/dev/null)

    if [[ $? -eq 0 && "$value" != "null" ]]; then
        test_pass
        return 0
    else
        test_fail "Field $field does not exist or is null"
        return 1
    fi
}

# Assert JSON field is array with length
# Usage: assert_json_array_length json field expected_length test_name
assert_json_array_length() {
    local json="$1"
    local field="$2"
    local expected="$3"
    local test_name="$4"

    test_start "$test_name"

    local actual
    actual=$(echo "$json" | jq "$field | length" 2>/dev/null)

    if [[ "$actual" == "$expected" ]]; then
        test_pass
        return 0
    else
        test_fail "Array $field: expected length $expected, got $actual"
        return 1
    fi
}

# Assert numeric greater than
# Usage: assert_greater_than expected actual test_name
assert_greater_than() {
    local threshold="$1"
    local actual="$2"
    local test_name="$3"

    test_start "$test_name"

    if (( actual > threshold )); then
        test_pass
        return 0
    else
        test_fail "Expected > $threshold, got $actual"
        return 1
    fi
}

# Assert not empty
# Usage: assert_not_empty value test_name
assert_not_empty() {
    local value="$1"
    local test_name="$2"

    test_start "$test_name"

    if [[ -n "$value" ]]; then
        test_pass
        return 0
    else
        test_fail "Expected non-empty value"
        return 1
    fi
}

# Print test summary
print_summary() {
    local total=$((TESTS_PASSED + TESTS_FAILED))
    echo ""
    echo "========================================"
    echo -e "Tests: ${GREEN}$TESTS_PASSED passed${NC}, ${RED}$TESTS_FAILED failed${NC}, $total total"
    echo "========================================"

    if [[ $TESTS_FAILED -gt 0 ]]; then
        return 1
    fi
    return 0
}
