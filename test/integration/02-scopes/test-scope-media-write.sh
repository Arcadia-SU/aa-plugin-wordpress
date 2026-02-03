#!/bin/bash
# Test: media:write scope

# With correct scope
generate_jwt "media:write"
body='{"url":"https://picsum.photos/100/100","alt":"Test"}'
response=$(api_post "/media" "$body")
status=$(get_status)
# Note: May fail if URL is not accessible, but should not be 403
if [[ "$status" == "403" ]]; then
    test_start "media:write scope allows POST /media"
    test_fail "Got 403 Forbidden"
else
    test_start "media:write scope allows POST /media"
    test_pass
fi

# Without scope
generate_jwt "media:read"
response=$(api_post "/media" "$body")
status=$(get_status)
assert_status "403" "$status" "Without media:write scope, POST /media returns 403"
