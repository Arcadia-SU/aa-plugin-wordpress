#!/bin/bash
# Test: List posts

generate_jwt "articles:read articles:write"

# Create a test post first
body='{"title":"List Test Post","status":"draft","content":{"blocks":[]}}'
api_post "/articles" "$body" > /dev/null
post_id=$(echo "$LAST_BODY" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi

# List articles
response=$(api_get "/articles")
status=$(get_status)

assert_status "200" "$status" "List articles returns 200"
assert_json_exists "$response" ".posts" "Response contains posts array"
assert_json_exists "$response" ".total" "Response contains total count"
assert_json_exists "$response" ".total_pages" "Response contains total_pages"
