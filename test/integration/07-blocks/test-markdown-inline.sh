#!/bin/bash
# Test: Markdown inline formatting

generate_jwt "articles:write"

body='{
    "title": "Markdown Inline Test",
    "status": "draft",
    "content": {
        "blocks": [
            {"type": "paragraph", "content": "Text with **bold** and *italic* and `code`."},
            {"type": "paragraph", "content": "A [link](https://example.com) here."}
        ]
    }
}'
response=$(api_post "/articles" "$body")
status=$(get_status)

assert_status "201" "$status" "Create post with markdown inline returns 201"
assert_json_exists "$response" ".content" "Response contains content"

# Check for HTML rendering
content=$(echo "$response" | jq -r '.content // empty')
assert_contains "<strong>bold</strong>" "$content" "Bold rendered as strong tag"
assert_contains "<em>italic</em>" "$content" "Italic rendered as em tag"
assert_contains "<code>code</code>" "$content" "Code rendered as code tag"

post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi
