#!/bin/bash
# Test: Upload media from URL

generate_jwt "media:write"

body='{"url":"https://picsum.photos/200/200","alt":"Test image","title":"URL Upload Test"}'
response=$(api_post "/media" "$body")
status=$(get_status)

# May return 201 on success or 400 if URL unavailable
if [[ "$status" == "201" ]]; then
    test_start "Upload media from URL returns 201"
    test_pass
    assert_json_exists "$response" ".attachment_id" "Response contains attachment_id"
    assert_json_exists "$response" ".url" "Response contains URL"

    media_id=$(echo "$response" | jq -r '.attachment_id // empty')
    if [[ -n "$media_id" ]]; then
        CREATED_MEDIA+=("$media_id")
    fi
else
    test_start "Upload media from URL"
    test_fail "Expected 201, got $status (URL may be unavailable)"
fi
