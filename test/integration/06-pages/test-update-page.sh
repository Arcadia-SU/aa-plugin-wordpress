#!/bin/bash
# Test: Update page

generate_jwt "site:read posts:write"

# Get list of pages first
response=$(api_get "/pages")
page_id=$(echo "$response" | jq -r '.pages[0].id // empty')

if [[ -z "$page_id" || "$page_id" == "null" ]]; then
    test_start "Update page (skipped - no pages exist)"
    test_pass
else
    # Update the page
    original_title=$(echo "$response" | jq -r '.pages[0].title // empty')
    update_body="{\"title\":\"Updated Page $(date +%s)\"}"
    response=$(api_put "/pages/$page_id" "$update_body")
    status=$(get_status)

    assert_status "200" "$status" "Update page returns 200"
    assert_json_exists "$response" ".id" "Response contains id"

    # Restore original title
    restore_body="{\"title\":\"$original_title\"}"
    api_put "/pages/$page_id" "$restore_body" > /dev/null
fi
