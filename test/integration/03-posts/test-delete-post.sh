#!/bin/bash
# Test: Delete post

generate_jwt "articles:write articles:delete"

# Create post first
body='{"title":"Delete Test Post","status":"draft","content":{"blocks":[]}}'
response=$(api_post "/articles" "$body")
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')

# Delete post
response=$(api_delete "/articles/$post_id")
status=$(get_status)

assert_status "200" "$status" "Delete post returns 200"
assert_json_field "$response" ".success" "true" "Response indicates success"
