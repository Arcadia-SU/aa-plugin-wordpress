#!/bin/bash
# Test: posts:read scope

# With correct scope
generate_jwt "posts:read"
response=$(api_get "/posts")
status=$(get_status)
assert_status "200" "$status" "posts:read scope allows GET /posts"

# Without scope
generate_jwt "media:read"
response=$(api_get "/posts")
status=$(get_status)
assert_status "403" "$status" "Without posts:read scope, GET /posts returns 403"
