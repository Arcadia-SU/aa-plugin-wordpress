#!/bin/bash
# Test: Missing JWT authentication

# Clear JWT
clear_jwt

# Make request
response=$(api_get "/posts")
status=$(get_status)

# Assertions
assert_status "401" "$status" "Missing JWT returns 401"
assert_json_field "$response" ".code" "missing_token" "Error code is missing_token"
