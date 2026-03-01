# Plugin WordPress - Specifications

**Status:** MVP développé ✅
**Repo:** [github.com/Arcadia-SU/aa-plugin-wordpress](https://github.com/Arcadia-SU/aa-plugin-wordpress)
**Lié à:** [PRD Agent SEO](./prd.md)

**Fichiers satellites :**
- [Decisions Log](./plugin-wp-decisions-log.md) — historique des décisions validées
- [Dev Guide](./plugin-wp-dev-guide.md) — repo, CI/CD, publication WP.org
- [Code Review](./plugin-wp-code-review.md) — audit v2.0.1 (33 issues)

---

## 1. Communication Agent → Plugin

- **Direction:** Agent initie toujours (push vers WordPress)
- **Protocole:** REST API exposée par le plugin WordPress
- **Capacités requises:** 14 endpoints validés
  - Articles :
    - `POST /articles` - create_article(title, content, status, meta) → article_id
    - `GET /articles` - get_articles(?post_type, ?status, ?page, ?per_page) → paginated list
    - `PUT /articles/{id}` - update_article(article_id, fields) → success
    - `DELETE /articles/{id}` - delete_article(article_id) → success
  - Pages :
    - `GET /pages` - get_pages(?status, ?page, ?per_page) → paginated list
    - `PUT /pages/{id}` - update_page(page_id, fields) → success
  - Médias :
    - `POST /media` - upload_media(file, meta) → media_id
    - `PUT /articles/{id}/featured-image` - set_featured_image(article_id, media_id) → success
  - Taxonomies :
    - `GET /categories` - get_categories() → list
    - `GET /tags` - get_tags() → list
    - `POST /categories` - create_category(name, parent?) → cat_id
  - Structure site :
    - `GET /site-info` - get_site_info() → url, name, theme, etc.
  - Blocs :
    - `GET /blocks` - get_blocks() → available block types + fields schema
    - `GET /blocks/usage` - get_blocks_usage(?post_type, ?sample_size) → block usage stats + examples from existing content

**Nommage `/articles` :** Le code agent (`WordPressSiteConnector`) utilise `/articles` comme path, pas `/posts`. Cohérent avec le vocabulaire CMS-agnostic du `SiteConnector` protocol. Le plugin traduit `article` ↔ `wp_post` en interne.

**Paramètres de query — `GET /articles` et `GET /pages` :**

| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `post_type` | string | `post` | Type de contenu WP (`GET /articles` uniquement) |
| `status` | string | tous | Filtrer par statut CMS : `publish`, `draft`, `pending` |
| `page` | int | `1` | Page de résultats |
| `per_page` | int | `20` | Items par page (max: 100) |

**Champs modifiables — `PUT /articles/{id}` et `PUT /pages/{id}` :**

| Champ | Type | Description |
|-------|------|-------------|
| `content` | string (JSON blocks) | Contenu de l'article |
| `title` | string | Titre |
| `status` | string | Statut : `publish`, `draft`, `pending` |
| `meta` | object | Métadonnées (categories, tags, author, etc.) |

Tous les champs sont optionnels — seuls les champs fournis sont modifiés.

---

## 2. Authentification & Sécurité

- **Stockage credentials:** Dans ArcadiaAgents (avec les autres intégrations)
- **Méthode d'auth:** JWT signé par ArcadiaAgents (asymétrique RS256)

### Flow de connexion (Connection Key + Handshake)

```
┌─────────────────┐                      ┌─────────────────┐
│  ArcadiaAgents  │                      │   Plugin WP     │
└────────┬────────┘                      └────────┬────────┘
         │                                        │
         │ 1. User ajoute son site WP             │
         │    → Génère Connection Key unique      │
         │                                        │
         │ 2. User copie la Key dans WP admin     │
         │                                        │
         │                    3. Plugin envoie ───┤
         │                       POST /handshake  │
         │◄───────────────────────────────────────┤
         │                                        │
         │ 4. ArcadiaAgents valide la Key         │
         │    → Retourne public key (pour JWT)    │
         │    → Marque le site comme "connecté"   │
         ├───────────────────────────────────────►│
         │                                        │
         │ 5. Plugin stocke la public key         │
         │    → Affiche "Connecté ✓"              │
         │                                        │
         │ 6. Requêtes API signées JWT            │
         │    Plugin vérifie avec public key      │
         ├───────────────────────────────────────►│
```

**Détails :**
- **Connection Key** : Token unique généré par ArcadiaAgents lors de l'ajout d'un site
- **Handshake** : Le plugin contacte ArcadiaAgents pour échanger la key contre la public key RSA
- **JWT** : Signé par ArcadiaAgents (private key), vérifié par le plugin (public key)
- **Expiration** : JWT courte durée (15-30 min)

**MVP sans frontend AA :** La Connection Key peut être générée manuellement (CLI/DB) pour le premier client.

### Fallback Header : `X-AA-Token`

Le plugin accepte le JWT depuis **deux sources**, par ordre de priorité :

1. `Authorization: Bearer <jwt>` — méthode standard
2. `X-AA-Token: Bearer <jwt>` — fallback si `Authorization` est absent (Apache/Basic Auth scenarios)

**Côté ArcadiaAgents (connector) :**
- Envoie **toujours** `X-AA-Token: Bearer <jwt>` en plus de `Authorization`
- Si Basic Auth configuré : `Authorization: Basic <credentials>` + JWT dans `X-AA-Token`

**Côté plugin (PHP) :**
```php
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($auth_header, 'Bearer ')) {
    $token = substr($auth_header, 7);
} else {
    $token = $_SERVER['HTTP_X_AA_TOKEN'] ?? '';
    if (str_starts_with($token, 'Bearer ')) {
        $token = substr($token, 7);
    }
}
```

### API Contract : Handshake Endpoint (côté serveur ArcadiaAgents)

**Endpoint :** `POST https://api.arcadia-agents.com/v1/wordpress/handshake`

**Request :**
```json
{
  "connection_key": "aa_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "site_url": "https://example.com",
  "site_name": "Mon Site WordPress",
  "plugin_version": "0.1.0"
}
```

**Response (succès 200) :**
```json
{
  "public_key": "-----BEGIN PUBLIC KEY-----\nMIIBI...\n-----END PUBLIC KEY-----"
}
```

**Response (erreur 4xx/5xx) :**
```json
{
  "message": "Connection key invalide ou expirée"
}
```

**Comportement attendu du serveur :**
1. Valider que `connection_key` existe et n'est pas déjà utilisée
2. Associer le site (`site_url`) à l'account qui a généré la key
3. Retourner la public key RSA (format PEM) pour validation JWT
4. Marquer la key comme "utilisée" (one-time use)

**Génération des JWT (pour les requêtes API) :**
- Algorithme : RS256
- Signé avec la private key correspondante
- Payload requis :
  ```json
  {
    "iss": "arcadia-agents",
    "sub": "site_id",
    "iat": 1234567890,
    "exp": 1234568790,
    "scopes": ["articles:read", "articles:write", ...]
  }
  ```
- Durée : 15-30 min recommandé

### Scopes (permissions granulaires)

8 scopes par ressource+action :

| Scope | Description |
|-------|-------------|
| `articles:read` | Lire les articles |
| `articles:write` | Créer/modifier articles |
| `articles:delete` | Supprimer articles |
| `media:read` | Lire la media library |
| `media:write` | Upload images |
| `taxonomies:read` | Lire catégories/tags |
| `taxonomies:write` | Créer catégories/tags |
| `site:read` | Lire infos site + pages |

- Interface WP admin : checkboxes pour activer/désactiver chaque scope
- Erreur scope manquant : réponse JSON structurée
  ```json
  {
    "error": "scope_denied",
    "required_scope": "articles:delete",
    "message": "This action requires the 'articles:delete' scope which is not enabled in WordPress settings."
  }
  ```

---

## 3. Scope du Plugin

- **MVP:** Plugin minimal (API only), pas d'interface admin complexe
- **Interface admin minimale:**
  - État connexion (vert/rouge + timestamp dernière activité)
  - Checkboxes scopes (8 permissions)
  - Bouton "Test connection"

---

## 4. Médias & Images

- Via URL (sideload) : l'agent upload sur storage ArcadiaAgents, envoie l'URL au plugin
- Plugin utilise `media_sideload_image()` pour importer dans WP media library
- Featured image : paramètre explicite dans `create_article()` ou via endpoint dédié

---

## 5. Blocs de contenu

### JSON Schema (ADR-013 - Unified Block Model)

Modèle unifié : tout est un **block** avec `type`, nesting via **`children`**, pas de strings nus.

```json
{
  "meta": {
    "title": "Titre SEO (balise <title>)",
    "slug": "mon-article-seo",
    "description": "Meta description pour SEO...",
    "author": "email@example.com",
    "post_type": "article",
    "featured_image_url": "https://storage.arcadiaagents.com/...",
    "featured_image_alt": "Description de l'image...",
    "categories": ["SEO"],
    "tags": ["wordpress"]
  },
  "h1": "Titre visible H1 de l'article",
  "children": [
    {
      "type": "section",
      "heading": null,
      "children": [
        {"type": "paragraph", "content": "Paragraphe d'intro avant le premier H2..."}
      ]
    },
    {
      "type": "section",
      "heading": "Titre H2",
      "level": 2,
      "children": [
        {"type": "paragraph", "content": "Texte avec [lien externe](https://example.com)..."},
        {
          "type": "list",
          "ordered": false,
          "children": [
            {"type": "text", "content": "Item 1"},
            {"type": "text", "content": "Item 2"}
          ]
        },
        {
          "type": "section",
          "heading": "Sous-titre H3",
          "level": 3,
          "children": [
            {"type": "paragraph", "content": "Contenu du H3..."},
            {"type": "image", "url": "https://...", "alt": "Description image"}
          ]
        }
      ]
    }
  ]
}
```

**Types de blocks (MVP) :**

| Type | Champs | Description |
|------|--------|-------------|
| `paragraph` | `content: string` | Paragraphe de texte |
| `text` | `content: string` | Noeud texte (pour items de liste) |
| `list` | `ordered: bool`, `children: block[]` | Liste (ordonnée ou non) |
| `image` | `url: string`, `alt: string` | Image |
| `section` | `heading: string?`, `level: 2\|3?`, `children: block[]` | Section H2/H3 |

**Types de blocks (post-MVP) :**

| Type | Champs | Description |
|------|--------|-------------|
| `quote` | `content: string` | Citation |
| `faq` | `items: [{q, a}, ...]` | Questions/réponses |
| `cta` | `text: string`, `url: string` | Call-to-action |
| `table` | `headers: []`, `rows: [[]]` | Tableau |

**Principes :**
- Pas d'`intro`/`conclusion` spéciaux — juste des sections avec `heading: null`
- Sections H3 sont des blocks dans les children de H2
- Profondeur max : **H3** (pas de H4, enforced par le domain model)

**Formatage inline (markdown) :** Le champ `content` accepte `**bold**`, `*italic*`, `[texte](url)`, `` `code` ``. Le plugin parse le markdown et génère le HTML approprié. Voir ADR-013 pour le rationale markdown vs nodes structurés.

### Mapping JSON → Blocs WordPress

**Mapping ACF (MVP) :**

| Type JSON | → ACF Block | Champ ACF |
|-----------|-------------|-----------|
| `section` (level: 2) | `acf/title` | `title` (H2) |
| `section` (level: 3) | `acf/title` | `title` (H3) |
| `paragraph` | `acf/text` | `text` (wysiwyg) |
| `text` (dans list) | N/A | Converti en `<li>` |
| `list` | `acf/text` | `text` (wysiwyg avec `<ul>`/`<ol>`) |
| `image` | `acf/image` | `image` (image field) |

**Mapping post-MVP :**

| Type JSON | → ACF Block |
|-----------|-------------|
| `quote` | `acf/citation` |
| `faq` | `acf/faq` |
| `cta` | `acf/bouton` |
| `table` | `acf/tableau` |
| `callout` | `acf/message-alerte` |

**Format technique généré par le plugin :**
```html
<!-- wp:acf/title {"name":"acf/title","data":{"title":"Mon titre H2"}} /-->
<!-- wp:acf/text {"name":"acf/text","data":{"text":"<p>Mon paragraphe...</p>"}} /-->
```

**Résumé des décisions blocs (Q1-Q7) :**

| Question | Décision |
|----------|----------|
| Q1 Format | JSON hiérarchique (ADR-013) |
| Q2 Découverte | Dynamique : `GET /blocks` (disponibilité) + `GET /blocks/usage` (exemples réels) → agent génère des descriptions |
| Q3 Choix template | Plugin décide (mapping) |
| Q4 Blocs custom | Supportés via ACF Blocks |
| Q5 Validation | Best effort + warning (fallback `acf/text`) |
| Q6 Blocs dynamiques | Non utilisés |
| Q7 Zones éditables | Non applicable |

### Custom Blocks (Q8)

Le plugin supporte des blocs custom via découverte dynamique.

**Endpoint `GET /arcadia/v1/blocks` :** Le plugin expose les blocs disponibles via introspection. Scope requis : `site:read`.

```json
{
  "adapter": "acf",
  "blocks": {
    "builtin": [
      {"type": "paragraph", "description": "Text block"},
      {"type": "heading", "description": "H2/H3 heading"},
      {"type": "image", "description": "Single image"},
      {"type": "list", "description": "Ordered/unordered list"}
    ],
    "custom": [
      {
        "type": "bouton",
        "title": "Bouton CTA",
        "fields": [
          {"name": "bouton_label", "type": "text", "required": true, "label": "Label du bouton"},
          {"name": "bouton_lien", "type": "url", "required": true, "label": "Lien"},
          {"name": "bouton_style", "type": "select", "required": false, "choices": ["primary", "secondary"]}
        ]
      }
    ]
  }
}
```

**Sources d'introspection :**
- **ACF :** `acf_get_block_types()` + `acf_get_fields()`
- **Gutenberg :** `WP_Block_Type_Registry` → attributs déclarés dans `register_block_type()`

> **Bug connu (finding 022) :** Les blocs `builtin` peuvent ne pas avoir `type`/`description` sur certains sites ACF. Le connector AA flatten les deux groupes et skip les non-dict. Le plugin devrait garantir que chaque élément est un objet avec au minimum `type`.

> **Bug connu (finding 023) :** `get_fields()` retourne `false` → PHP fatal error sur le front-end.
>
> **Root cause (diagnostiquee en prod 2026-02-24) :** Ce n'est PAS `_acf_changed`. Les deux articles (cassé et fonctionnel) ont `_acf_changed: true`. La vraie cause est triple :
> 1. **Pas de field group ACF pour le post type `article`** — seul `[PAGE] - Header` existe, assigné à `page` uniquement
> 2. **Pas d'entrées de référence ACF dans `wp_postmeta`** — les posts créés via REST API n'ont pas les paires `_field_name → field_key` qu'ACF crée lors d'un save via WP admin
> 3. **Bug thème** — `article.php:26` appelle `_get_the_title(get_fields())` avec type hint `array` strict, sans gérer `false`
>
> **Fix thème (Vertuelle) :** `$fields = get_fields(); _get_the_title(is_array($fields) ? $fields : []);`
>
> **Fix plugin (robustesse) :** Après `wp_insert_post`, appeler `do_action('acf/save_post', $post_id)` pour déclencher le mécanisme ACF et créer les entrées de référence. Ça rend nos posts compatibles avec tout thème qui appelle `get_fields()`.
>
> Le fix `_acf_changed` (issue #1) reste valide comme bonne pratique mais ne résout pas ce crash spécifique.

**Convention properties :** Les `properties` d'un bloc custom correspondent **directement** aux noms de champs/attributs du bloc cible. Pas de couche de traduction.

**Format JSON d'un bloc custom dans un article :**
```json
{
  "type": "bouton",
  "properties": {
    "bouton_label": "Contactez-nous",
    "bouton_lien": "https://example.com/contact",
    "bouton_style": "primary"
  }
}
```

**Gestion des erreurs : fail fast (HTTP 422).** Type inconnu ou champ requis manquant → requête entière rejetée.

```json
{
  "error": "unknown_block_type",
  "block_type": "bloc-inconnu",
  "message": "Block type 'bloc-inconnu' is not registered.",
  "available_custom_blocks": ["bouton", "faq", "citation"]
}
```

**Transformations de types de champs ACF :**

| Type ACF | Property reçue | Transformation plugin |
|----------|---------------|----------------------|
| `text`, `textarea`, `wysiwyg`, `url` | String | Aucune (passthrough) |
| `select`, `radio` | String | Validation contre `choices` |
| `image` | URL string | Sideload → attachment ID |
| `repeater` | Array d'objets | Aplatissement ACF (`field_0_subfield`, `field_1_subfield`...) |

**Blocs supportés :**

| Type de bloc | Supporté | Notes |
|-------------|----------|-------|
| Blocs MVP (paragraph, heading, image, list) | ✅ | Adapters existants |
| Blocs ACF custom | ✅ | Introspection dynamique |
| Blocs Gutenberg dynamiques custom (server-rendered) | ✅ | Attributs via Registry, self-closing |
| Blocs Gutenberg statiques custom (saved HTML) | ❌ Différé | Nécessite la structure HTML interne |

### Block Usage Discovery (Q10)

L'agent doit pouvoir comprendre comment les blocs sont **réellement utilisés** sur le site, pas seulement lesquels sont disponibles. Ceci lui permet de générer des descriptions riches et de prendre de meilleures décisions éditoriales.

**Motivation :** Le `GET /blocks` retourne les blocs disponibles avec des descriptions basiques. Mais pour qu'un agent comprenne vraiment à quoi sert `acf/citation` ou `acf/tableau`, il doit voir des exemples concrets d'utilisation dans le contenu existant du site.

**Endpoint `GET /arcadia/v1/blocks/usage` :** Retourne des statistiques d'usage et des exemples de blocs depuis le contenu existant. Scope requis : `site:read`.

**Paramètres de query :**

| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `post_type` | string | tous | Filtrer par type de contenu |
| `sample_size` | int | `3` | Nombre d'exemples par type de bloc (max: 10) |

**Response :**

```json
{
  "total_posts_analyzed": 47,
  "blocks": [
    {
      "type": "acf/text",
      "count": 312,
      "posts_with_block": 45,
      "examples": [
        {
          "post_id": 123,
          "post_title": "Investir en loi Pinel en 2024",
          "block_data": {
            "text": "<p>La loi Pinel permet de bénéficier d'une réduction d'impôt...</p>"
          },
          "context": {
            "parent_block": "acf/title",
            "position": 2
          }
        }
      ]
    },
    {
      "type": "acf/citation",
      "count": 8,
      "posts_with_block": 6,
      "examples": [
        {
          "post_id": 456,
          "post_title": "Témoignages clients iSelection",
          "block_data": {
            "citation_texte": "Un accompagnement exceptionnel du début à la fin.",
            "citation_auteur": "Marie D., acheteuse 2023"
          },
          "context": {
            "parent_block": "acf/title",
            "position": 5
          }
        }
      ]
    },
    {
      "type": "acf/tableau",
      "count": 3,
      "posts_with_block": 3,
      "examples": [
        {
          "post_id": 789,
          "post_title": "Comparatif dispositifs fiscaux",
          "block_data": {
            "tableau_contenu": "<table>...</table>"
          },
          "context": {
            "parent_block": "acf/title",
            "position": 3
          }
        }
      ]
    }
  ]
}
```

**Implémentation PHP :**

1. Requête `WP_Query` sur les posts publiés du `post_type` demandé
2. Pour chaque post, parser `post_content` via `parse_blocks()`
3. Compter les occurrences de chaque type de bloc
4. Collecter `sample_size` exemples par type (bloc data + contexte parent + position)
5. Retourner les stats agrégées

**Performance :** Ce endpoint peut être lent sur un site avec beaucoup de contenu. Stratégies :
- Limiter le scan aux N posts les plus récents (ex: 100)
- Cache transient WP (ex: 24h, invalidé au save_post)
- Le connector AA n'appelle ce endpoint qu'une fois lors du `discover_site`, pas à chaque article

---

### ACF Fields per Post Type (Q9)

Les thèmes CPT+ACF ne lisent pas `post_content` — ils lisent les champs ACF via `get_fields()`. Le plugin doit écrire dans les deux.

**Décision : Discovery + mapping explicite.** Le mapping est fait une fois par site après `discover_site` et stocké dans `cms_schema` côté AA. Le plugin reçoit des noms de champs ACF finaux + valeurs.

#### 1. Discovery : champs ACF dans `GET /site-info`

```json
{
  "post_types": [...],
  "acf_field_groups": [
    {
      "title": "Article Fields",
      "post_types": ["article"],
      "fields": [
        {"name": "titre_article", "type": "text", "required": true, "label": "Titre"},
        {"name": "contenu_principal", "type": "wysiwyg", "required": true, "label": "Contenu"},
        {"name": "image_hero", "type": "image", "required": false, "label": "Image hero"}
      ]
    }
  ]
}
```

**Implémentation PHP :** `acf_get_field_groups(['post_type' => $type])` + `acf_get_fields($group)` pour chaque post type.

#### 2. Payload `POST /articles` avec `acf_fields`

```json
{
  "meta": {
    "title": "SEO title",
    "description": "Meta description",
    "post_type": "article"
  },
  "h1": "Mon titre H1",
  "children": [...],
  "acf_fields": {
    "titre_article": "Mon titre visible",
    "contenu_principal": null,
    "image_hero": "https://storage.arcadiaagents.com/..."
  }
}
```

**Règles plugin :**
- Pour chaque entrée : `update_field($name, $value, $post_id)`
- **Valeur `null` sur `wysiwyg`** : le plugin copie le `post_content` rendu dans ce champ ACF
- **`post_content` est toujours écrit** (blocs Gutenberg), même si `acf_fields` est présent
- Si `acf_fields` absent ou vide : comportement inchangé (rétro-compatible)

#### 3. Mapping côté AA (dans `cms_schema`)

```json
{
  "acf_mapping": {
    "article": {
      "titre_article": "meta.title",
      "contenu_principal": "rendered_content",
      "image_hero": "meta.featured_image_url"
    }
  }
}
```

Sources possibles : `meta.title`, `meta.description`, `meta.featured_image_url`, `rendered_content` (→ `null` dans le payload), `h1`.

L'agent propose un mapping après `discover_site`. L'utilisateur valide ou ajuste. Persisté dans `cms_schema` via `update_configuration`.

---

## 6. Compatibilité multi-builders

### Architecture à adaptateurs

```
                    ┌→ [ACF Adapter]      → ACF Blocks (acf/text, acf/title...)
Agent JSON → Plugin ├→ [Gutenberg Adapter] → Blocs natifs (wp:paragraph, wp:heading...)
                    └→ [Elementor Adapter] → Widgets Elementor (JSON propriétaire)
```

### Modes supportés

| Mode | Stockage | Format généré | Statut |
|------|----------|---------------|--------|
| **ACF Blocks** | `post_content` | `<!-- wp:acf/text {...} /-->` | ✅ MVP |
| **Gutenberg natif** | `post_content` | `<!-- wp:paragraph -->...<!-- /wp:paragraph -->` | ✅ MVP |
| **Elementor** | `_elementor_data` (post_meta) | JSON propriétaire Elementor | ❓ Futur |

> Gutenberg natif inclus dans le MVP car ACF Pro est payant.

### Détail des adaptateurs

**ACF Blocks (MVP)** — Détection : présence du plugin ACF Pro. ✅ Fait.

**Gutenberg natif (MVP)** — Détection : absence d'ACF ou config explicite.

| JSON | Bloc natif |
|------|------------|
| `paragraph` | `wp:paragraph` |
| `heading` | `wp:heading {"level": 2}` |
| `image` | `wp:image` |
| `list` | `wp:list` |

**Elementor (Future, si demande)** — Effort significatif (format propriétaire, pas d'API documentée).

### Détection automatique

1. ACF Pro actif + blocs ACF enregistrés → Mode ACF
2. Elementor actif → Mode Elementor (si supporté)
3. Sinon → Mode Gutenberg natif

Override possible via config admin.

---

## 7. Décisions validées

Historique complet des décisions : **[plugin-wp-decisions-log.md](./plugin-wp-decisions-log.md)**

---

## 8. Développement & Publication

Guide complet (repo, CI/CD, WP.org, standards) : **[plugin-wp-dev-guide.md](./plugin-wp-dev-guide.md)**

---

## 9. Code Review

Audit v2.0.1 (33 issues : 1 critical, 6 high, 12 medium, 14 low) : **[plugin-wp-code-review.md](./plugin-wp-code-review.md)**

**Priorités fix :**
1. **BLOQUANT** : #1 (`do_action('acf/save_post')` — crash front-end sur tout site ACF)
2. **CRITICAL + HIGH** : #27 (test file), #4 (timing attack), #13 (image SSRF), #29 (secret visible), #24 (SEO update), #2 (secret stockage)
3. **MEDIUM** : #5 + #3 (JWT hardening), #26 (slug), #7 + #10 (validation), #12 (DRY), #14 (basename)

---

## Next Steps

1. ~~Décider protocole de communication~~ ✅
2. ~~Définir liste exhaustive des capacités~~ ✅
3. ~~Choisir méthode d'authentification~~ ✅
4. ~~Définir les scopes et leur gestion~~ ✅
5. ~~Spécifier interface admin minimale~~ ✅
6. ~~Spécifier upload médias~~ ✅
7. ~~Résoudre Q1 : Format JSON hiérarchique~~ ✅
8. ~~Résoudre Q2-Q7 : ACF Blocks mapping~~ ✅
9. ~~Documenter workflow dev & publication~~ ✅
10. ~~Créer le repo GitHub + structure du plugin~~ ✅
11. ~~Développer le plugin MVP~~ ✅
12. Tester avec WP local + site client
13. Soumettre à WP.org
14. ~~Spécifier custom blocks (Q8)~~ ✅
15. ~~Implémenter Q8 : block registry + endpoint `GET /blocks` + custom_block() adapters + fail fast validation~~ ✅
16. **Fix plugin CRITICAL + HIGH** (voir [code review](./plugin-wp-code-review.md)) — #1 ✅, #24 ✅, #2/#4 N/A (RS256), reste #27 #29 à vérifier
17. Fix plugin MEDIUM (voir [code review](./plugin-wp-code-review.md))
18. ~~Implémenter Q10 : `GET /blocks/usage` endpoint (block usage stats + examples)~~ ✅
19. ~~Implémenter Q9 : ACF fields discovery + write + fix finding 023~~ ✅
