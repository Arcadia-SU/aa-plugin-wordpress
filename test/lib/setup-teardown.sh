#!/bin/bash
# Test setup and teardown functions

# Array to track created resources for cleanup
declare -a CREATED_POSTS
declare -a CREATED_MEDIA
declare -a CREATED_CATEGORIES
declare -a CREATED_TAGS

# Initialize test environment
setup_tests() {
    CREATED_POSTS=()
    CREATED_MEDIA=()
    CREATED_CATEGORIES=()
    CREATED_TAGS=()

    # Ensure results directory exists
    mkdir -p "$RESULTS_DIR"

    # Clear previous failure log
    > "$RESULTS_DIR/failures.log"

    # Generate default JWT with all scopes for setup
    generate_jwt "posts:read posts:write posts:delete media:read media:write taxonomies:read taxonomies:write site:read"
}

# Create a test post and track it for cleanup
# Usage: create_test_post [title] [status]
# Returns: post ID
create_test_post() {
    local title="${1:-Test Post $(date +%s)}"
    local status="${2:-draft}"

    local body=$(cat <<EOF
{
    "title": "$title",
    "status": "$status",
    "content": {
        "blocks": [
            {"type": "paragraph", "content": "Test content"}
        ]
    }
}
EOF
)

    local response
    response=$(api_post "/posts" "$body")
    local post_id
    post_id=$(echo "$response" | jq -r '.id // .post_id // empty')

    if [[ -n "$post_id" && "$post_id" != "null" ]]; then
        CREATED_POSTS+=("$post_id")
        echo "$post_id"
    else
        echo ""
        return 1
    fi
}

# Create a test category and track it for cleanup
# Usage: create_test_category [name] [parent_id]
# Returns: category ID
create_test_category() {
    local name="${1:-Test Category $(date +%s)}"
    local parent="${2:-0}"

    local body
    if [[ "$parent" != "0" ]]; then
        body="{\"name\": \"$name\", \"parent\": $parent}"
    else
        body="{\"name\": \"$name\"}"
    fi

    local response
    response=$(api_post "/categories" "$body")
    local cat_id
    cat_id=$(echo "$response" | jq -r '.id // .term_id // empty')

    if [[ -n "$cat_id" && "$cat_id" != "null" ]]; then
        CREATED_CATEGORIES+=("$cat_id")
        echo "$cat_id"
    else
        echo ""
        return 1
    fi
}

# Upload test media and track for cleanup
# Usage: upload_test_media [url]
# Returns: attachment ID
upload_test_media() {
    local url="${1:-https://picsum.photos/200/200}"

    local body="{\"url\": \"$url\", \"alt\": \"Test image\", \"title\": \"Test Media $(date +%s)\"}"

    local response
    response=$(api_post "/media" "$body")
    local media_id
    media_id=$(echo "$response" | jq -r '.attachment_id // empty')

    if [[ -n "$media_id" && "$media_id" != "null" ]]; then
        CREATED_MEDIA+=("$media_id")
        echo "$media_id"
    else
        echo ""
        return 1
    fi
}

# Clean up all created test resources
cleanup_test_data() {
    # Delete posts
    for post_id in "${CREATED_POSTS[@]}"; do
        if [[ -n "$post_id" ]]; then
            api_delete "/posts/$post_id" > /dev/null 2>&1
        fi
    done

    # Note: Media and taxonomies cleanup would need additional endpoints
    # or direct WP-CLI commands

    CREATED_POSTS=()
    CREATED_MEDIA=()
    CREATED_CATEGORIES=()
    CREATED_TAGS=()
}

# Run before each test file
before_test_file() {
    local test_file="$1"
    if [[ "$VERBOSE" == "true" ]]; then
        echo ""
        echo "Running: $(basename "$test_file")"
        echo "----------------------------------------"
    fi
}

# Run after each test file
after_test_file() {
    # Cleanup resources created during this test file
    cleanup_test_data
}
