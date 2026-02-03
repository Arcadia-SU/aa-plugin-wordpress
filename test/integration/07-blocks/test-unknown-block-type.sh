#!/bin/bash
# Test: Unknown block type handling

generate_jwt "posts:write"

body='{
    "title": "Unknown Block Test",
    "status": "draft",
    "content": {
        "blocks": [
            {"type": "paragraph", "content": "Normal paragraph."},
            {"type": "unknown_widget_xyz", "data": "some data"},
            {"type": "paragraph", "content": "Another paragraph."}
        ]
    }
}'
response=$(api_post "/posts" "$body")
status=$(get_status)

# Should succeed - unknown blocks should be skipped or handled gracefully
assert_status "201" "$status" "Create post with unknown block returns 201"
assert_contains "Normal paragraph" "$response" "Known blocks still processed"

post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi
