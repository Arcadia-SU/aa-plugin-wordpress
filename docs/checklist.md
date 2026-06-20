# Plugin WordPress - Checklist de développement

**Dernière mise à jour :** 2026-06-20 (v0.1.32 ; Phases 31 hardening wp_slash + auth/uninstall fixes committées ; Phases 32-33 intégrées depuis backlog ; Phase 29 déploiement preprod + E2E toujours pending)

> **Archive :** Phases 0–26 (toutes terminées) → [`archives/checklist-phases-0-26.md`](archives/checklist-phases-0-26.md)

---

## Phases archivées (résumé)

| Phase | Sujet | Date |
|-------|-------|------|
| 0 | Préparation (specs + Docker) | 2026-01 |
| 1 | Structure plugin (repo, fichiers de base) | 2026-01 |
| 2 | Authentification JWT RS256 + admin scopes | 2026-01 |
| 3 | Endpoints REST MVP (articles, pages, médias, taxonomies, site) | 2026-01 |
| 4 | Génération blocs (Gutenberg + ACF + custom + ACF fields + block usage) | 2026-02 |
| 5 | Tests (PHPUnit + manuel ACF Pro) | 2026-02 |
| 6 | CI/CD (PHPCS, PHPUnit, deploy WP.org) | 2026-02 |
| 6b | Code review fixes (33 issues v2.0.1) | 2026-02 |
| 8 | Endpoints v2 (source tracking, filters, taxonomy CRUD, redirects, scopes v2) | 2026-02 |
| 9 | Force Draft + support `core/*` en mode ACF | 2026-03 |
| 10 | Preview URL (G1) + Excerpt (G2) + enriched format_post (G3) | 2026-03 |
| 11 | ACF block validation + image auto-sideload + render test (H1) | 2026-03 |
| 12 | `accepted_formats` sur champs image (I1) | 2026-03 |
| 13 | Markdown wysiwyg + image field key (J1-J2) | 2026-03 |
| 14 | `preview_url` dans GET /articles + `id`/`search` filters (K1-K2) | 2026-03 |
| 15 | Fix preview URL CPT 404 (L1) | 2026-03 |
| 16 | SEO meta-title separation `body.title` ≠ `meta.title` (M1) | 2026-03 |
| 17 | Field schema & calibration (FS-1→FS-4) | 2026-03 |
| 18 | Admin scope `settings:write` checkbox (aa-xs3) | 2026-03 |
| 19 | Fix preview body vide (aa-preview) | 2026-03 |
| 20 | Fix `field-schema` post_type filter (aa-xp8) | 2026-03 |
| 21 | Robustesse ACF : valeurs vides, nested repeaters, `validate-content` (N1-N3) | 2026-03 |
| 22 | Fix repeater block comment + sideload warnings (O1-O2) | 2026-03 |
| 23 | ~~Dual-write post_meta~~ REVERTED (P1) | 2026-03 |
| 24 | Repeaters flat ACF + sub-field keys + image field schema fix | 2026-03 |
| 25 | Pending Revisions system (REV-001) | 2026-04 |
| 26 | Scopes retirés du JWT — plugin = seule source de vérité | 2026-04 |

---

## Validation manuelle pending

*Items terminés en code/tests mais avec une validation manuelle restante. À valider lors du prochain accès au site client / docker dev.*

- [ ] **Phase 19 (aa-preview)** — Valider preview body sur `preprod-iselection.vertuelle.com` post ID 57824
- [ ] **Phase 21 N2** — Valider nested repeater écriture sur bloc `acf/table` (row → cols → cell) sur site client
- [ ] **Phase 24** — Valider repeater flat + sub-field keys sur post client (FAQ bloc `acf/faq` identique en structure au post 56300)
- [ ] **Phase 24 cleanup post 63657** — Identifier les meta polluées (faq, _faq, color, _color, link, _link, size, _size, block-id, _block-id, title si pollué)
- [ ] **Phase 24 cleanup post 63657** — Script one-shot ou WP-CLI pour nettoyer
- [ ] **Phase 24 cleanup post 63657** — Vérifier que `get_fields()` ne retourne plus de champs de blocs au post-level
- [ ] **Phase 25.6** — Validation manuelle Pending Revisions sur WordPress dev local (docker)

---

## Phase 27 : ACF Pro repeater flat-keys en PUT (symétrie GET/PUT)

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-05-01*
*Bug observé : push impossible sur tout article iSelection contenant un repeater (cms_post_id 20803, 2026-05-01)*
*Bead AA : `aa-iedn` (P0) — fermable après déploiement plugin*

### Contexte
Les types ACF Pro repeater (`acf/numeric-list`, `acf/faq`, `acf/pushs`, `acf/table`) sont retournés en GET au format flat-keys (`list`: 8, `list_0_text`: "...", etc.) mais le PUT exige un array de rows et rejette en 422 (`acf_validation_failed`, expected:array, got:integer). L'agent consomme directement le shape GET — forcer un reformat côté AA déplacerait la connaissance ACF Pro repeater hors du plugin.

### 27.1 — Layer d'expansion flat→array dans la pipeline ACF
- [x] Helper `has_indexed_subkeys($props, $field, $count)` : détecter si `$props` contient des keys `<field>_<n>_<sub>` pour `n ∈ [0, count)`
- [x] Helper `collapse_flat_to_rows($props, $field, $count)` : reconstruire array de rows + supprimer les flat-keys consommées
- [x] Détection structurelle pure (pas de mapping codé en dur par type) : integer count + keys numérotées = repeater à expand
- [x] Intégration en amont de la validation existante dans la pipeline `validate_block_recursive()` / `process_block()` (`includes/class-acf-validator.php` — `validate_acf_block()` appelle `expand_flat_repeaters()` avant H1.2/H1.1)
- [x] Format array de rows reste accepté (backward compat)
- [x] Bonus : strip des `_<field>` / `_<field>_<n>_<sub>` que l'agent peut renvoyer depuis GET (le adapter les ré-injecte depuis le schema)

### 27.2 — Tests unitaires
- [x] Test : `acf/numeric-list` flat-keys → expand correct (`list: 8` + `list_N_text` + `list_N_title`)
- [x] Test : `acf/faq` flat-keys → expand correct
- [x] Test : `acf/pushs` flat-keys → expand correct
- [x] Test : `acf/table` flat-keys (nested repeater) → expand récursif correct
- [x] Test : repeater synthétique → valide le pattern générique
- [x] Test : array de rows reste accepté (regression)
- [x] Test bonus : strip des field-key refs `_<field>`
- [x] Test bonus : count = 0 → array vide

### 27.3 — Validation & déploiement
- [x] `./build.sh` passe (tous les checks bloquants) — v0.1.19, 264 tests OK
- [x] Déploiement standalone sur preprod-iselection.vertuelle.com (via v0.1.20)
- [x] Validation E2E : push article iSelection cms_post_id 20803 (contenant repeater) sans 422
- [x] Fermer bead `aa-iedn` côté AA

---

## Phase 28 : Coercion canonique ACF (identity-passthrough type contract)

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-05-04*
*Bug observé : 422 `acf_validation_failed` sur `acf/text-image` (`is_lightbox`, expected:bool|int, got:string) après round-trip GET/PUT — iSelection preprod `cms_post_id=20723`, 2026-05-04*
*Bead AA : `aa-e3m1` (asymétrie `acf/button.icon` int/str) fermable par le même mécanisme*

### Contexte
ACF Pro stocke les `true_false` en `wp_postmeta` LONGTEXT → GET retourne `"1"`/`"0"` (string). AA stocke verbatim (identity-passthrough). PUT exige bool/int strict (`Arcadia_ACF_Validator::check_field_type()` lignes 607-614) → 422. Même asymétrie sur `image` (numeric strings), `number`, etc. Le pattern `expand_flat_repeaters()` (Phase 27) est exactement le pre-coercion à généraliser.

**Goal :** chaque round-trip GET → store → PUT d'un bloc `acf/*` réussit sans casting manuel côté AA. Le plugin owns le type contract end-to-end.

### 28.1 — Helper de coercion canonique
- [x] Méthode `coerce_field_to_canonical($value, $acf_field_type) → $value` dans `Arcadia_ACF_Validator`
- [x] Méthode `coerce_properties_to_canonical(&$properties, $schema)` qui walk le schema et mute en place
- [x] Coercion par type ACF :
  - `true_false` → `bool` (`"0"`/`""`/`"false"` → false ; `"1"`/`"true"` → true ; bool/int/null passthrough)
  - `image` / `file` → `int` (numeric string via `ctype_digit` → int ; `""`/`null` → 0 ; URL/object → passthrough vers H1.2 sideload)
  - `gallery` → `array<int>` (chaque élément via règle `image`)
  - `number` → `int|float` (numeric string → int si `(float)$int === $float`, sinon float ; non-numeric → laissé pour `check_field_type`)
  - `text`/`textarea`/`wysiwyg`/`url`/`email`/`select`/`radio` → `string` (cast int/float défensif ; bool/null/array laissés pour type check)
  - `repeater` → recurse dans rows via `sub_fields` (cohérent avec `expand_flat_repeaters()`)
  - `relationship` / `post_object` → `int` ou `array<int>`
  - default (link, custom) → passthrough
- [x] Si non-coercible (ex: `"banana"` pour `number`) → laissé tel quel pour que `check_field_type()` produise l'erreur claire

### 28.2 — Intégration pipeline validation
- [x] Appel `coerce_properties_to_canonical()` dans `validate_acf_block()` après `expand_flat_repeaters()` et **avant** sideload (sinon `is_string($numeric)` traiterait `"30225"` comme une URL)
- [x] `check_field_type()` reste strict — pas de relaxation
- [x] Mutation `$block['properties']` propagée (pas une copie locale)

### 28.3 — Tests unitaires (PHPUnit)
- [x] Test class `AcfCoercionTest` (15 tests)
- [x] **Per-type unit tests** : `true_false`, `image`, `file`, `gallery`, `number`, text-types, `relationship`/`post_object`, type inconnu (passthrough)
- [x] **Validator integration test** : payload `acf/text-image` iSelection (`is_lightbox: "1"`, `image: "30225"`) → validation passe + `is_lightbox === true` + `image === 30225`
- [x] **Identity round-trip sentinel** : 2 passes successives → second pass identique au premier (idempotence prouvée)
- [x] **Negative coercion test** : `"banana"` pour `number` → erreur `got: 'string'`, valeur préservée
- [x] **Test bonus** : numeric-string image skip sideload (sideload mocké à WP_Error, test passe)
- [x] **Test bonus** : coercion recurse dans rows de repeater (flat ET array-of-rows)

### 28.4 — Validation & déploiement
- [x] Tous les tests existants verts + nouveaux verts (279 tests, 928 assertions)
- [x] `./build.sh` passe — v0.1.20, zip 348KB
- [x] Déploiement preprod-iselection.vertuelle.com
- [x] Validation E2E : `python -m scripts.reingest_iselection_legacy --force` puis smoke push `cms_post_id=20723` → 200
- [x] Fermer bead `aa-e3m1` (asymétrie `acf/button.icon`) côté AA

