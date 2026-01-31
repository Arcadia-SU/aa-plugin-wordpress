# Plugin WordPress - Checklist de développement

**Dernière mise à jour :** 2026-01-31

---

## Phase 0 : Préparation

### Specs & Documentation
- [x] Définir architecture (agent → plugin REST API)
- [x] Définir endpoints (11 → 13 avec edit pages)
- [x] Définir auth (JWT asymétrique + scopes)
- [x] Définir format JSON sémantique
- [x] Définir mapping vers ACF Blocks
- [x] Définir mapping vers Gutenberg natif
- [x] Documenter flow de connexion (Connection Key + handshake)
- [x] Finaliser JSON schema (avec featured_image_url)

### Environnement
- [x] Setup Docker (docker-compose.yml)
- [x] Créer site WP local de test
- [x] Plugin activé sur le site de test
- [ ] Installer ACF Pro sur le site de test (optionnel - Gutenberg natif d'abord)

---

## Phase 1 : Structure du plugin

### Setup repo
- [x] Créer repo GitHub (`Arcadia-SU/aa-plugin-wordpress`)
- [x] Créer structure de fichiers de base
- [x] Setup composer.json
- [x] Setup phpcs.xml (WordPress Coding Standards)
- [x] Créer readme.txt (format WP.org)

### Fichiers de base
- [x] `arcadia-agents.php` - Point d'entrée + métadonnées
- [x] `includes/class-api.php` - Enregistrement endpoints REST
- [x] `includes/class-auth.php` - Validation JWT
- [x] `includes/class-blocks.php` - Génération blocs Gutenberg/ACF
- [x] `admin/settings.php` - Page admin WP (structure de base)

---

## Phase 2 : Authentification

### Page admin (UI)
- [x] Champ Connection Key
- [x] Afficher état connexion (vert/rouge)
- [x] Checkboxes scopes
- [x] Bouton "Test connection"

### Backend auth
- [x] Implémenter validation JWT (RS256)
- [x] Stocker public key dans options WP
- [x] Implémenter vérification des scopes
- [x] Implémenter réponses d'erreur structurées
- [x] Implémenter handshake avec ArcadiaAgents
- [x] Sauvegarder les scopes sélectionnés

---

## Phase 3 : Endpoints REST

### Articles
- [x] `POST /arcadia/v1/posts` - Créer article
- [x] `GET /arcadia/v1/posts` - Lister articles
- [x] `PUT /arcadia/v1/posts/{id}` - Modifier article
- [x] `DELETE /arcadia/v1/posts/{id}` - Supprimer article

### Pages
- [x] `GET /arcadia/v1/pages` - Lister pages
- [x] `PUT /arcadia/v1/pages/{id}` - Modifier page

### Médias
- [x] `POST /arcadia/v1/media` - Upload via URL (sideload)
- [x] `PUT /arcadia/v1/posts/{id}/featured-image` - Définir image à la une

### Taxonomies
- [x] `GET /arcadia/v1/categories` - Lister catégories
- [x] `GET /arcadia/v1/tags` - Lister tags
- [x] `POST /arcadia/v1/categories` - Créer catégorie

### Site
- [x] `GET /arcadia/v1/health` - Health check (sans auth)
- [x] `GET /arcadia/v1/site-info` - Infos site

---

## Phase 4 : Génération de blocs

### Infrastructure
- [x] Créer interface `BlockAdapter`
- [x] Implémenter détection automatique du mode (ACF vs Gutenberg)
- [x] Implémenter parsing markdown → HTML (liens)

### Gutenberg Adapter (MVP)
- [x] Mapper `heading` → `wp:heading`
- [x] Mapper `paragraph` → `wp:paragraph`
- [x] Mapper `image` → `wp:image`
- [x] Mapper `list` → `wp:list`

### ACF Adapter (MVP)
- [x] Mapper `heading` → `acf/title`
- [x] Mapper `paragraph` → `acf/text`
- [x] Mapper `image` → `acf/image`
- [x] Mapper `list` → `acf/text` (avec ul/ol)

---

## Phase 5 : Tests

- [ ] Setup PHPUnit
- [ ] Tests unitaires : validation JWT
- [ ] Tests unitaires : parsing JSON → blocs
- [ ] Tests unitaires : markdown → HTML
- [ ] Tests intégration : endpoints REST
- [x] Test manuel : créer article complet via API
- [ ] Test manuel : site client (ACF Pro)

---

## Phase 6 : CI/CD

- [x] GitHub Action : lint (PHPCS)
- [x] GitHub Action : tests (PHPUnit) - placeholder, tests à implémenter
- [x] GitHub Action : deploy WP.org (10up/action-wordpress-plugin-deploy)

---

## Phase 7 : Publication

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
