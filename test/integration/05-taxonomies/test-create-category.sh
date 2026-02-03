#!/bin/bash
# Test: Create category

generate_jwt "taxonomies:write"

unique_name="Test Category $(date +%s)"
body="{\"name\":\"$unique_name\"}"
response=$(api_post "/categories" "$body")
status=$(get_status)

assert_status "201" "$status" "Create category returns 201"
assert_json_exists "$response" ".id" "Response contains id"
assert_json_field "$response" ".name" "$unique_name" "Name matches"
assert_json_exists "$response" ".slug" "Response contains slug"

cat_id=$(echo "$response" | jq -r '.id // .term_id // empty')
if [[ -n "$cat_id" ]]; then
    CREATED_CATEGORIES+=("$cat_id")
fi
