#!/bin/bash
# Test: Create post with minimal data

generate_jwt "articles:write"

body='{"title":"Minimal Test Post","status":"draft","content":{"blocks":[]}}'
response=$(api_post "/articles" "$body")
status=$(get_status)

assert_status "201" "$status" "Create minimal post returns 201"
assert_json_exists "$response" ".id" "Response contains post id"
assert_json_field "$response" ".title" "Minimal Test Post" "Title matches"
assert_json_field "$response" ".status" "draft" "Status is draft"

# Track for cleanup
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi
