#!/bin/bash
# Test: List pages

generate_jwt "site:read"

response=$(api_get "/pages")
status=$(get_status)

assert_status "200" "$status" "List pages returns 200"
assert_json_exists "$response" ".pages" "Response contains pages array"
assert_json_exists "$response" ".total" "Response contains total"
assert_json_exists "$response" ".total_pages" "Response contains total_pages"

# Test filters
response=$(api_get "/pages?per_page=5")
status=$(get_status)
assert_status "200" "$status" "List pages with per_page returns 200"

response=$(api_get "/pages?status=publish")
status=$(get_status)
assert_status "200" "$status" "List pages with status filter returns 200"
