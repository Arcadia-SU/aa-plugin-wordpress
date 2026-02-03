#!/bin/bash
# API client functions for integration tests

# Last HTTP response
LAST_RESPONSE=""
LAST_STATUS=""
LAST_BODY=""

# Current JWT token
JWT_TOKEN=""

# Generate JWT with specific scopes
# Usage: generate_jwt scope1 scope2 ...
generate_jwt() {
    local scopes="$*"
    # Run PHP inside Docker container
    JWT_TOKEN=$(docker compose exec -T wordpress bash -c "cd /var/www/html/wp-content/plugins/arcadia-agents/test && php generate-jwt.php private_key.pem '$scopes' 2>/dev/null | grep -E '^eyJ' | head -1")
    export JWT_TOKEN
}

# Generate expired JWT
generate_expired_jwt() {
    JWT_TOKEN=$(docker compose exec -T wordpress bash -c "cd /var/www/html/wp-content/plugins/arcadia-agents/test && php generate-jwt.php private_key.pem 'posts:read' --expired 2>/dev/null | grep -E '^eyJ' | head -1")
    export JWT_TOKEN
}

# Generate malformed JWT
generate_malformed_jwt() {
    JWT_TOKEN="not.a.valid.jwt.token"
    export JWT_TOKEN
}

# Clear JWT (for testing missing auth)
clear_jwt() {
    JWT_TOKEN=""
    export JWT_TOKEN
}

# Make API GET request
# Usage: api_get endpoint [extra_curl_args]
api_get() {
    local endpoint="$1"
    shift
    local extra_args="$*"

    local url="${API_BASE}${endpoint}"
    local auth_header=""

    if [[ -n "$JWT_TOKEN" ]]; then
        auth_header="-H \"Authorization: Bearer $JWT_TOKEN\""
    fi

    LAST_RESPONSE=$(eval curl -s -w "\n%{http_code}" \
        -X GET \
        -H "Content-Type: application/json" \
        $auth_header \
        --max-time "$REQUEST_TIMEOUT" \
        $extra_args \
        "\"$url\"" 2>/dev/null)

    # Split response body and status
    LAST_STATUS=$(echo "$LAST_RESPONSE" | tail -n1)
    LAST_BODY=$(echo "$LAST_RESPONSE" | sed '$d')

    echo "$LAST_BODY"
}

# Make API POST request
# Usage: api_post endpoint body [extra_curl_args]
api_post() {
    local endpoint="$1"
    local body="$2"
    shift 2
    local extra_args="$*"

    local url="${API_BASE}${endpoint}"
    local auth_header=""

    if [[ -n "$JWT_TOKEN" ]]; then
        auth_header="-H \"Authorization: Bearer $JWT_TOKEN\""
    fi

    LAST_RESPONSE=$(eval curl -s -w "\n%{http_code}" \
        -X POST \
        -H "Content-Type: application/json" \
        $auth_header \
        --max-time "$REQUEST_TIMEOUT" \
        -d "'$body'" \
        $extra_args \
        "\"$url\"" 2>/dev/null)

    LAST_STATUS=$(echo "$LAST_RESPONSE" | tail -n1)
    LAST_BODY=$(echo "$LAST_RESPONSE" | sed '$d')

    echo "$LAST_BODY"
}

# Make API PUT request
# Usage: api_put endpoint body [extra_curl_args]
api_put() {
    local endpoint="$1"
    local body="$2"
    shift 2
    local extra_args="$*"

    local url="${API_BASE}${endpoint}"
    local auth_header=""

    if [[ -n "$JWT_TOKEN" ]]; then
        auth_header="-H \"Authorization: Bearer $JWT_TOKEN\""
    fi

    LAST_RESPONSE=$(eval curl -s -w "\n%{http_code}" \
        -X PUT \
        -H "Content-Type: application/json" \
        $auth_header \
        --max-time "$REQUEST_TIMEOUT" \
        -d "'$body'" \
        $extra_args \
        "\"$url\"" 2>/dev/null)

    LAST_STATUS=$(echo "$LAST_RESPONSE" | tail -n1)
    LAST_BODY=$(echo "$LAST_RESPONSE" | sed '$d')

    echo "$LAST_BODY"
}

# Make API DELETE request
# Usage: api_delete endpoint [extra_curl_args]
api_delete() {
    local endpoint="$1"
    shift
    local extra_args="$*"

    local url="${API_BASE}${endpoint}"
    local auth_header=""

    if [[ -n "$JWT_TOKEN" ]]; then
        auth_header="-H \"Authorization: Bearer $JWT_TOKEN\""
    fi

    LAST_RESPONSE=$(eval curl -s -w "\n%{http_code}" \
        -X DELETE \
        -H "Content-Type: application/json" \
        $auth_header \
        --max-time "$REQUEST_TIMEOUT" \
        $extra_args \
        "\"$url\"" 2>/dev/null)

    LAST_STATUS=$(echo "$LAST_RESPONSE" | tail -n1)
    LAST_BODY=$(echo "$LAST_RESPONSE" | sed '$d')

    echo "$LAST_BODY"
}

# Get last HTTP status code
get_status() {
    echo "$LAST_STATUS"
}

# Get last response body
get_body() {
    echo "$LAST_BODY"
}

# Load fixture file
# Usage: load_fixture path/to/fixture.json
load_fixture() {
    local fixture_path="$1"
    cat "$FIXTURES_DIR/$fixture_path"
}
