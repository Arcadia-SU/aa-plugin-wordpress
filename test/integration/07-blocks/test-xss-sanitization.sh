#!/bin/bash
# Test: XSS sanitization

generate_jwt "articles:write"

body=$(load_fixture "posts/xss-attempt.json")
response=$(api_post "/articles" "$body")
status=$(get_status)

assert_status "201" "$status" "Create post with XSS attempt returns 201"

# Check that script tags are removed/escaped
content=$(echo "$response" | jq -r '.content // empty')

# Should NOT contain raw script tags
if [[ "$content" == *"<script>"* ]]; then
    test_start "Script tags are sanitized"
    test_fail "Content contains unescaped <script> tags"
else
    test_start "Script tags are sanitized"
    test_pass
fi

# Should NOT contain onclick handlers
if [[ "$content" == *"onclick"* ]]; then
    test_start "Event handlers are sanitized"
    test_fail "Content contains onclick handlers"
else
    test_start "Event handlers are sanitized"
    test_pass
fi

post_id=$(echo "$response" | jq -r '.id // .post_id // empty')
if [[ -n "$post_id" ]]; then
    CREATED_POSTS+=("$post_id")
fi
