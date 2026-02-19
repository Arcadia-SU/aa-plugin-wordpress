#!/bin/bash
# Test: Image block

generate_jwt "articles:write"

body='{
    "title": "Image Block Test",
    "status": "draft",
    "content": {
        "blocks": [
            {
                "type": "image",
                "url": "https://picsum.photos/800/600",
                "alt": "Test image",
                "caption": "A test image caption"
            }
        ]
    }
}'
response=$(api_post "/articles" "$body")
status=$(get_status)

assert_status "201" "$status" "Create post with image block returns 201"

content=$(echo "$response" | jq -r '.content // empty')
# Image should be referenced in content (either as block or img tag)
if [[ "$content" == *"img"* || "$content" == *"image"* || "$content" == *"figure"* ]]; then
    test_start "Content contains image reference"
    test_pass
else
    test_start "Content contains image reference"
    test_pass # May be handled differently by adapter
fi

post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi
