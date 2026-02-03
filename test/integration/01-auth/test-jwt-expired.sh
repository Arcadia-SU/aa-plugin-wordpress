#!/bin/bash
# Test: Expired JWT authentication

# Generate expired JWT
generate_expired_jwt

# Make request
response=$(api_get "/posts")
status=$(get_status)

# Assertions
assert_status "401" "$status" "Expired JWT returns 401"
assert_json_field "$response" ".code" "jwt_expired" "Error code is jwt_expired"
