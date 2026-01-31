#!/bin/bash
# Stop the development environment

cd "$(dirname "$0")"

echo "🛑 Stopping Arcadia Agents WordPress dev environment..."
docker compose down
echo "✅ Stopped."
