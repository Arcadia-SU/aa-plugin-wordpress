#!/bin/bash
# Test: media:read scope

# With correct scope
generate_jwt "media:read"
response=$(api_get "/media")
status=$(get_status)
assert_status "200" "$status" "media:read scope allows GET /media"

# Without scope
generate_jwt "posts:read"
response=$(api_get "/media")
status=$(get_status)
assert_status "403" "$status" "Without media:read scope, GET /media returns 403"
