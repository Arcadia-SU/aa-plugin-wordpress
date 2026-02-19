#!/bin/bash
# Test: articles:write scope

# With correct scope
generate_jwt "articles:write"
body='{"title":"Scope Test Post","status":"draft","content":{"blocks":[]}}'
response=$(api_post "/articles" "$body")
status=$(get_status)
assert_status "201" "$status" "articles:write scope allows POST /articles"

# Track for cleanup
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi

# Without scope
generate_jwt "articles:read"
response=$(api_post "/articles" "$body")
status=$(get_status)
assert_status "403" "$status" "Without articles:write scope, POST /articles returns 403"
