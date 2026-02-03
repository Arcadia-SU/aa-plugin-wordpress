#!/bin/bash
# Test: Upload media from non-existent URL

generate_jwt "media:write"

body='{"url":"https://example.com/nonexistent-image-12345.jpg","alt":"Test"}'
response=$(api_post "/media" "$body")
status=$(get_status)

assert_status "400" "$status" "Upload from 404 URL returns 400"
assert_json_exists "$response" ".code" "Response contains error code"
