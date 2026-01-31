=== Arcadia Agents ===
Contributors: arcadiaagents
Tags: seo, content management, automation, rest api, gutenberg
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to Arcadia Agents for autonomous SEO content management.

== Description ==

Arcadia Agents is a WordPress plugin that enables seamless integration between your WordPress site and the Arcadia Agents platform for automated SEO content management.

**Features:**

* **REST API** - Secure endpoints for content management (posts, pages, media, taxonomies)
* **JWT Authentication** - Asymmetric RS256 authentication for maximum security
* **Gutenberg Support** - Native WordPress block generation
* **ACF Blocks Support** - Compatible with Advanced Custom Fields Pro blocks
* **Granular Permissions** - 8 configurable scopes for fine-grained access control

**How it works:**

1. Get your Connection Key from the Arcadia Agents dashboard
2. Enter the key in WordPress under Settings → Arcadia Agents
3. Configure the permissions you want to grant
4. Arcadia Agents can now publish and manage content on your site

== Installation ==

1. Upload the `arcadia-agents` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → Arcadia Agents to configure the connection

== Frequently Asked Questions ==

= What is Arcadia Agents? =

Arcadia Agents is a platform that uses AI to help manage SEO content on WordPress sites. This plugin provides the connection between your site and the platform.

= Is my site secure? =

Yes. The plugin uses asymmetric JWT authentication (RS256) which means only Arcadia Agents can generate valid tokens. You also have full control over which permissions to grant.

= What permissions are available? =

* posts:read - Read articles
* posts:write - Create/edit articles
* posts:delete - Delete articles
* media:read - Read media library
* media:write - Upload media
* taxonomies:read - Read categories/tags
* taxonomies:write - Create categories/tags
* site:read - Read site info and pages

= Does it work with page builders? =

Currently, the plugin supports native Gutenberg blocks and ACF Blocks (Advanced Custom Fields Pro). Support for other page builders may be added in the future.

== Screenshots ==

1. Settings page with connection status and permissions

== Changelog ==

= 0.1.0 =
* Initial release
* REST API endpoints for posts, pages, media, taxonomies
* JWT RS256 authentication
* Gutenberg and ACF Blocks adapters
* Admin settings page with permission management

== Upgrade Notice ==

= 0.1.0 =
Initial release of Arcadia Agents plugin.