### Notes coordination
- AA backend : patch parallèle de `_parse_error_message` (`wordpress_site_connector.py:205-226`) pour lire `errors` aussi sous `data.errors`. **Aucun changement plugin requis** — le shape émis est correct.
- **Hors scope :** sites sans ACF (gating `is_acf_available()` OK), core Gutenberg (pas d'asymétrie), migration data pré-existante (AA re-ingest via scripts dédiés).
- **À ne pas faire :** relaxer `check_field_type()`, ajouter coercion côté AA backend, modifier la sérialisation GET (self-heal au prochain round-trip une fois PUT canonique).

---

## Phase 29 : Coercion canonique côté GET (long-term cleanup)

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-05-04*
*Constat post Phase 28 : asymétrie GET/PUT — PUT auto-coerce canonique, GET retourne encore le shape ACF Pro brut (`is_lightbox: "1"` au lieu de `true`). Observé sur `cms_post_id=20723` après ré-ingestion.*
*Priorité : **non-bloquant**, pas d'urgence (le PUT round-trip self-heal). Strictement system-cleanliness.*

### Contexte
Phase 28 a fermé l'asymétrie côté PUT (validator coerce avant `check_field_type()`). Le GET émet toujours le shape brut ACF (string `"1"`/`"0"` pour `true_false`, numeric strings pour `image`, etc.). Conséquence : la DB AA ne devient jamais canonique car chaque ré-ingestion re-pollue avec les strings legacy. Fonctionnellement OK (PUT self-heal), contractuellement asymétrique pour tout consumer (AA ou futur client du plugin).

**Goal :** GET émet les mêmes types canoniques que le validator enforce au PUT — boucle fermée, identity-passthrough end-to-end sans dépendre du validator comme étape de self-heal.

### 29.1 — Application au point de sérialisation GET
- [x] Localisé : `format_parsed_blocks()` dans `trait-api-posts.php` — endpoint `GET /articles/{id}/blocks` (utilisé par AA `parse_article_block` qui lit `attrs.data` pour les blocs `acf/*`)
- [x] Réutilisation de `Arcadia_ACF_Coercer::coerce_properties_to_canonical()` (single source of truth)
- [x] Schema via `Arcadia_Block_Registry::get_block_schema()`
- [x] Décision : **changement direct** (pas de query param fence) — AA est le seul consumer connu, simplicité prime

### 29.2 — Tests unitaires
- [x] iSelection regression : `acf/text-image` (`is_lightbox: "1"`, `image: "30225"`) → bool/int canoniques
- [x] Identity round-trip : GET puis re-coerce = no-op (idempotence)
- [x] Non-ACF blocks (`core/*`) → unchanged
- [x] Unknown ACF block (pas dans registry) → passthrough sans crash
- [x] Nested ACF dans `innerBlocks` → coercion récursive
- [x] Repeater rows → coercion sub-fields
- [x] Régression : 322 tests verts (était 279 → +43 incluant les 7 nouveaux ici)

### 29.3 — Validation & déploiement
- [x] `./build.sh` passe — v0.1.25, zip 356KB
- [ ] Déploiement preprod-iselection.vertuelle.com
- [ ] Validation E2E : `python -m scripts.reingest_iselection_legacy --force` puis SQL spot-check :
  ```sql
  SELECT jsonb_typeof(jsonb_path_query_first(article_json, '$.children[*] ? (@.type == "acf/text-image")') -> 'properties' -> 'is_lightbox')
  FROM arcadia_agents.seo_articles WHERE workspace_id = '<iselection>' LIMIT 5;
  -- expected: 'boolean' on every row
  ```

### Notes coordination
- **Hors scope :** comportement pour clients non-AA. Si quelqu'un d'autre lit ces endpoints et attend le shape ACF brut, le changement est observable. Fence par query param ou nouvelle version si nécessaire.
- **Pas un blocker Path A** — grouper avec d'autres polish GET-side s'il y en a.

---

## Phase 30 : Pending Revisions — enforcement serveur

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-06-10*
*Décision Oscar 2026-06-10 (decisions.md) — supersède le flag opt-in du 2026-04-05*
*Spec : [pending-revisions.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/pending-revisions.md) §2.1 + §8*

### Contexte
Aujourd'hui la révision n'est créée que si la requête contient `pending_revision: true` **et** que le setting `aa_pending_revisions` est actif. Le setting seul ne protège rien : un PUT sans flag écrase le live même quand le client a activé la validation. Aligner sur le pattern `force_draft` (hard enforcement serveur) — la volonté du client doit être une garantie auto-portante, pas une convention que chaque appelant doit connaître.

### 30.1 — Enforcement serveur (`trait-api-posts.php`)
- [x] Setting `aa_pending_revisions` actif **et** post `publish` → tout `PUT /articles/{id}` stocké comme révision pending (réponse 201 revision), flag ou non
- [x] Flag `pending_revision` déprécié : accepté dans le body, ignoré (pas d'erreur)
- [x] Comportement inchangé : posts non publiés → update direct ; `POST /articles` → territoire `force_draft` ; priorité sur `force_draft` conservée

### 30.2 — Note de supersede avec référence (`class-revisions.php`)
- [x] `"Superseded by newer revision."` → `"Superseded by revision [new_id]"` (spec §6.1, traçabilité)

### 30.3 — Tests & build
- [x] `RevisionsTest.php` : nouveau cas "PUT sans flag, setting actif, post publié → révision créée"
- [x] Tests existants ajustés (flag seul sans setting → update direct, inchangé)
- [x] `./build.sh` passe

---

## Phase 32 : Flag `dry_run` transversal — exécuter sans persister

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-06-20*
*Sévérité : moyenne. Besoin immédiat : `POST /articles` (débloque le contrôle de justesse `forward` de la calibration CMS — oracle CMS point (1) — même sur un site sans articles).*

### Contexte
La calibration CMS du backend doit vérifier que sa transform `forward` (article canonique → blocs ACF) produit des blocs **réellement valides** pour ce CMS. Le seul oracle fiable est le CMS lui-même : sa normalisation ACF (réordonnancement, defaults, rendu HTML). Aujourd'hui l'obtenir imposerait de publier un brouillon de test puis de le supprimer — effet de bord sur le site client + cleanup fragile (orphelin si le delete échoue).

**Demande.** Un flag `dry_run` **sur tous les endpoints qui écrivent** (création, mise à jour, suppression…), pas seulement la création de post. Le flag fait passer le payload dans le **même pipeline** que l'opération réelle (validation + normalisation ACF), mais **s'arrête juste avant le `save`** et renvoie ce que l'opération aurait produit/stocké.
```json
POST /articles?dry_run=true  →  { "blocks": [ ...blocs normalisés tels que le CMS les stockerait... ] }
```

**Forme volontairement générique.** Les endpoints ne savent rien de « calibration » : ils valident/normalisent sans écrire. Convention transversale uniforme (même flag partout), tout appelant futur en bénéficie. Pas d'endpoint dédié. Sans ce flag, l'alternative est publier-puis-supprimer (effet de bord + cleanup).

### 32.1 — Plomberie transversale du flag
- [ ] Lire `dry_run` (query param + body, bool coercion) de façon uniforme sur tous les handlers d'écriture
- [ ] Endpoints couverts : `POST /articles`, `PUT /articles/{id}`, `DELETE /articles/{id}`, `POST /pages`, `PUT /pages/{id}`, `DELETE /pages/{id}`, media, taxonomies CRUD, redirects (recenser exhaustivement les write-paths)
- [ ] Convention de réponse uniforme : renvoyer ce que l'opération aurait persisté (pour `POST/PUT articles` → `blocks` normalisés post-pipeline ACF ; pour delete → ce qui aurait été supprimé)

### 32.2 — Court-circuit avant `save` (priorité : `POST /articles`)
- [ ] `POST /articles?dry_run=true` : exécute validation + coercion + normalisation ACF, s'arrête avant `wp_insert_post` / `do_action('acf/save_post')`, renvoie les `blocks` normalisés
- [ ] `PUT /articles/{id}?dry_run=true` : idem avant `wp_update_post` (interaction avec pending-revisions enforcement : ne crée pas de révision non plus)
- [ ] DELETE `dry_run` : ne supprime pas, renvoie l'effet qu'aurait eu l'opération
- [ ] Aucun effet de bord : pas de sideload média réel persistant, pas de meta écrite, pas de révision créée

### 32.3 — Tests & build
- [ ] Test : `POST /articles?dry_run=true` → 0 post créé en DB + `blocks` normalisés retournés
- [ ] Test : `PUT /articles/{id}?dry_run=true` → post inchangé + aucune révision pending créée (même setting actif)
- [ ] Test : DELETE `dry_run` → ressource toujours présente
- [ ] Test : flag absent / false → comportement réel inchangé (regression)
- [ ] `./build.sh` passe (tous checks bloquants)
- [ ] Mettre à jour `api-contract.md` (convention `dry_run` transversale) côté master specs

---

## Phase 33 : `GET /articles/{id}/blocks` renvoie les `field_values` (perf, basse priorité)

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-06-20*
*Sévérité : basse — pure latence. Découvert pendant la review du harness anti-OOM (aa-bqy7). À prendre quand on touche cet endpoint.*

### Contexte
L'agent SEO lit un article en deux temps via `get_cms_article` : le mode « carte » (défaut) appelle `GET /articles/{id}/blocks` pour la structure des blocs, puis fait un **2e appel** au listing (`GET /articles?...`) uniquement pour récupérer les `field_values` post-level (ACF/meta). `GET /articles/{id}/blocks` ne renvoie aujourd'hui que `{ "post_id": ..., "blocks": [...] }`.

**Demande.** Inclure les `field_values` post-level dans la réponse :
```json
{ "post_id": "...", "blocks": [...], "field_values": { "...": "..." } }
```
Le backend pourrait alors supprimer le 2e appel HTTP sur le chemin chaud (un appel agent → un appel CMS au lieu de deux). Pas de blocage, pas de régression — le backend est correct aujourd'hui.

### 33.1 — Enrichir la réponse
- [ ] Ajouter `field_values` (post-level ACF/meta) à la réponse de `GET /articles/{id}/blocks`, même source que le listing
- [ ] Réutiliser la sérialisation `field_values` existante du listing (single source of truth)

### 33.2 — Tests & build
- [ ] Test : réponse contient `field_values` cohérents avec le listing
- [ ] Test : article sans ACF → `field_values` vide/cohérent (pas de crash)
- [ ] `./build.sh` passe
- [ ] Mettre à jour `api-contract.md` côté master specs

---

## Phase 7 : Publication WP.org

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
