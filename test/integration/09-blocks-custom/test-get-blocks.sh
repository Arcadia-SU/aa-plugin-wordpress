#!/bin/bash
# Test: GET /blocks endpoint returns block discovery data

generate_jwt "site:read"

response=$(api_get "/blocks")
status=$(get_status)

assert_status "200" "$status" "Get blocks returns 200"

# Check top-level structure
assert_json_exists "$response" ".adapter" "Response contains adapter name"
assert_json_exists "$response" ".blocks" "Response contains blocks object"
assert_json_exists "$response" ".blocks.builtin" "Response contains builtin blocks"
assert_json_exists "$response" ".blocks.custom" "Response contains custom blocks"

# Check builtin blocks contain the 4 MVP types
builtin_count=$(echo "$response" | jq '.blocks.builtin | length')
assert_equals "4" "$builtin_count" "Builtin blocks has 4 entries"

# Check that each builtin has type and description
for type in paragraph heading image list; do
    found=$(echo "$response" | jq -r ".blocks.builtin[] | select(.type == \"$type\") | .type")
    assert_equals "$type" "$found" "Builtin blocks contains $type"
done
