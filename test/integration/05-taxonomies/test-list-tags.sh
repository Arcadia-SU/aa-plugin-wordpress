#!/bin/bash
# Test: List tags

generate_jwt "taxonomies:read"

response=$(api_get "/tags")
status=$(get_status)

assert_status "200" "$status" "List tags returns 200"
assert_json_exists "$response" ".tags" "Response contains tags array"
