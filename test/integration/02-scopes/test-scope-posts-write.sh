#!/bin/bash
# Test: posts:write scope

# With correct scope
generate_jwt "posts:write"
body='{"title":"Scope Test Post","status":"draft","content":{"blocks":[]}}'
response=$(api_post "/posts" "$body")
status=$(get_status)
assert_status "201" "$status" "posts:write scope allows POST /posts"

# Track for cleanup
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi

# Without scope
generate_jwt "posts:read"
response=$(api_post "/posts" "$body")
status=$(get_status)
assert_status "403" "$status" "Without posts:write scope, POST /posts returns 403"
