#!/bin/bash
# Test: Update existing post

generate_jwt "posts:write"

# Create post first
body='{"title":"Update Test Post","status":"draft","content":{"blocks":[]}}'
response=$(api_post "/posts" "$body")
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
CREATED_POSTS+=("$post_id")

# Update post
update_body='{"title":"Updated Title","status":"draft"}'
response=$(api_put "/posts/$post_id" "$update_body")
status=$(get_status)

assert_status "200" "$status" "Update post returns 200"
assert_json_field "$response" ".title" "Updated Title" "Title was updated"
