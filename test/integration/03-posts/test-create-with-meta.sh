#!/bin/bash
# Test: Create post with meta fields

generate_jwt "posts:write"

body=$(load_fixture "posts/with-meta.json")
response=$(api_post "/posts" "$body")
status=$(get_status)

assert_status "201" "$status" "Create post with meta returns 201"
assert_json_exists "$response" ".id" "Response contains post id"

# Track for cleanup
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi
