# Arcadia Agents - WordPress Plugin

Connect your WordPress site to Arcadia Agents for autonomous SEO content management.

## Features

- **REST API** for content management (posts, pages, media, taxonomies)
- **JWT authentication** (RS256) with granular scopes
- **Gutenberg & ACF Blocks** support via adapter pattern
- **Media sideloading** from URLs

## Installation

1. Download the plugin from [Releases](../../releases)
2. Upload to `/wp-content/plugins/arcadia-agents/`
3. Activate in WordPress admin
4. Configure in **Settings → Arcadia Agents**

## Configuration

1. Get your **Connection Key** from your Arcadia Agents dashboard
2. Paste it in **Settings → Arcadia Agents**
3. Enable the permissions (scopes) you want to grant
4. Click **Test Connection** to verify

## API Endpoints

All endpoints are prefixed with `/wp-json/arcadia/v1/`

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/health` | GET | Health check (no auth) |
| `/posts` | GET, POST | List/create posts |
| `/posts/{id}` | PUT, DELETE | Update/delete post |
| `/pages` | GET | List pages |
| `/pages/{id}` | PUT | Update page |
| `/media` | POST | Upload media via URL |
| `/categories` | GET, POST | List/create categories |
| `/tags` | GET | List tags |
| `/site-info` | GET | Site information |

## Development

See [CLAUDE.md](CLAUDE.md) for development instructions.

```bash
# Start local environment
./start.sh

# Stop
./stop.sh
```

## Requirements

- WordPress 6.0+
- PHP 8.0+

## License

GPL v2 or later
