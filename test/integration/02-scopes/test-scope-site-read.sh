#!/bin/bash
# Test: site:read scope

# With correct scope
generate_jwt "site:read"
response=$(api_get "/site-info")
status=$(get_status)
assert_status "200" "$status" "site:read scope allows GET /site-info"

response=$(api_get "/pages")
status=$(get_status)
assert_status "200" "$status" "site:read scope allows GET /pages"

# Without scope
generate_jwt "posts:read"
response=$(api_get "/site-info")
status=$(get_status)
assert_status "403" "$status" "Without site:read scope, GET /site-info returns 403"
