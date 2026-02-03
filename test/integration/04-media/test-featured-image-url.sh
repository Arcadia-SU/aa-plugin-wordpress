#!/bin/bash
# Test: Set featured image by URL

generate_jwt "posts:write media:write"

# Create a post first
body='{"title":"Featured Image URL Test","status":"draft","content":{"blocks":[]}}'
response=$(api_post "/posts" "$body")
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
CREATED_POSTS+=("$post_id")

# Set featured image by URL
featured_body='{"url":"https://picsum.photos/400/300"}'
response=$(api_put "/posts/$post_id/featured-image" "$featured_body")
status=$(get_status)

if [[ "$status" == "200" ]]; then
    test_start "Set featured image by URL returns 200"
    test_pass
    assert_json_field "$response" ".success" "true" "Response indicates success"
    assert_json_exists "$response" ".attachment_id" "Response contains attachment_id"
else
    test_start "Set featured image by URL"
    test_fail "Expected 200, got $status (URL may be unavailable)"
fi
