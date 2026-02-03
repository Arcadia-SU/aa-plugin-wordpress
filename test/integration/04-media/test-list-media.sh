#!/bin/bash
# Test: List media

generate_jwt "media:read"

response=$(api_get "/media")
status=$(get_status)

assert_status "200" "$status" "List media returns 200"
assert_json_exists "$response" ".media" "Response contains media array"
assert_json_exists "$response" ".total" "Response contains total"
assert_json_exists "$response" ".total_pages" "Response contains total_pages"

# Test with filters
response=$(api_get "/media?per_page=5")
status=$(get_status)
assert_status "200" "$status" "List media with per_page returns 200"

response=$(api_get "/media?mime_type=image/jpeg")
status=$(get_status)
assert_status "200" "$status" "List media with mime_type filter returns 200"

response=$(api_get "/media?search=test")
status=$(get_status)
assert_status "200" "$status" "List media with search returns 200"
