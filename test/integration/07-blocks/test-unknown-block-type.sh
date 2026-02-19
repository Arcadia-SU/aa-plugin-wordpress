#!/bin/bash
# Test: Unknown block type handling (fail fast with 422)

generate_jwt "articles:write"

body='{
    "title": "Unknown Block Test",
    "status": "draft",
    "h1": "Test Article",
    "children": [
        {"type": "paragraph", "content": "Normal paragraph."},
        {"type": "unknown_widget_xyz", "properties": {"data": "some data"}},
        {"type": "paragraph", "content": "Another paragraph."}
    ]
}'
response=$(api_post "/articles" "$body")
status=$(get_status)

# Should fail with 422 - unknown block type rejected
assert_status "422" "$status" "Create post with unknown block returns 422"
assert_contains "unknown_block_type" "$response" "Error code is unknown_block_type"
