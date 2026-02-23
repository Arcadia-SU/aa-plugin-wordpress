# Plugin WordPress - Checklist de développement

**Dernière mise à jour :** 2026-02-03

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
