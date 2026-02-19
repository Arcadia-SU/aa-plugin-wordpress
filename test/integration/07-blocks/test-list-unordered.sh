#!/bin/bash
# Test: Unordered list block

generate_jwt "articles:write"

body='{
    "title": "Unordered List Test",
    "status": "draft",
    "content": {
        "blocks": [
            {
                "type": "list",
                "ordered": false,
                "items": ["Apple", "Banana", "Cherry"]
            }
        ]
    }
}'
response=$(api_post "/articles" "$body")
status=$(get_status)

assert_status "201" "$status" "Create post with unordered list returns 201"

content=$(echo "$response" | jq -r '.content // empty')
assert_contains "<ul>" "$content" "Contains unordered list tag"
assert_contains "<li>" "$content" "Contains list items"

post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi
