=== Arcadia Agents ===
Contributors: arcadiaagents
Tags: seo, content management, automation, rest api, gutenberg
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.9.0
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

= 0.9.0 =
A proposal now previews in the site's real template — the one WordPress itself picks — instead of in a hand-written approximation of it. And when a preview genuinely cannot be rendered, the page says so instead of impersonating the site.

* Fixed: a revision preview came back as a bare wall of text on some post types and rendered perfectly on others. The post handed to the theme's loop was still an `aa_revision`, so any template that branches on it — a `if ( 'expertise_sante' !== get_post_type() ) { return; }` guard on its first line, a taxonomy lookup, a field read on get_the_ID() — returned without printing a byte. Measured on a client preprod: two of ten pending proposals came back bare, and they were exactly the two whose post type has such a template. The loop post now carries the parent's post type, while keeping the revision's ID so the text on screen is still the proposal and never the live page
* Changed: the preview template is resolved through WordPress's own chain — get_single_template() / get_page_template(), then the `template_include` filter — instead of a copied hierarchy fed to locate_template(). A theme that routes its templates by filter, which is how several agency themes are built, was being short-circuited and the preview included whatever the copy happened to land on
* New: previews work on block themes. A block theme's templates are not files in the theme directory, so the previous resolution could never reach one and every preview fell through to the fallback page
* New: the fallback page now says what it is — "Simplified preview: your site's design is not shown here" — and tells the reviewer that the text below is the content that will be published, and that it lands in the site's usual layout once approved. Until now that page impersonated the site, so a reviewer could approve believing they had seen the real thing
* Unchanged: API contract identical to 0.8.0 — this release only touches preview rendering

= 0.8.0 =
The review queue is workable at volume: proposals can be decided in batch, and a row now carries three ranked actions on a single line instead of four wrapped over two.

* New: checkbox selection in the review queue, with a bulk bar that only appears once something is selected — approve or reject the whole selection in one confirmation instead of one decision per row
* New: shift-click extends the selection from the last box clicked, in both directions — picking a run of consecutive proposals is one gesture
* New: the header checkbox selects everything when nothing is selected and clears the selection otherwise, showing a minus while the selection is partial; Escape backs out of an open confirmation, then out of the selection
* New: a bulk run reports its outcome honestly — it processes one proposal at a time, removes each row as it succeeds, and on a partial failure says how many failed and leaves those rows in the queue
* Changed: Approve and Reject are now icon buttons (✓ / ✕), Preview is a quiet icon+label revealed on row hover or keyboard focus, and the article title itself links to the review view — the same four destinations, ranked instead of shouted
* Changed: the "Edit" button is gone from the queue; the article title carries that link, which is where the diff and the approve/reject controls already live
* Unchanged: API contract identical to 0.7.0 — this release only touches the plugin's admin screens

= 0.7.0 =
The admin list UI now covers custom post types — found on the first CPT-built client site, where all agent content lives in a custom `article` type.

* New: the "Arcadia (n)" view, the list filter and the badge styling now appear on every public post type's list screen, not just Posts and Pages — the rule is the same as the API's write surface (public types except media), so the review queue works on sites built on custom post types
* New: the dashboard "Arcadia contents" card enumerates every post type actually holding agent content, one link per type, instead of a hardcoded Posts/Pages pair (which read "0" on CPT-built sites)
* Unchanged: the badge itself already rendered on all post types; API contract identical to 0.6.0

= 0.6.2 =
Visual polish only — identical API contract to 0.6.0.

* Changed: the "Agent: Draft only / Direct publishing" chip no longer appears in the Posts and Pages lists — it is a set-once setting, not permanent list chrome. It remains on the plugin dashboard and at the top of the settings page

= 0.6.1 =
Visual polish only — identical API contract to 0.6.0.

* Fixed: the "Arcadia" badge in the admin lists overpowered the row it sat on — it is now slimmer (11px, reduced padding) and uses a soft 22% tint of the brand color instead of a solid fill, following the same recipe as the publishing-guard chip

= 0.6.0 =
The plugin's presence in WordPress is now visible and legible — feedback from the first client onboarding session.

* New: agent-created articles and pages carry an "Arcadia" badge in the admin lists, from draft to published; content the agent merely edited is not badged
* New: an "Arcadia (n)" view in the Posts and Pages lists, combinable with the status links — Arcadia then Drafts is the review queue in two clicks
* New: the publishing guard is readable at a glance — "Draft only" / "Direct publishing" chip in the lists and the dashboard, a dedicated Publishing section at the top of the settings, and switching it off now asks for an explicit confirmation that saves immediately
* New: every permission checkbox has a "?" tooltip explaining what it unlocks (works on click, keyboard and hover); permissions are grouped by theme
* New: full French translation (fr_FR) — the interface follows the site or profile language, nothing to configure
* New: settings and dashboard redesigned to the Arcadia design system, scoped to the plugin's own pages only — the rest of the admin stays native
* Changed: the handshake now sends `home_url()` instead of `site_url()` — on "WordPress in its own directory" installs the old value pointed REST calls at a 404
* Changed: "Connect" now uses the key typed in the same submit — no need to save it first
* Fixed: the connection test result is rendered as text, not injected as HTML
* Fixed: approving or rejecting a proposal from the dashboard no longer reloads the page

= 0.5.2 =
No plugin code changed — identical contract to 0.5.1.

* Fixed a release-pipeline blind spot: every build is now installed as an upgrade over the previous release on a disposable WordPress before shipping, asserting the plugin stays active, /health reports the new version, and stored content survives byte-identical

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
