#!/bin/bash
# Test: articles:read scope

# With correct scope
generate_jwt "articles:read"
response=$(api_get "/articles")
status=$(get_status)
assert_status "200" "$status" "articles:read scope allows GET /articles"

# Without scope
generate_jwt "media:read"
response=$(api_get "/articles")
status=$(get_status)
assert_status "403" "$status" "Without articles:read scope, GET /articles returns 403"
