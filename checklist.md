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
- [ ] Créer repo GitHub `arcadia-wordpress-plugin`
- [x] Créer structure de fichiers de base
- [ ] Setup composer.json
- [ ] Setup phpcs.xml (WordPress Coding Standards)
- [ ] Créer readme.txt (format WP.org)

### Fichiers de base
- [x] `arcadia-agents.php` - Point d'entrée + métadonnées
- [ ] `includes/class-api.php` - Enregistrement endpoints REST
- [ ] `includes/class-auth.php` - Validation JWT
- [x] `admin/settings.php` - Page admin WP (structure de base)

---

## Phase 2 : Authentification

### Page admin (UI)
- [x] Champ Connection Key
- [x] Afficher état connexion (vert/rouge)
- [x] Checkboxes scopes
- [x] Bouton "Test connection"

### Backend auth
- [ ] Implémenter validation JWT (RS256)
- [ ] Stocker public key dans options WP
- [ ] Implémenter vérification des scopes
- [ ] Implémenter réponses d'erreur structurées
- [ ] Implémenter handshake avec ArcadiaAgents
- [ ] Sauvegarder les scopes sélectionnés

---

## Phase 3 : Endpoints REST

### Articles
- [ ] `POST /arcadia/v1/posts` - Créer article
- [ ] `GET /arcadia/v1/posts` - Lister articles
- [ ] `PUT /arcadia/v1/posts/{id}` - Modifier article
- [ ] `DELETE /arcadia/v1/posts/{id}` - Supprimer article

### Pages
- [ ] `GET /arcadia/v1/pages` - Lister pages
- [ ] `PUT /arcadia/v1/pages/{id}` - Modifier page

### Médias
- [ ] `POST /arcadia/v1/media` - Upload via URL (sideload)
- [ ] `PUT /arcadia/v1/posts/{id}/featured-image` - Définir image à la une

### Taxonomies
- [ ] `GET /arcadia/v1/categories` - Lister catégories
- [ ] `GET /arcadia/v1/tags` - Lister tags
- [ ] `POST /arcadia/v1/categories` - Créer catégorie

### Site
- [x] `GET /arcadia/v1/health` - Health check (sans auth)
- [ ] `GET /arcadia/v1/site-info` - Infos site

---

## Phase 4 : Génération de blocs

### Infrastructure
- [ ] Créer interface `BlockAdapter`
- [ ] Implémenter détection automatique du mode (ACF vs Gutenberg)
- [ ] Implémenter parsing markdown → HTML (liens)

### Gutenberg Adapter (MVP)
- [ ] Mapper `heading` → `wp:heading`
- [ ] Mapper `paragraph` → `wp:paragraph`
- [ ] Mapper `image` → `wp:image`
- [ ] Mapper `list` → `wp:list`

### ACF Adapter (MVP)
- [ ] Mapper `heading` → `acf/title`
- [ ] Mapper `paragraph` → `acf/text`
- [ ] Mapper `image` → `acf/image`
- [ ] Mapper `list` → `acf/text` (avec ul/ol)

---

## Phase 5 : Tests

- [ ] Setup PHPUnit
- [ ] Tests unitaires : validation JWT
- [ ] Tests unitaires : parsing JSON → blocs
- [ ] Tests unitaires : markdown → HTML
- [ ] Tests intégration : endpoints REST
- [ ] Test manuel : créer article complet via API
- [ ] Test manuel : site client (ACF Pro)

---

## Phase 6 : CI/CD

- [ ] GitHub Action : lint (PHPCS)
- [ ] GitHub Action : tests (PHPUnit)
- [ ] GitHub Action : deploy WP.org (10up/action-wordpress-plugin-deploy)

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
