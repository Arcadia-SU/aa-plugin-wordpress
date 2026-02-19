#!/bin/bash
# Test: Ordered list block

generate_jwt "articles:write"

body='{
    "title": "Ordered List Test",
    "status": "draft",
    "content": {
        "blocks": [
            {
                "type": "list",
                "ordered": true,
                "items": ["First item", "Second item", "Third item"]
            }
        ]
    }
}'
response=$(api_post "/articles" "$body")
status=$(get_status)

assert_status "201" "$status" "Create post with ordered list returns 201"

content=$(echo "$response" | jq -r '.content // empty')
assert_contains "<ol>" "$content" "Contains ordered list tag"
assert_contains "<li>" "$content" "Contains list items"

post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi
