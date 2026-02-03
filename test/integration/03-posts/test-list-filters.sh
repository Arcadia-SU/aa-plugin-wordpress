#!/bin/bash
# Test: List posts with filters

generate_jwt "posts:read"

# Test per_page
response=$(api_get "/posts?per_page=5")
status=$(get_status)
assert_status "200" "$status" "List with per_page returns 200"

# Test status filter
response=$(api_get "/posts?status=draft")
status=$(get_status)
assert_status "200" "$status" "List with status filter returns 200"

# Test search
response=$(api_get "/posts?search=test")
status=$(get_status)
assert_status "200" "$status" "List with search returns 200"

# Test pagination
response=$(api_get "/posts?page=1&per_page=2")
status=$(get_status)
assert_status "200" "$status" "List with pagination returns 200"
