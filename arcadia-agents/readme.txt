=== Arcadia Agents ===
Contributors: arcadiaagents
Tags: seo, content management, automation, rest api, gutenberg
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.5.1
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
* **Granular Permissions** - 14 configurable scopes for fine-grained access control

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

* articles:read - Read articles
* articles:write - Create/edit articles
* articles:delete - Delete articles
* revisions:write - Withdraw its own pending revisions (never approve them)
* media:read - Read media library
* media:write - Upload/edit media
* media:delete - Delete media
* taxonomies:read - Read categories/tags
* taxonomies:write - Create/edit categories/tags
* taxonomies:delete - Delete categories/tags
* site:read - Read site info and pages
* redirects:read - Read redirects
* redirects:write - Create/delete redirects
* settings:write - Update plugin settings

Each one is a checkbox in Settings → Arcadia Agents, off unless you tick it. A permission added by a plugin update always arrives disabled.

= Does it work with page builders? =

Currently, the plugin supports native Gutenberg blocks and ACF Blocks (Advanced Custom Fields Pro). Support for other page builders may be added in the future.

== Screenshots ==

1. Settings page with connection status and permissions

== Changelog ==

= 0.5.1 =
Covers everything since 0.3.0. Versions 0.4.0, 0.4.1 and 0.5.0 were built but never released.

* A pending revision now says what it proposes: field-by-field before/after in the API, in the classic editor banner and in the block editor panel
* Revision previews resolve custom fields against the page they modify instead of rendering an empty shell
* Pending revisions can be withdrawn through the API — new `revisions:write` permission, disabled by default, and there is deliberately no matching "approve" (approval stays a human decision)
* An edit held for approval now refuses a `status` change with a clear error instead of accepting it and doing nothing at approval time
* SEO meta is written to whichever SEO plugin is active — on a Rank Math or AIOSEO site, meta titles and descriptions previously went to Yoast's fields and never appeared
* Revision previews fixed: a rich-text field no longer renders blank when the edit proposes no page content, repeater/group/flexible fields no longer render a mix of old and new, and themes reading fields off the queried page now see the proposal
* The before/after list now matches what approval actually writes, and warns when disallowed HTML (iframes, scripts) will be stripped
* Revision details over the API no longer expose related posts or users in full, and long values are capped and flagged

= 0.3.0 =
* Write integrity: approving a revision replays the same pipeline as a direct write, so custom fields, taxonomies, featured image and SEO meta are no longer lost
* A partial update stays partial — an update that does not mention custom fields no longer erases them
* `meta.title` writes the SEO title only; it no longer renames the post
* Field calibration can be removed, and an unknown mapping source is rejected instead of stored and ignored

= 0.2.1 =
* No functional change — static-analysis and CI hygiene only (accurate type annotations in the preview renderer, stale analysis baseline entry removed, coding-standards job repaired)

= 0.2.0 =
* New `/contents*` endpoints — canonical name for the content surface
* `/articles*` and `PUT /pages/{id}` deprecated; both keep working until 2027-02-01 and now carry Deprecation/Sunset/Link headers
* Pages and hierarchical custom post types are now editable through the content endpoints (previously 404)
* `post_parent`, `menu_order` and `page_template` are refused with an explicit 422 instead of being silently ignored — site structure is not the agent's to change
* Revision previews now render in their parent's template instead of falling back to a generic one
* `word_count` is omitted rather than reported as 0 when the content lives in block attributes, and no longer miscounts accented text

= 0.1.0 =
* Initial release
* REST API endpoints for posts, pages, media, taxonomies
* JWT RS256 authentication
* Gutenberg and ACF Blocks adapters
* Admin settings page with permission management

== Upgrade Notice ==

= 0.2.1 =
Maintenance release. Identical behaviour to 0.2.0 — deploy this one instead if you have not shipped 0.2.0 yet.

= 0.2.0 =
Adds the `/contents*` endpoints and deprecates `/articles*` and `PUT /pages/{id}` (removal no earlier than 2027-02-01). `PUT /pages/{id}` now returns the same payload as `/contents/{id}`.

= 0.1.0 =
Initial release of Arcadia Agents plugin.
