#!/bin/bash
# Test: Create category with parent

generate_jwt "taxonomies:write"

# Create parent category
parent_name="Parent Category $(date +%s)"
body="{\"name\":\"$parent_name\"}"
response=$(api_post "/categories" "$body")
parent_id=$(echo "$response" | jq -r '.id // .term_id // empty')
CREATED_CATEGORIES+=("$parent_id")

# Create child category
child_name="Child Category $(date +%s)"
body="{\"name\":\"$child_name\",\"parent\":$parent_id}"
response=$(api_post "/categories" "$body")
status=$(get_status)

assert_status "201" "$status" "Create child category returns 201"
assert_json_field "$response" ".parent" "$parent_id" "Parent ID matches"

child_id=$(echo "$response" | jq -r '.id // .term_id // empty')
if [[ -n "$child_id" ]]; then
    CREATED_CATEGORIES+=("$child_id")
fi
