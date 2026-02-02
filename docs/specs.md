# Plugin WordPress - Specifications

**Status:** MVP développé ✅
**Repo:** [github.com/Arcadia-SU/aa-plugin-wordpress](https://github.com/Arcadia-SU/aa-plugin-wordpress)
**Lié à:** [PRD Agent SEO](./prd.md)

---

## 1. Communication Agent → Plugin

- **Direction:** ✅ Agent initie toujours (push vers WordPress)
  - Rationale : L'agent orchestre, le plugin exécute. Pas besoin que WP initie.

- **Protocole:** ✅ REST API exposée par le plugin WordPress
  - Rationale : WP déjà sur internet, standard WP (écosystème, debugging), stateless. Sécurité gérée via endpoints custom + auth robuste.

- **Capacités requises:** ✅ 13 endpoints validés
  - Articles :
    - `POST /posts` - create_post(title, content, status, meta) → post_id
    - `GET /posts` - get_posts(filters?) → list
    - `PUT /posts/{id}` - update_post(post_id, fields) → success
    - `DELETE /posts/{id}` - delete_post(post_id) → success
  - Pages :
    - `GET /pages` - get_pages() → list (pour internal linking)
    - `PUT /pages/{id}` - update_page(page_id, fields) → success
  - Médias :
    - `POST /media` - upload_media(file, meta) → media_id
    - `PUT /posts/{id}/featured-image` - set_featured_image(post_id, media_id) → success
  - Taxonomies :
    - `GET /categories` - get_categories() → list
    - `GET /tags` - get_tags() → list
    - `POST /categories` - create_category(name, parent?) → cat_id
  - Structure site :
    - `GET /site-info` - get_site_info() → url, name, theme, etc.

---

## 2. Authentification & Sécurité

- **Stockage credentials:** ✅ Dans ArcadiaAgents (avec les autres intégrations)
  - Rationale : Cohérence avec autres intégrations, gestion centralisée

- **Méthode d'auth:** ✅ JWT signé par ArcadiaAgents (asymétrique RS256)
  - Rationale : Sécurisé (signature crypto), expiration auto (pas de rotation manuelle), supporte les scopes dans les claims. Simplifie la vie au client.

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

### API Contract : Handshake Endpoint (côté serveur ArcadiaAgents)

**Endpoint :** `POST https://api.arcadiaagents.com/v1/wordpress/handshake`

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
    "scopes": ["posts:read", "posts:write", ...]
  }
  ```
- Durée : 15-30 min recommandé

- **Scopes (permissions granulaires):** ✅ Par ressource+action
  - Rationale : Granulaire mais pas explosif (~8 scopes), standard industrie (GitHub, Slack, Google)
  - Liste des scopes :
    - `posts:read` - Lire les articles
    - `posts:write` - Créer/modifier articles
    - `posts:delete` - Supprimer articles
    - `media:read` - Lire la media library
    - `media:write` - Upload images
    - `taxonomies:read` - Lire catégories/tags
    - `taxonomies:write` - Créer catégories/tags
    - `site:read` - Lire infos site + pages
  - Interface WP admin : checkboxes pour activer/désactiver chaque scope
  - Erreur scope manquant : réponse JSON structurée
    ```json
    {
      "error": "scope_denied",
      "required_scope": "posts:delete",
      "message": "This action requires the 'posts:delete' scope which is not enabled in WordPress settings."
    }
    ```
  - L'agent interprète et explique à l'user en langage naturel

---

## 3. Scope du Plugin

- **MVP:** ✅ Plugin minimal (API only), pas d'interface admin complexe
  - Rationale : Simplicité, le dashboard est dans ArcadiaAgents

- **Interface admin minimale:** ✅ Minimaliste, pas de logs
  - État connexion (vert/rouge + timestamp dernière activité)
  - Checkboxes scopes (8 permissions)
  - Bouton "Test connection"
  - Rationale : L'admin WP a juste besoin de savoir si ça marche. Debug/logs vivent dans ArcadiaAgents.

---

## 4. Médias & Images

- **Support médias:** ✅ L'agent génère des images et doit pouvoir les publier

- **Détails d'implémentation:** ✅ Via URL (sideload)
  - Format : L'agent upload l'image sur storage ArcadiaAgents, envoie l'URL au plugin
  - Plugin utilise `media_sideload_image()` pour importer dans WP media library
  - Featured image : paramètre explicite dans `create_post()` ou via endpoint dédié
  - Rationale : Évite base64 (lourd) et multipart (complexe). URL = simple, standard, debuggable.

---

## 5. Blocs de contenu (ACF Blocks)

**Contexte :** Demande client, obligatoire. L'agent doit générer du contenu compatible avec le thème WP existant.

### Découverte : ACF Blocks (pas Gutenberg natif)

**Analyse du site client (2026-01-30) :**
- Le client utilise **ACF Pro** avec des **ACF Blocks** (blocs Gutenberg custom définis via ACF)
- L'éditeur Gutenberg classique apparaît vide car le contenu est stocké dans des blocs ACF
- Le thème définit ~30 blocs custom (export ACF analysé)

**Blocs ACF disponibles (pertinents pour articles) :**
| Bloc ACF | ID technique | Usage |
|----------|--------------|-------|
| Texte riche | `acf/text` | Paragraphes (wysiwyg) |
| Titre | `acf/title` | Headings H2/H3 |
| Texte + image | `acf/text-image` | Paragraphe avec image |
| Image | `acf/image` | Image seule |
| Liste numérique | `acf/liste-numerique` | Listes ordonnées |
| Citation | `acf/citation` | Quotes |
| FAQ | `acf/faq` | Questions/réponses |
| Message d'alerte | `acf/message-alerte` | Encadrés/callouts |
| Bouton | `acf/bouton` | CTA |
| Tableau | `acf/tableau` | Tables |

**Impact :** Le plugin génère des ACF Blocks, pas des blocs Gutenberg natifs.

---

### Q1. Format de sortie de l'agent ✅ JSON hiérarchique (sémantique)

L'agent génère un JSON structuré, le plugin mappe vers ACF Blocks.

**Schema JSON (ADR-013 - Unified Block Model) :**

Modèle unifié inspiré de Notion/Slate.js/ProseMirror :
- Tout est un **block** avec `type`
- Nesting via **`children`** (pas `items`, `content`, `subsections`)
- Pas de strings nus - même les items de liste sont des blocks `text`

```json
{
  "meta": {
    "title": "Titre SEO (balise <title>)",
    "slug": "mon-article-seo",
    "description": "Meta description pour SEO...",
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
- Pas d'`intro`/`conclusion` spéciaux - juste des sections avec `heading: null`
- **Tout dans `children`** - sections H3 sont des blocks dans les children de H2
- Profondeur max : **H3** (pas de H4, enforced par le domain model)
- Structure uniforme et extensible (ajout futur de `image`, `table`, `cta`...)

**Formatage inline (markdown) :**

Le champ `content` des blocks texte accepte un subset de markdown :

| Syntaxe | Rendu |
|---------|-------|
| `**bold**` | **gras** |
| `*italic*` | *italique* |
| `[texte](url)` | lien hypertexte |
| `` `code` `` | code inline |

**Exemple :**
```json
{"type": "paragraph", "content": "Pour les **freelances** et [indépendants](https://...), la priorité est simple."}
```

**Pourquoi markdown et pas des nodes structurés ?**

Notion/Slate/ProseMirror fragmentent le texte en nodes avec marks (voir ADR-013). Ce pattern est conçu pour les **éditeurs interactifs** (sélection + clic "Bold"). Notre pipeline est différent :
- Le LLM génère naturellement du markdown
- On n'édite pas interactivement le texte
- WordPress convertit markdown → HTML avec des libs standard

→ Le plugin parse le markdown et génère le HTML approprié (`<strong>`, `<a href>`, etc.)

---

### Q2-Q4. Mapping JSON → ACF Blocks ✅

**Mapping MVP :**
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

**Q2. Découverte des blocs** ✅ Non nécessaire
- Les blocs ACF sont fixes, définis par le thème client
- Le plugin connaît le mapping hardcodé
- Pas besoin d'endpoint de découverte dynamique

**Q3. Qui choisit le mapping** ✅ Le plugin (hardcodé)
- Le plugin fait le mapping JSON → ACF Block
- Pas de choix utilisateur nécessaire pour MVP
- Future : interface admin pour override si besoin

**Q4. Blocs custom du thème** ✅ Supportés via mapping
- Les blocs ACF du client SONT les blocs custom du thème
- Le mapping ci-dessus les utilise directement

---

### Q5. Validation ✅ Best effort + warning

- Le plugin génère les blocs ACF et insère dans le post
- Si un type JSON n'a pas de mapping → fallback vers `acf/text`
- Log warning dans ArcadiaAgents, pas d'erreur bloquante
- Rationale : mieux vaut publier imparfait que bloquer

---

### Q6-Q7. Blocs dynamiques et zones éditables ✅ Non applicable

- **Q6.** Blocs dynamiques : Non utilisés. On génère des blocs statiques avec contenu.
- **Q7.** Zones éditables : Non applicable avec ACF Blocks. Chaque bloc = une unité autonome.

---

### Résumé Q1-Q7

| Question | Statut | Décision |
|----------|--------|----------|
| Q1 Format | ✅ | JSON hiérarchique |
| Q2 Découverte | ✅ | Non nécessaire (mapping hardcodé) |
| Q3 Choix template | ✅ | Plugin décide (mapping) |
| Q4 Blocs custom | ✅ | Supportés via ACF Blocks |
| Q5 Validation | ✅ | Best effort + warning |
| Q6 Blocs dynamiques | ✅ | Non utilisés |
| Q7 Zones éditables | ✅ | Non applicable |

---

## 6. Compatibilité multi-builders

### Architecture à adaptateurs

Le JSON sémantique de l'agent est agnostique du format cible. Le plugin utilise des **adaptateurs** pour générer le format approprié selon le setup WordPress du client.

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
| **Elementor** | `_elementor_data` (post_meta) | JSON propriétaire Elementor | ❓ Futur (non prévu) |

> **Note :** Gutenberg natif est inclus dans le MVP car ACF Pro est payant et tous les clients ne l'ont pas.

### Détail des adaptateurs

**ACF Blocks (MVP)**
- Détection : présence du plugin ACF Pro
- Mapping : JSON → blocs `acf/*`
- Effort : ✅ Fait

**Gutenberg natif (MVP)**
- Détection : absence d'ACF ou config explicite
- Mapping direct, blocs plus simples :
  | JSON | Bloc natif |
  |------|------------|
  | `paragraph` | `wp:paragraph` |
  | `heading` | `wp:heading {"level": 2}` |
  | `image` | `wp:image` |
  | `list` | `wp:list` |
- Effort : Faible (blocs natifs bien documentés)

**Elementor (Future, si demande)**
- Stockage différent : `_elementor_data` dans post_meta, pas `post_content`
- Format : JSON propriétaire avec widgets Elementor
- Détection : présence du plugin Elementor
- Effort : **Significatif** (reverse-engineering du format, pas d'API publique documentée)

**Autres page builders**
| Builder | Format | Effort estimé |
|---------|--------|---------------|
| WPBakery | Shortcodes propriétaires | Moyen |
| Divi | JSON dans post_meta | Significatif |
| Beaver Builder | JSON propriétaire | Significatif |

### Détection automatique

Le plugin détecte le mode au démarrage :
1. ACF Pro actif + blocs ACF enregistrés → Mode ACF
2. Elementor actif → Mode Elementor (si supporté)
3. Sinon → Mode Gutenberg natif

**Override possible** via config admin si le client veut forcer un mode.

### Roadmap

| Phase | Scope | Effort |
|-------|-------|--------|
| **MVP** | ACF Blocks + Gutenberg natif | ✅ |
| **Future** | + Elementor (si demande client) | Significatif |

---

## 7. Décisions validées (historique)

- **2026-01-27 | Direction communication**
  - Quoi : Agent → Plugin (push)
  - Pourquoi : L'agent orchestre, le plugin exécute. Pas besoin que WP initie.

- **2026-01-27 | Stockage credentials**
  - Quoi : Dans ArcadiaAgents
  - Pourquoi : Cohérence avec autres intégrations, gestion centralisée

- **2026-01-27 | Scope MVP**
  - Quoi : API only, admin minimal
  - Pourquoi : Simplicité, le dashboard est dans ArcadiaAgents

- **2026-01-27 | Support médias**
  - Quoi : Oui, images générées
  - Pourquoi : L'agent génère des images pour les articles

- **2026-01-27 | Protocole**
  - Quoi : REST API (plugin expose endpoints)
  - Pourquoi : Standard WP, stateless, debuggable. Sécurité via endpoints custom + auth

- **2026-01-27 | Capacités**
  - Quoi : 11 endpoints (CRUD posts, media, taxonomies, site info)
  - Pourquoi : Couvre tous les besoins agent : publish, update, images, linking, discovery

- **2026-01-27 | Méthode d'auth**
  - Quoi : JWT signé par ArcadiaAgents
  - Pourquoi : Sécurisé (crypto), expiration auto, supporte scopes. Simplifie la vie client (pas de rotation manuelle).

- **2026-01-27 | Scopes**
  - Quoi : 8 scopes par ressource+action (posts, media, taxonomies, site × read/write/delete)
  - Comment : Checkboxes dans WP admin, erreur JSON structurée si scope manquant
  - Pourquoi : Granulaire, standard industrie, permet à l'agent d'expliquer les erreurs à l'user

- **2026-01-30 | Interface admin minimale**
  - Quoi : État connexion + checkboxes scopes + bouton test. Pas de logs.
  - Pourquoi : L'admin WP a juste besoin de savoir si ça marche. Debug/logs dans ArcadiaAgents.

- **2026-01-30 | Upload médias**
  - Quoi : Via URL (sideload). Agent upload sur storage AA, envoie URL, plugin fait media_sideload_image()
  - Pourquoi : Simple, standard, debuggable. Évite base64 (lourd) et multipart (complexe).

- **2026-01-30 | Format JSON hiérarchique (Gutenberg Q1)**
  - Quoi : JSON structuré hiérarchique (meta + h1 + sections[H2 → subsections[H3 → content]])
  - Structure : sections/subsections avec heading optionnel (null = pas de titre)
  - Profondeur max : H3 (pas de H4), title ≠ h1
  - Pas d'intro/conclusion spéciaux : juste des sections avec heading: null
  - Types MVP : paragraph, image, list
  - Extensible : cta, table, faq, quote prévus post-MVP
  - Limitations : colonnes/layouts = modification manuelle (rare, accepté)
  - Pourquoi :
    - Hiérarchique car workflow déterministe avec structure connue
    - Le moins opinionated possible (structure uniforme)
    - Listicles rentrent bien
    - Agent agnostique WP (JSON sémantique → plugin mappe vers Gutenberg)
    - Validation facile (structure explicite vs parsing linéaire)
    - Alternatives rejetées : linéaire (complexité déplacée vers code), HTML brut (perd richesse Gutenberg)

- **2026-01-30 | Links inline en markdown**
  - Quoi : Format `[texte](url)` dans le texte des paragraphes
  - Plugin parse markdown → HTML
  - Pourquoi :
    - Léger et lisible
    - Standard (markdown)
    - Plugin contrôle la conversion (sécurité vs HTML brut)
    - Alternatives rejetées : HTML brut (mélange formats, XSS théorique), structured positions (verbeux, fragile)

- **2026-02-02 | Formatage inline = markdown (pas nodes structurés)**
  - Quoi : `content` accepte markdown inline (`**bold**`, `*italic*`, `[link](url)`, `` `code` ``)
  - Alternative rejetée : nodes fragmentés avec marks (style Notion/Slate/ProseMirror)
  - Pourquoi :
    - Le pattern nodes+marks est conçu pour les **éditeurs interactifs** (sélection + clic "Bold")
    - Notre use case est un **pipeline de génération** : LLM génère → JSON stocke → WordPress affiche
    - Le LLM génère naturellement du markdown, pas des structures fragmentées
    - Complexité inutile : parser markdown → nodes → HTML vs simple markdown → HTML
    - JSON 10x plus compact et lisible
  - Voir ADR-013 pour le détail du rationale

- **2026-01-30 | Découverte ACF Blocks (Q2-Q7)**
  - Quoi : Le client utilise ACF Pro avec ACF Blocks, pas Gutenberg natif
  - Impact : Plugin génère des ACF Blocks (`acf/text`, `acf/title`, etc.)
  - Mapping hardcodé JSON → ACF Block
  - Q2-Q7 résolues :
    - Découverte : non nécessaire (blocs fixes)
    - Choix template : plugin décide (mapping)
    - Blocs custom : supportés (ACF Blocks = blocs custom)
    - Validation : best effort + warning
    - Blocs dynamiques : non utilisés
    - Zones éditables : non applicable
  - Pourquoi : Analyse de l'export ACF du client (acf-export-2026-01-30.json)

- **2026-01-30 | Architecture multi-builders**
  - Quoi : Plugin avec adaptateurs pour différents formats WordPress
  - Modes : ACF Blocks (MVP) → Gutenberg natif (V1.1) → Elementor (future, si demande)
  - Détection automatique du mode selon plugins actifs
  - Pourquoi :
    - JSON sémantique permet cette flexibilité
    - Gutenberg natif = faible effort (blocs standards)
    - Elementor = effort significatif (format propriétaire, pas d'API publique)
    - Autres builders (WPBakery, Divi) = chacun son format propriétaire

- **2026-01-31 | Flow de connexion**
  - Quoi : Connection Key + handshake pour échanger la public key RSA
  - MVP sans frontend AA : génération manuelle de la Connection Key (CLI/DB)
  - Pourquoi : Sécurisé (asymétrique), simple pour l'utilisateur (copier-coller une clé)

- **2026-01-31 | Gutenberg natif MVP**
  - Quoi : Gutenberg natif inclus dans le MVP (pas V1.1)
  - Pourquoi : ACF Pro est payant, pas tous les clients l'ont

- **2026-01-31 | Featured image dans meta**
  - Quoi : `meta.featured_image_url` dans le JSON schema
  - Plugin fait sideload de l'image et la définit comme featured image
  - Pourquoi : Simplifie le flow (pas besoin d'appel séparé)

- **2026-01-31 | Edit pages**
  - Quoi : Ajout endpoint `PUT /pages/{id}` pour modifier les pages existantes
  - Pourquoi : Nécessaire pour l'internal linking et l'optimisation des pages commerciales

---

## 8. Développement & Publication du Plugin

### Modèle business

**Gratuit.** Le plugin est un connecteur. Ce sont les agents ArcadiaAgents qui sont payants.

### Repo dédié

```
github.com/Arcadia-SU/aa-plugin-wordpress
├── arcadia-agents/              ← Le plugin lui-même
│   ├── arcadia-agents.php       ← Point d'entrée (métadonnées + hooks)
│   ├── includes/
│   │   ├── class-api.php        ← Endpoints REST
│   │   ├── class-auth.php       ← JWT validation
│   │   └── class-blocks.php     ← Génération ACF Blocks
│   ├── admin/
│   │   └── settings.php         ← Page admin WP
│   └── readme.txt               ← OBLIGATOIRE pour WP.org
├── tests/                       ← PHPUnit tests
├── .github/
│   └── workflows/
│       ├── test.yml             ← CI: lint + tests
│       └── release.yml          ← CD: build + deploy WP.org
├── composer.json                ← Dépendances PHP
├── phpcs.xml                    ← WordPress coding standards
└── README.md                    ← GitHub (différent de readme.txt)
```

### Soumission WordPress.org

**Première fois (manuel) :**
1. Créer un compte wordpress.org
2. Soumettre le plugin via https://wordpress.org/plugins/developers/add/
3. Review manuelle (1-7 jours) - vérification sécurité + guidelines
4. Si approuvé → accès SVN (oui, SVN, pas Git)

**Après approbation :**
```
https://plugins.svn.wordpress.org/arcadia-agents/
├── /trunk/      ← Code actuel
├── /tags/1.0.0/ ← Versions taguées
└── /assets/     ← Screenshots, bannière, icône
```

### Workflow de release

**Dev quotidien (GitHub) :**
1. Dev sur branch `feature/*`
2. PR → `main`
3. Tests CI passent
4. Merge

**Release (GitHub → WP.org) :**
1. Bump version dans `arcadia-agents.php` + `readme.txt`
2. Create GitHub release (tag `v1.2.0`)
3. GitHub Action pousse automatiquement vers SVN WP.org
4. Users reçoivent la mise à jour dans leur WP admin

**GitHub Action pour deploy WP.org :**
```yaml
# .github/workflows/release.yml
name: Deploy to WordPress.org
on:
  release:
    types: [published]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: 10up/action-wordpress-plugin-deploy@stable
        env:
          SVN_USERNAME: ${{ secrets.WP_ORG_USERNAME }}
          SVN_PASSWORD: ${{ secrets.WP_ORG_PASSWORD }}
          SLUG: arcadia-agents
```

### Standards WP.org (obligatoires)

Pour être accepté sur la marketplace :

- ✅ **GPL compatible** - Licence open source obligatoire
- ✅ **WordPress Coding Standards** - PHPCS vérifie automatiquement
- ✅ **Pas de code obfusqué** - Tout doit être lisible
- ✅ **Pas de tracking sans consentement** - RGPD
- ✅ **readme.txt bien formaté** - Changelog, FAQ, screenshots
- ✅ **Préfixer tout** - Fonctions, classes, options → `arcadia_*`
- ✅ **Sanitize/escape** - Toutes les données (sécurité critique)

### Environnement de développement

**Option recommandée : Local by Flywheel**
- GUI simple, gratuit : https://localwp.com/
- Crée un site WP local en 2 clics
- Hot reload : modifie le code → refresh → test

**Alternative : Docker**
```bash
docker run -d -p 8080:80 wordpress
```

### Parcours complet

```
Phase 1 : Setup
├── Créer repo GitHub arcadia-wordpress-plugin
├── Setup environnement local (Local by Flywheel)
└── Développer le plugin MVP

Phase 2 : Publication
├── Soumettre à WP.org
├── Attendre review (1-7 jours)
└── Setup CI/CD GitHub → WP.org

Phase 3 : Maintenance
├── Releases via GitHub tags
├── Updates automatiques pour les users
└── Support via GitHub issues + forum WP.org
```

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
