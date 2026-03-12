# Plugin WordPress - Checklist de développement

**Dernière mise à jour :** 2026-03-07

---

## Phase 0 : Préparation

### Specs & Documentation
- [DONE] Définir architecture (agent → plugin REST API)
- [DONE] Définir endpoints (11 → 13 avec edit pages)
- [DONE] Définir auth (JWT asymétrique + scopes)
- [DONE] Définir format JSON sémantique
- [DONE] Définir mapping vers ACF Blocks
- [DONE] Définir mapping vers Gutenberg natif
- [DONE] Documenter flow de connexion (Connection Key + handshake)
- [DONE] Finaliser JSON schema (avec featured_image_url)

### Environnement
- [DONE] Setup Docker (docker-compose.yml)
- [DONE] Créer site WP local de test
- [DONE] Plugin activé sur le site de test

---

## Phase 1 : Structure du plugin

### Setup repo
- [DONE] Créer repo GitHub (`Arcadia-SU/aa-plugin-wordpress`)
- [DONE] Créer structure de fichiers de base
- [DONE] Setup composer.json
- [DONE] Setup phpcs.xml (WordPress Coding Standards)
- [DONE] Créer readme.txt (format WP.org)

### Fichiers de base
- [DONE] `arcadia-agents.php` - Point d'entrée + métadonnées
- [DONE] `includes/class-api.php` - Enregistrement endpoints REST
- [DONE] `includes/class-auth.php` - Validation JWT
- [DONE] `includes/class-blocks.php` - Génération blocs Gutenberg/ACF
- [DONE] `admin/settings.php` - Page admin WP (structure de base)

---

## Phase 2 : Authentification

### Page admin (UI)
- [DONE] Champ Connection Key
- [DONE] Afficher état connexion (vert/rouge)
- [DONE] Checkboxes scopes
- [DONE] Bouton "Test connection"

### Backend auth
- [DONE] Implémenter validation JWT (RS256)
- [DONE] Stocker public key dans options WP
- [DONE] Implémenter vérification des scopes
- [DONE] Implémenter réponses d'erreur structurées
- [DONE] Implémenter handshake avec ArcadiaAgents
- [DONE] Sauvegarder les scopes sélectionnés
- [DONE] Fallback header `X-AA-Token` (Apache/Basic Auth/CDN/WAF compatibility)

---

## Phase 3 : Endpoints REST

### Articles
- [DONE] `POST /arcadia/v1/posts` - Créer article
- [DONE] `GET /arcadia/v1/posts` - Lister articles
- [DONE] `PUT /arcadia/v1/posts/{id}` - Modifier article
- [DONE] `DELETE /arcadia/v1/posts/{id}` - Supprimer article

### Pages
- [DONE] `GET /arcadia/v1/pages` - Lister pages
- [DONE] `PUT /arcadia/v1/pages/{id}` - Modifier page

### Médias
- [DONE] `POST /arcadia/v1/media` - Upload via URL (sideload)
- [DONE] `PUT /arcadia/v1/posts/{id}/featured-image` - Définir image à la une

### Taxonomies
- [DONE] `GET /arcadia/v1/categories` - Lister catégories
- [DONE] `GET /arcadia/v1/tags` - Lister tags
- [DONE] `POST /arcadia/v1/categories` - Créer catégorie

### Site
- [DONE] `GET /arcadia/v1/health` - Health check (sans auth)
- [DONE] `GET /arcadia/v1/site-info` - Infos site

---

## Phase 4 : Génération de blocs

### Infrastructure
- [DONE] Créer interface `BlockAdapter`
- [DONE] Implémenter détection automatique du mode (ACF vs Gutenberg)
- [DONE] Implémenter parsing markdown → HTML (liens)

### Gutenberg Adapter (MVP)
- [DONE] Mapper `heading` → `wp:heading`
- [DONE] Mapper `paragraph` → `wp:paragraph`
- [DONE] Mapper `image` → `wp:image`
- [DONE] Mapper `list` → `wp:list`

### ACF Adapter (MVP)
- [DONE] Mapper `heading` → `acf/title`
- [DONE] Mapper `paragraph` → `acf/text`
- [DONE] Mapper `image` → `acf/image`
- [DONE] Mapper `list` → `acf/text` (avec ul/ol)

### Custom Blocks (Q8)
- [DONE] Créer Block Registry (centraliser blocs MVP + introspection ACF/Gutenberg)
- [DONE] Endpoint `GET /arcadia/v1/blocks` (scope `site:read`)
- [DONE] Méthode `custom_block()` sur les adapters (ACF + Gutenberg)
- [DONE] Validation fail fast : 422 si type inconnu ou champ requis manquant
- [DONE] Gestion types ACF : sideload image → attachment ID
- [DONE] Gestion types ACF : aplatissement repeater
- [DONE] Tests unitaires custom blocks
- [DONE] Tests intégration endpoint `GET /blocks`

### ACF Fields (Q9)
- [DONE] Discovery champs ACF par post type dans `GET /site-info` (`acf_field_groups`)
- [DONE] Écriture via `acf_fields` dans `POST /articles` et `PUT /articles/{id}`
- [DONE] Fallback wysiwyg null → copie `post_content`
- [DONE] Auto-populate ACF fields (safety net pour `get_fields()`)
- [DONE] Fix finding 023 : `do_action('acf/save_post', $post_id)` dans create + update
- [DONE] Tests unitaires ACF fields (10 tests)

### Block Usage (Q10)
- [DONE] Endpoint `GET /arcadia/v1/blocks/usage` (scope `site:read`)
- [DONE] Params : `post_type` filter, `sample_size` (clamped 1-10)
- [DONE] Récursion `innerBlocks` avec context parent
- [DONE] Cache transient 24h + invalidation `save_post`
- [DONE] Tests unitaires block usage (12 tests)
- [DONE] ~~Finding 024 : strip namespace prefixes~~ → **Superseded by ADR-022 D2** (2026-03-02)
- [DONE] ADR-022 D2 : retourner noms CMS namespaced (`core/paragraph`, `acf/bouton`) dans `/blocks` et `/blocks/usage`

---

## Phase 5 : Tests

- [DONE] Setup PHPUnit
- [DONE] Tests unitaires : validation JWT
- [DONE] Tests unitaires : parsing JSON → blocs
- [DONE] Tests unitaires : markdown → HTML
- [DONE] Tests intégration : endpoints REST
- [DONE] Test manuel : créer article complet via API
- [DONE] Test manuel : site client (ACF Pro) → [checklist-test-site-client.md](checklist-test-site-client.md)

---

## Phase 6 : CI/CD

- [DONE] GitHub Action : lint (PHPCS)
- [DONE] GitHub Action : tests (PHPUnit) - placeholder, tests à implémenter
- [DONE] GitHub Action : deploy WP.org (10up/action-wordpress-plugin-deploy)

*Note : noté done mais à vérifier*

---

## Phase 6b : Code Review Fixes

*Ref: [code-review.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/plugin-wp-specs/code-review.md) — Audit v2.0.1, 33 issues*

### CRITICAL + HIGH (specs item 16)
- [DONE] #1 — `do_action('acf/save_post')` manquant (finding 023)
- [N/A] #2 — JWT secret en clair dans wp_options (on utilise RS256 + public key, pas de secret côté plugin)
- [N/A] #4 — Timing attack JWT (délégué à firebase/php-jwt pour RS256)
- [DONE] #13 — Featured image download : timeout explicite + SSRF hardening
- [DONE] #24 — SEO meta manquant dans `update_post`
- [DONE] #27 — Test files exclus du zip (vérifié : build.sh exclut `/tests/*` + check 9)
- [DONE] #29 — Champ Connection Key en `type="password"` dans admin

### MEDIUM (specs item 17)
- [DONE] #3 — JWT clock skew tolerance + validation iat/nbf
- [N/A] #5 — base64url_decode (délégué à firebase/php-jwt)
- [DONE] #6 — Type-checking strict sur retour validate_jwt
- [DONE] #7 — Validation post_type contre types enregistrés
- [DONE] #9 — Gestion erreur création catégorie/tag : warnings surfacés dans la réponse API
- [DONE] #10 — update_post vérifie existence du post
- [DONE] #11 — update_post empêche changement de post_type (retourne 400)
- [DONE] #12 — DRY catégories/tags : les deux utilisent `get_or_create_terms()`
- [DONE] #14 — basename() query strings
- [DONE] #18 — `/health` n'expose plus les versions WP/PHP (info disclosure)
- [DONE] #22 — Categories update remplace au lieu de merger

---

## Phase 8 : Endpoints v2

### Source tracking & Enriched format_post (P0-a)
- [DONE] Hidden taxonomy `arcadia_source` registration
- [DONE] `wp_set_object_terms` in `create_post()` for source tracking
- [DONE] `Arcadia_SEO_Meta` class (multi-plugin detection: Yoast > RankMath > AIOSEO > native)
- [DONE] Enriched `format_post()`: author name, category/tag names, published_at, last_modified, word_count, has_blocks, seo{}

### Enriched filters GET /articles (P0-b)
- [DONE] category filter (slug or ID)
- [DONE] tag filter
- [DONE] author filter (email/login/ID resolution)
- [DONE] date_from/date_to filter (date_query)
- [DONE] orderby whitelist (date, title, modified)
- [DONE] order whitelist (ASC, DESC)
- [DONE] source filter (arcadia/wordpress/all via tax_query)

### GET /articles/{id}/blocks (P0-c)
- [DONE] `get_article_blocks()` using `parse_blocks()`
- [DONE] Recursive `format_parsed_blocks()` with innerBlocks
- [DONE] Null blockName skip, empty attrs as object

### POST /tags + PUT categories + PUT tags (P1-a)
- [DONE] `create_tag()` with duplicate detection
- [DONE] `update_category()` via `wp_update_term()`
- [DONE] `update_tag()` via `wp_update_term()`

### DELETE categories + DELETE tags (P1-b)
- [DONE] `delete_category()` via `wp_delete_term()`
- [DONE] `delete_tag()` via `wp_delete_term()`
- [DONE] Scope `taxonomies:delete` + admin checkbox

### Media enriched + PUT/DELETE media (P2-a)
- [DONE] `type` param (image/video/audio/application → MIME prefix)
- [DONE] `date_from`/`date_to` filters
- [DONE] `update_media()` (title, caption, alt_text)
- [DONE] `delete_media()` with force flag
- [DONE] Scope `media:delete` + admin checkbox

### GET /menus + GET /users (P2-b)
- [DONE] `get_menus()`: hierarchical tree from `wp_get_nav_menu_items()`
- [DONE] `get_users_list()`: role filter + `count_user_posts()`

### Redirects CRUD (P2-c)
- [DONE] Hidden CPT `arcadia_redirect`
- [DONE] `template_redirect` hook for serving 301/302
- [DONE] Transient cache (24h) with invalidation on CRUD
- [DONE] `get_redirects()`, `create_redirect()`, `delete_redirect()`
- [DONE] Scopes `redirects:read`, `redirects:write` + admin checkboxes

### Scopes v2 (8 MVP → 12 v2)
- [DONE] `media:delete`
- [DONE] `taxonomies:delete`
- [DONE] `redirects:read`
- [DONE] `redirects:write`

### Tests v2
- [DONE] SeoMetaTest (9 tests)
- [DONE] PostsFiltersTest (12 tests)
- [DONE] ArticleBlocksTest (6 tests)
- [DONE] TaxonomyCrudTest (11 tests)
- [DONE] MediaCrudTest (8 tests)
- [DONE] SiteEndpointsTest (6 tests)
- [DONE] RedirectsTest (11 tests)
- [DONE] Updated FormattersTest (17 fields)
- [DONE] Updated AuthTest (12 scopes)

---

## Phase 9 : Backlog items #26, #27, #29

### Vérification sideload image ACF (#26)
- [DONE] Vérifier que `sideload_image_field()` est implémenté pour les champs ACF image (confirmé dans `class-adapter-acf.php` et `trait-api-acf-fields.php`)

### Force Draft (#29)
- [DONE] Option WP `aa_force_draft` (boolean, default `false`)
- [DONE] Checkbox "Force Draft" dans la page admin (section Settings)
- [DONE] Override `status` → `draft` dans `create_post()` quand activé
- [DONE] Override `status` → `draft` dans `update_post()` quand activé
- [DONE] `force_draft_applied: true` dans les réponses POST/PUT quand override actif
- [DONE] Exposer dans `GET /site-info` → `settings.force_draft`
- [DONE] Tests unitaires Force Draft (7 tests)

