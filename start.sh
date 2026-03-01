#!/bin/bash
# Start the development environment

set -e

cd "$(dirname "$0")"

echo "🚀 Starting Arcadia Agents WordPress dev environment..."
echo ""

# Start containers
docker compose up -d

echo ""
echo "✅ Environment started!"
echo ""
echo "📍 WordPress:   http://localhost:8082"
echo "📍 PHPMyAdmin:  http://localhost:8083"
echo ""
echo "🔧 First time setup:"
echo "   1. Go to http://localhost:8082 and complete WP installation"
echo "   2. Activate the 'Arcadia Agents' plugin"
echo "   3. Go to Settings → Arcadia Agents"
echo ""
echo "📝 Plugin code is mounted at:"
echo "   ./arcadia-agents → wp-content/plugins/arcadia-agents"
echo ""
echo "🛑 To stop: docker compose down"
