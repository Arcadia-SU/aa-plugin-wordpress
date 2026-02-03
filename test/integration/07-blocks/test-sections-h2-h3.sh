#!/bin/bash
# Test: Section headings (h2, h3)

generate_jwt "posts:write"

body='{
    "title": "Sections Test",
    "status": "draft",
    "content": {
        "blocks": [
            {"type": "heading", "level": 2, "content": "Main Section"},
            {"type": "paragraph", "content": "Section content."},
            {"type": "heading", "level": 3, "content": "Subsection"},
            {"type": "paragraph", "content": "Subsection content."}
        ]
    }
}'
response=$(api_post "/posts" "$body")
status=$(get_status)

assert_status "201" "$status" "Create post with headings returns 201"

content=$(echo "$response" | jq -r '.content // empty')
assert_contains "Main Section" "$content" "Contains h2 heading text"
assert_contains "Subsection" "$content" "Contains h3 heading text"

post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi
