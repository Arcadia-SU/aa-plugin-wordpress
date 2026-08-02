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
| `/contents` | GET, POST | List/create content |
| `/contents/{id}` | PUT, DELETE | Update/delete content |
| `/contents/{id}/blocks` | GET | Parsed block tree + ACF field values |
| `/contents/{id}/preview-url` | GET | Tokenised preview URL |
| `/contents/{id}/featured-image` | PUT | Set featured image |
| `/contents/{id}/revisions` | GET | List pending revisions |
| `/contents/{id}/revisions/{revision_id}` | GET | Read one revision |
| `/pages` | GET | List pages |
| `/media` | GET, POST | List/upload media |
| `/categories` | GET, POST | List/create categories |
| `/tags` | GET | List tags |
| `/site-info` | GET | Site information |

`/contents` serves any public post type except `attachment` — posts, pages and
hierarchical CPTs alike. Site structure stays out of scope: a body carrying
`post_parent`, `menu_order` or `page_template` is refused with a 422.

### Deprecated paths

Both still work and will keep working until **2027-02-01**. Responses carry
`Deprecation`, `Sunset` and `Link; rel="successor-version"` headers.

| Deprecated | Replacement |
|------------|-------------|
| `/articles*` (every method) | the matching `/contents*` path |
| `/pages/{id}` (PUT only) | `/contents/{id}` |

`GET /pages` is **not** deprecated.

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
