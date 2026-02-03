#!/bin/bash
# Test: Valid JWT authentication

# Generate valid JWT
generate_jwt "posts:read"

# Make request
response=$(api_get "/posts")
status=$(get_status)

# Assertions
assert_status "200" "$status" "Valid JWT returns 200"
assert_json_exists "$response" ".posts" "Response contains posts array"
