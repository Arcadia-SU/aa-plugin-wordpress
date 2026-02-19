#!/bin/bash
# Test: taxonomies:read scope

# With correct scope
generate_jwt "taxonomies:read"
response=$(api_get "/categories")
status=$(get_status)
assert_status "200" "$status" "taxonomies:read scope allows GET /categories"

response=$(api_get "/tags")
status=$(get_status)
assert_status "200" "$status" "taxonomies:read scope allows GET /tags"

# Without scope
generate_jwt "articles:read"
response=$(api_get "/categories")
status=$(get_status)
assert_status "403" "$status" "Without taxonomies:read scope, GET /categories returns 403"
