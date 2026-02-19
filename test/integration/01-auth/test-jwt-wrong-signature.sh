#!/bin/bash
# Test: JWT with wrong signature

# Create a JWT-like token with wrong signature
JWT_TOKEN="eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJhcmNhZGlhLWFnZW50cyIsInN1YiI6ImFwaS1jbGllbnQiLCJzY29wZXMiOlsicG9zdHM6cmVhZCJdLCJpYXQiOjE3MDAwMDAwMDAsImV4cCI6OTk5OTk5OTk5OX0.invalid_signature"
export JWT_TOKEN

# Make request
response=$(api_get "/articles")
status=$(get_status)

# Assertions
assert_status "401" "$status" "Wrong signature returns 401"
