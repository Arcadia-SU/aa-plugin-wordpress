#!/bin/bash
# Test: Create post with blocks content

generate_jwt "articles:write"

body=$(load_fixture "posts/complex-blocks.json")
response=$(api_post "/articles" "$body")
status=$(get_status)

assert_status "201" "$status" "Create post with blocks returns 201"
assert_json_exists "$response" ".id" "Response contains post id"
assert_json_exists "$response" ".content" "Response contains content"

# Track for cleanup
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi
