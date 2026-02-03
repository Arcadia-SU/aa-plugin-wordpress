#!/bin/bash
# Test: Site info endpoint

generate_jwt "site:read"

response=$(api_get "/site-info")
status=$(get_status)

assert_status "200" "$status" "Get site info returns 200"

# Check required fields
assert_json_exists "$response" ".name" "Response contains site name"
assert_json_exists "$response" ".url" "Response contains site URL"
assert_json_exists "$response" ".home" "Response contains home URL"
assert_json_exists "$response" ".language" "Response contains language"
assert_json_exists "$response" ".timezone" "Response contains timezone"
assert_json_exists "$response" ".wordpress" "Response contains wordpress info"
assert_json_exists "$response" ".wordpress.version" "Response contains WP version"
assert_json_exists "$response" ".plugin" "Response contains plugin info"
assert_json_exists "$response" ".plugin.version" "Response contains plugin version"
assert_json_exists "$response" ".theme" "Response contains theme info"
assert_json_exists "$response" ".theme.name" "Response contains theme name"
