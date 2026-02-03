#!/bin/bash
# Test: Malformed JWT authentication

# Generate malformed JWT
generate_malformed_jwt

# Make request
response=$(api_get "/posts")
status=$(get_status)

# Assertions
assert_status "401" "$status" "Malformed JWT returns 401"
