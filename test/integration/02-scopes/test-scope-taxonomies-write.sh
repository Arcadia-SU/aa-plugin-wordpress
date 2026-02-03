#!/bin/bash
# Test: taxonomies:write scope

# With correct scope
generate_jwt "taxonomies:write"
body="{\"name\":\"Test Category $(date +%s)\"}"
response=$(api_post "/categories" "$body")
status=$(get_status)
assert_status "201" "$status" "taxonomies:write scope allows POST /categories"

# Track for potential cleanup
cat_id=$(echo "$response" | jq -r '.id // .term_id // empty')

# Without scope
generate_jwt "taxonomies:read"
response=$(api_post "/categories" "$body")
status=$(get_status)
assert_status "403" "$status" "Without taxonomies:write scope, POST /categories returns 403"
