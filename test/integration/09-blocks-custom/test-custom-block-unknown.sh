#!/bin/bash
# Test: POST /articles with unknown custom block type returns 422

generate_jwt "articles:write"

body='{
    "title": "Unknown Custom Block Test",
    "status": "draft",
    "h1": "Test Article",
    "children": [
        {"type": "paragraph", "content": "Normal paragraph."},
        {"type": "bloc-inconnu", "properties": {"foo": "bar"}},
        {"type": "paragraph", "content": "Another paragraph."}
    ]
}'
response=$(api_post "/articles" "$body")
status=$(get_status)

# Should fail with 422 - unknown block type
assert_status "422" "$status" "Create post with unknown block type returns 422"
assert_contains "unknown_block_type" "$response" "Error code is unknown_block_type"
assert_contains "bloc-inconnu" "$response" "Error mentions the unknown block type"
