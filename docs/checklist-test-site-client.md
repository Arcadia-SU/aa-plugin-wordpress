# Test manuel : Site client (ACF Pro)

**Objectif :** Valider que le plugin fonctionne sur un vrai site WordPress avec ACF Pro et des blocs custom.
**Site :** iselection.com (PHP 8.3, WP 6.8.3, ACF Pro, thème custom)

---

## Phase A : Installation du plugin

- [DONE] Packager le plugin (zip du dossier `arcadia-agents/`)
- [DONE] Uploader sur le site client via WP Admin > Extensions > Ajouter
- [DONE] Activer le plugin
- [DONE] Vérifier que `GET /wp-json/arcadia/v1/health` répond 200

---

## Phase B : Setup auth (clés JWT)

- [DONE] Générer une paire de clés RSA (private + public)
- [DONE] Configurer la public key dans WP Admin > Réglages > Arcadia Agents (via Manual Setup)
- [DONE] Activer tous les scopes (pour le test)
- [DONE] Générer un JWT de test avec la private key
- [DONE] Vérifier que `GET /site-info` répond 200 avec le JWT

---

## Phase C : Tests API

### C1. Détection adapter
- [DONE] `GET /site-info` → `plugin.adapter` = `"acf"`

### C2. Découverte des blocs
- [DONE] `GET /blocks` → `adapter` = `"acf"`
- [DONE] `blocks.builtin` contient les 4 types MVP (paragraph, heading, image, list)
- [DONE] `blocks.custom` contient 28 blocs ACF du thème (alert, button, faq, text-image, etc.)
- [DONE] Chaque bloc custom a ses `fields` avec name, type, required

### C3. Création article — blocs builtin
- [DONE] `POST /posts` avec headings + paragraphs + list → HTTP 201
- [DONE] Le contenu contient des blocs `acf/text`, `acf/title` (markdown bold/italic rendu OK)

### C4. Création article — bloc custom
- [DONE] `POST /posts` avec bloc `alert` (color=green, title, text) → HTTP 201
- [DONE] Le contenu contient le bloc `acf/alert` avec les bonnes données

### C5. Fail fast — bloc inconnu
- [DONE] `POST /posts` avec un bloc de type `bloc-inexistant-xyz` → HTTP 422
- [DONE] Réponse contient `code: "unknown_block_type"` + liste des blocs disponibles
- [DONE] Aucun article n'a été créé

### C6. Fail fast — champ requis manquant
- [N/A] Aucun bloc custom du thème iSelection n'a de champ `required: true`
- [N/A] Test non-applicable sur ce site — la validation fonctionne (testé en unit tests)

---

## Phase D : Validation visuelle

- [DONE] Créer article avec `meta.post_type: "article"` contenant : H2, 2 paragraphes avec liens + bold + italic, H3, liste, bloc alert custom
- [DONE] Tous les blocs apparaissent comme blocs ACF (Titre, Texte riche, Message d'alerte) — aucun bloc "Classique"
- [DONE] Les liens sont présents et fonctionnels dans les paragraphes
- [DONE] Bold, italic, apostrophes rendus correctement
- [DONE] La liste a 3 items avec formatage
- [DONE] Le bloc alert a la bonne couleur et le bon titre

---

## Phase E : Nettoyage

- [DONE] Supprimer les articles de test créés (mis en corbeille)
- [DONE] Documenter les résultats

---

## Résumé

| Test | Résultat | Notes |
|------|----------|-------|
| A. Installation | OK | PHP 8.3, WP 6.8.3 |
| B. Auth JWT | OK | Via Manual Setup (public key PEM) |
| C1. Adapter ACF | OK | `adapter: "acf"` |
| C2. Blocs discovery | OK | 4 builtin + 28 custom |
| C3. POST builtin | OK | acf/title, acf/text avec markdown |
| C4. POST custom | OK | acf/alert avec properties |
| C5. Bloc inconnu | OK | 422 + unknown_block_type |
| C6. Champ requis | N/A | Aucun champ required sur ce thème |
| D. Visuel | OK | Blocs ACF corrects, liens/bold/italic OK |
| E. Nettoyage | OK | Articles test supprimés |

## Bugs corrigés pendant les tests

1. **`author: 0`** — Pas d'auteur assigné aux articles créés via API. Fix : `resolve_author()` (email/login lookup + fallback premier admin).
2. **Bloc "Classique"** — `wp_filter_post_kses` encodait les blocs HTML quand pas de current user. Fix : `wp_set_current_user()` avant insert/update.
3. **Paragraphes avec liens vides** — `wp_unslash()` dans `wp_insert_post()` cassait les `\"` du JSON des blocs ACF contenant des `<a href>`. Fix : `wp_slash($post_data)` avant insert/update (pattern standard WP core).
4. **DELETE ne supporte pas les CPT custom** — `delete_post()` vérifie `post_type === 'post'`. À corriger.

## Découvertes terrain

- Le site utilise le CPT `article` (104 publiés), pas le type natif `post` (0)
- 7 post types publics au total
- Les options WP (connexion, clés) persistent quand on réinstalle le plugin
