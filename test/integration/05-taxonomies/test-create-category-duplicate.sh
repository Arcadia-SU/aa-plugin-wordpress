#!/bin/bash
# Test: Create duplicate category

generate_jwt "taxonomies:write"

# Create category
unique_name="Duplicate Test $(date +%s)"
body="{\"name\":\"$unique_name\"}"
response=$(api_post "/categories" "$body")
cat_id=$(echo "$response" | jq -r '.id // .term_id // empty')
CREATED_CATEGORIES+=("$cat_id")

# Try to create same category again
response=$(api_post "/categories" "$body")
status=$(get_status)

# WordPress returns 409 for duplicate term (term_exists error)
if [[ "$status" == "409" || "$status" == "400" ]]; then
    test_start "Create duplicate category returns 409 or 400"
    test_pass
else
    test_start "Create duplicate category"
    test_fail "Expected 409 or 400, got $status"
fi
