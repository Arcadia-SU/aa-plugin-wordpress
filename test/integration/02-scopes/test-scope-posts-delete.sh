#!/bin/bash
# Test: articles:delete scope

# Create a post first
generate_jwt "articles:write articles:delete"
body='{"title":"Delete Test Post","status":"draft","content":{"blocks":[]}}'
response=$(api_post "/articles" "$body")
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')

# With correct scope
response=$(api_delete "/articles/$post_id")
status=$(get_status)
assert_status "200" "$status" "articles:delete scope allows DELETE /articles/{id}"

# Create another post
response=$(api_post "/articles" "$body")
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')

# Without scope
generate_jwt "articles:read articles:write"
response=$(api_delete "/articles/$post_id")
status=$(get_status)
assert_status "403" "$status" "Without articles:delete scope, DELETE /articles returns 403"

# Cleanup
generate_jwt "articles:delete"
api_delete "/articles/$post_id" > /dev/null 2>&1
