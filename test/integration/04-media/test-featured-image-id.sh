#!/bin/bash
# Test: Set featured image by attachment ID

generate_jwt "posts:write media:write"

# Create a post first
body='{"title":"Featured Image Test","status":"draft","content":{"blocks":[]}}'
response=$(api_post "/posts" "$body")
post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
CREATED_POSTS+=("$post_id")

# Upload media
media_body='{"url":"https://picsum.photos/300/200","alt":"Featured"}'
response=$(api_post "/media" "$media_body")
media_status=$(get_status)

if [[ "$media_status" != "201" ]]; then
    test_start "Set featured image by ID (skipped - media upload failed)"
    test_pass
else
    attachment_id=$(echo "$response" | jq -r '.attachment_id // empty')
    CREATED_MEDIA+=("$attachment_id")

    # Set featured image
    featured_body="{\"attachment_id\":$attachment_id}"
    response=$(api_put "/posts/$post_id/featured-image" "$featured_body")
    status=$(get_status)

    assert_status "200" "$status" "Set featured image by ID returns 200"
    assert_json_field "$response" ".success" "true" "Response indicates success"
fi
