#!/bin/bash
# Test: posts:delete scope

# Create a post first
generate_jwt "posts:write posts:delete"
body='{"title":"Delete Test Post","status":"draft","content":{"blocks":[]}}'
response=$(api_post "/posts" "$body")
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')

# With correct scope
response=$(api_delete "/posts/$post_id")
status=$(get_status)
assert_status "200" "$status" "posts:delete scope allows DELETE /posts/{id}"

# Create another post
response=$(api_post "/posts" "$body")
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')

# Without scope
generate_jwt "posts:read posts:write"
response=$(api_delete "/posts/$post_id")
status=$(get_status)
assert_status "403" "$status" "Without posts:delete scope, DELETE /posts returns 403"

# Cleanup
generate_jwt "posts:delete"
api_delete "/posts/$post_id" > /dev/null 2>&1
