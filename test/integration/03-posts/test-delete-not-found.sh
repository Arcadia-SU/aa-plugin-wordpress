#!/bin/bash
# Test: Delete non-existent post

generate_jwt "posts:delete"

# Try to delete non-existent post
response=$(api_delete "/posts/999999")
status=$(get_status)

assert_status "404" "$status" "Delete non-existent post returns 404"
