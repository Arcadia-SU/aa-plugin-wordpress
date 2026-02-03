#!/bin/bash
# Test: Upload non-image URL

generate_jwt "media:write"

# Try to upload a non-image file
body='{"url":"https://example.com/","alt":"Not an image"}'
response=$(api_post "/media" "$body")
status=$(get_status)

# Should fail with 400
assert_status "400" "$status" "Upload non-image returns 400"