### Support core/* en mode ACF (#27)
- [DONE] `is_registered('core/*')` accepte tous les blocs core/* dans le Block Registry
- [DONE] Normalisation `core/paragraph` → `paragraph` dans `process_block()` et `validate_block_recursive()`
- [DONE] Fallback `core/*` → Gutenberg adapter dans `custom_block()` de l'ACF adapter
- [DONE] Tests unitaires : registry accepte core/* (6 assertions)
- [DONE] Tests unitaires : core/paragraph et core/heading produisent le même output que paragraph/heading
- [DONE] Tests unitaires : core/* blocks ne sont pas rejetés (pas de 422)

### Specs mises à jour
- [DONE] `api-contract.md` : ajout `force_draft_applied` dans réponses POST/PUT
- [DONE] `decisions.md` : ajout sous-entrée double canal (site-info + réponse POST/PUT)

---

## Phase 10 : Backlog G1-G3 + Bugfix

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/plugin-wp-specs/backlog.md) — intégré 2026-03-06*

### Bug: `featured_image_alt` not set during sideload (P2)
- [DONE] Ajouter param `$alt = ''` à `sideload_and_set_featured_image()`
- [DONE] `update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt)` après sideload
- [DONE] Passer `$meta['featured_image_alt'] ?? ''` dans `create_post()` et `update_post()`
- [DONE] Tests unitaires alt text sideload (3 tests)

### G1 — Preview URL endpoint (P1)
- [DONE] `GET /articles/{id}/preview-url` (scope `articles:read`)
- [DONE] Token stocké en `wp_postmeta` (`_aa_preview_token` + `_aa_preview_expires`)
- [DONE] Token valide 24h, accessible sans auth
- [DONE] Hook `template_redirect` : vérifier token + rendre avec template thème
- [DONE] Cron cleanup tokens expirés
- [DONE] Tests unitaires preview URL (10 tests)

### G2 — Excerpt support POST/PUT articles (P1)
- [DONE] Accepter `excerpt` top-level dans `POST /articles`
- [DONE] Accepter `excerpt` top-level dans `PUT /articles/{id}`
- [DONE] Mapper vers `post_excerpt`
- [DONE] Retourner `excerpt` dans `GET /articles`
- [DONE] Tests unitaires excerpt (5 tests)

### G3 — Enriched response fields GET /articles (P2)
- [DONE] `excerpt` dans format_post()
- [DONE] `featured_image_url` dans format_post()
- [DONE] `featured_image_alt` dans format_post()
- [DONE] `post_type` dans format_post()
- [DONE] `author` (display name) dans format_post()
- [DONE] `categories` (noms) dans format_post()
- [DONE] `tags` (noms) dans format_post()
- [DONE] `last_modified` (ISO 8601) dans format_post()
- [DONE] `word_count` dans format_post()
- [DONE] `has_blocks` dans format_post()
- [DONE] Tests unitaires enriched fields (FormattersTest updated: 19 fields)

---

## Phase 11 : ACF Block Validation at Publish (H1)

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/plugin-wp-specs/backlog.md) — intégré 2026-03-06*
*Endpoints concernés : `POST /articles`, `PUT /articles/{id}`*

### H1.1 — ACF Schema Validation (before save)
- [DONE] Valider types de champs ACF contre le schéma enregistré (`acf_get_fields()`)
- [DONE] Vérifier champs requis présents
- [DONE] Vérifier disponibilité bloc pour le post type cible
- [DONE] Retourner 422 avec erreur structurée par champ (block_index, field, expected, got, suggestion)

### H1.2 — Image URL Auto-Sideload
- [DONE] Détecter URL string dans champ ACF `image` (au lieu d'un attachment ID)
- [DONE] Auto-sideload via `media_sideload_image()`
- [DONE] Remplacer URL par attachment ID dans les données du bloc
- [DONE] Retourner 422 si sideload échoue
- [DONE] Support format objet `{url, alt, title}` avec propagation metadata (alt text, titre)
- [DONE] Tracking sideloaded IDs + re-attach au post après `wp_insert_post`

### H1.3 — Render Test (after save, before response)
- [DONE] `render_block()` interne pour chaque bloc ACF après sauvegarde
- [DONE] Catch fatal errors / exceptions via `ob_start()` + error handler
- [DONE] Rollback post si render échoue → retourner 422
- [DONE] Si render OK → retourner 200 normalement

### Implementation details
- `Arcadia_ACF_Validator` singleton: `validate_and_preprocess()` + `render_test()`
- Integrated into `json_to_blocks()` (post_type param added)
- `sideload_image_field()` returns `WP_Error` on failure (no silent fallback)
- Render test in `create_post()` and `update_post()` after save
- 29 unit tests (AcfValidatorTest)

---

## Phase 12 : accepted_formats (I1)

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/plugin-wp-specs/backlog.md) — intégré 2026-03-07*
*Endpoint concerné : `GET /blocks`*

### I1 — `GET /blocks` : exposer `accepted_formats` sur les champs image
- [DONE] Ajouter `"accepted_formats": ["int", "url", "object"]` aux champs `image` dans `get_acf_block_fields()`
- [DONE] Test unitaire : image fields include accepted_formats, text fields don't (BlockRegistryTest)

---

## Phase 13 : ACF Bugfixes (J1-J2)

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/plugin-wp-specs/backlog.md) — intégré 2026-03-07*

### J1 — Conversion markdown → HTML sur champs wysiwyg ACF
- [DONE] Fix root cause: schema lookup in `custom_block()` passait le nom sans prefix (`bouton`) au lieu du nom complet (`acf/bouton`)
- [DONE] Appliquer conversion markdown → HTML sur les champs `wysiwyg` dans `custom_block()` (blocs ACF dans `post_content`)
- [DONE] Appliquer conversion markdown → HTML sur les champs `wysiwyg` dans `process_acf_fields()` (champs ACF top-level)
- [DONE] Ne pas affecter les champs non-wysiwyg (text, url, select)
- [DONE] Tests unitaires : AcfAdapterTest (5 tests) + AcfFieldsTest wysiwyg markdown (1 test)

### J2 — Image ACF : `get_field()` retourne int au lieu d'array
- [DONE] Même root cause que J1 : fix schema lookup restaure l'injection des paires `_fieldname` → `field_key`
- [DONE] Tests unitaires : field key references injectées (AcfAdapterTest), image field key reference (AcfAdapterTest)

---

## Phase 14 : Backlog K1-K2

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/plugin-wp-specs/backlog.md) — intégré 2026-03-07*

### K1 — Include `preview_url` in GET /articles response
- [DONE] `get_or_create_token()` sur `Arcadia_Preview` (réutilise tokens valides, évite DB writes inutiles)
- [DONE] Ajouter `preview_url` dans `format_post()` (20 champs au lieu de 19)
- [DONE] Tests unitaires : get_or_create_token (3 tests) + FormattersTest updated (20 fields)

### K2 — `search` et `id` query params sur GET /articles
- [DONE] Param `search` (string) — déjà implémenté (Phase 8, lignes 104-107 trait-api-posts.php)
- [DONE] Param `id` (int) — filtre par article ID via `WP_Query` arg `p`
- [DONE] Tests unitaires : id_filter + search_filter (PostsFiltersTest, 2 tests)

---

## Phase 15 : Bugfix Preview URL CPT (L1)

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/plugin-wp-specs/backlog.md) — intégré 2026-03-12*
*Fichiers concernés : `includes/class-preview.php`, `arcadia-agents.php`, `tests/unit/PreviewUrlTest.php`, `tests/unit/bootstrap.php`*

### L1 — Fix preview URL 404 pour CPT `article`
- [DONE] `pre_get_posts` hook dans `class-preview.php` : `fix_query_for_preview($query)` — set `post_type` + `post_status` pour previews CPT
- [DONE] Mise à jour `handle_preview()` : `status_header(200)`, `$GLOBALS['post']`, `$GLOBALS['wp_query']` fix, template hierarchy privée
- [DONE] `arcadia-agents.php` : enregistrer `pre_get_posts` hook, changer priorité `template_redirect` preview à 1
- [DONE] Tests unitaires `PreviewUrlTest.php` : fix_query_for_preview (4 tests), template hierarchy (2 tests), handle_preview status_header (1 test)
- [DONE] Bootstrap `tests/unit/bootstrap.php` : stubs `status_header()`, `validate_file()`, `locate_template()`, `WP_Query` enrichi

---

## Phase 7 : Publication

*Note : Attendre le passage en prod de l'agent SEO*

- [ ] Créer compte wordpress.org
- [ ] Préparer assets (bannière, icône, screenshots)
- [ ] Soumettre plugin pour review
- [ ] Attendre approbation (1-7 jours)
- [ ] Configurer secrets GitHub (WP_ORG_USERNAME, WP_ORG_PASSWORD)
- [ ] Première release

---

## Notes

### Décisions en attente
- Rate limiting : reporté post-MVP

### Risques identifiés
- Review WP.org peut prendre du temps
- ACF Pro payant = pas tous les clients l'ont (d'où Gutenberg natif MVP)
