#!/bin/bash
# Test: Update non-existent post

generate_jwt "articles:write"

# Try to update non-existent post
update_body='{"title":"Ghost Post"}'
response=$(api_put "/articles/999999" "$update_body")
status=$(get_status)

assert_status "404" "$status" "Update non-existent post returns 404"
assert_json_field "$response" ".code" "post_not_found" "Error code is post_not_found"
