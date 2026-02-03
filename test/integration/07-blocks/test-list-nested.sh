#!/bin/bash
# Test: Nested list block

generate_jwt "posts:write"

body='{
    "title": "Nested List Test",
    "status": "draft",
    "content": {
        "blocks": [
            {
                "type": "list",
                "ordered": false,
                "items": [
                    "Parent item 1",
                    {
                        "text": "Parent item 2",
                        "children": ["Child 2.1", "Child 2.2"]
                    },
                    "Parent item 3"
                ]
            }
        ]
    }
}'
response=$(api_post "/posts" "$body")
status=$(get_status)

assert_status "201" "$status" "Create post with nested list returns 201"

content=$(echo "$response" | jq -r '.content // empty')
assert_contains "<ul>" "$content" "Contains unordered list tag"

post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi
