#!/bin/bash
# Test: List categories

generate_jwt "taxonomies:read"

response=$(api_get "/categories")
status=$(get_status)

assert_status "200" "$status" "List categories returns 200"
assert_json_exists "$response" ".categories" "Response contains categories array"

# Each category should have required fields
first_cat=$(echo "$response" | jq -r '.categories[0] // empty')
if [[ -n "$first_cat" && "$first_cat" != "null" ]]; then
    assert_json_exists "$response" ".categories[0].id" "Category has id"
    assert_json_exists "$response" ".categories[0].name" "Category has name"
    assert_json_exists "$response" ".categories[0].slug" "Category has slug"
fi
