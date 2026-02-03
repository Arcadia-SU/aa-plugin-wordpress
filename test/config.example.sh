#!/bin/bash
# Test configuration template
# Copy this file to config.sh and adjust values

# WordPress site URL
export WP_URL="http://localhost:8080"

# API namespace
export API_BASE="${WP_URL}/wp-json/arcadia/v1"

# Path to JWT generation script
export JWT_SCRIPT="../arcadia-agents/test/generate-jwt.php"

# Path to private key for JWT signing
export PRIVATE_KEY_PATH="../arcadia-agents/test/private.pem"

# Path to public key (configured in WordPress)
export PUBLIC_KEY_PATH="../arcadia-agents/test/public.pem"

# Test timeouts (seconds)
export REQUEST_TIMEOUT=30

# Verbose output (true/false)
export VERBOSE=false
