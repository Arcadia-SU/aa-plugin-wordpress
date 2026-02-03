#!/bin/bash
# Test: Create and publish post

generate_jwt "posts:write"

body='{"title":"Published Test Post","status":"publish","content":{"blocks":[{"type":"paragraph","content":"Published content"}]}}'
response=$(api_post "/posts" "$body")
status=$(get_status)

assert_status "201" "$status" "Create published post returns 201"
assert_json_field "$response" ".status" "publish" "Status is publish"
assert_json_exists "$response" ".url" "Response contains URL"

# Track for cleanup
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi
