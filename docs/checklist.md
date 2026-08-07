# Plugin WordPress - Checklist de développement

**Dernière mise à jour :** 2026-08-05 (**v0.3.0 buildée** ; Phases 34/36/37/38/39 terminées ; **Phase 40** surface `/contents` **terminée** ; **Phase 41** lot P1b **terminée** ; **Phase 42** intégrité d'écriture des champs **terminée** — 572 tests, 7 mutants tués ; Phase 29 E2E AA-side pending)

> **Prochain front de travail : déploiement de v0.3.0.** Il ne reste que la campagne manuelle sur les
> 3 sites clients, puis la levée de l'avertissement envoyé à AA et la bascule de leur connector.
>
> **Pourquoi v0.2.1 ne doit pas rester en prod.** Les 4 items de la Phase 42 avaient été routés par AA le
> 2026-07-30 avec la mention « ⏸ à grouper avec le lot post_type » — donc dans la même release que les
> Phases 40/41. Ils n'ont pas été intégrés à la checklist, et v0.2.0 puis v0.2.1 sont parties sans eux.
> Or la Phase 41 a rendu les pages business **écrivables** (elles répondaient 404 avant) sans que la
> Phase 42 les rende **sûres** : depuis le 2026-08-02, un `PUT` sur une de ces pages réussit en perdant
> des champs en silence. v0.3.0 ferme le trou. La leçon est intégrée dans le protocole : intégrer le
> backlog, c'est l'intégrer **en entier**.

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
- [x] Déploiement preprod-iselection.vertuelle.com — **couvert par le déploiement v0.1.32** (preprod confirmée à 0.1.32 ≥ 0.1.25, le code Phase 29 est en prod ; 2026-06-20)
- [ ] Validation E2E **(tâche AA-side, hors session plugin)** : `python -m scripts.reingest_iselection_legacy --force` puis SQL spot-check :
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

**Scope réalisé (décision 2026-06-20).** Un validateur no-persist existait déjà (`POST /validate-content`) — orphelin (aucun consommateur AA, absent de `api-contract.md`). Implémenté : `dry_run` sur **`POST` + `PUT /articles`** (besoin réel = calibration), réutilisant le validateur dry-run existant, et **`POST /validate-content` supprimé** (le dry-run create en est un strict superset). La plomberie (helper `is_dry_run()` + convention de réponse) est posée ; les autres write-paths sont **différés** (§32.5) — aucun consommateur aujourd'hui.

### 32.1 — Plomberie du flag (helper partagé)
- [x] Helper `is_dry_run( $request )` (`trait-api-posts.php`) : lecture query param **et** body via `get_param()` + coercion `filter_var(FILTER_VALIDATE_BOOLEAN)`. Reader canonique de la convention dry-run.
- [x] Threading `$dry_run` dans `Arcadia_Blocks::json_to_blocks()` → `Arcadia_ACF_Validator::validate_and_preprocess(..., $dry_run)` (skip sideload, déjà en place) et dans `Arcadia_Post_Builder::build_post_data(..., $dry_run)`.

### 32.2 — Court-circuit avant `save` (articles)
- [x] `POST /articles?dry_run=true` : exécute validation + coercion + render via `dry_run_build()`, s'arrête avant `write_post`/`finalize_post`, renvoie `{ dry_run, valid, blocks, field_values }` (HTTP 200). Blocs normalisés via `format_parsed_blocks()` (parité `GET .../blocks`).
- [x] `PUT /articles/{id}?dry_run=true` : early-return **avant** le bloc force-draft/révision → ne crée pas de révision ni ne touche le live. Renvoie le même payload.
- [x] Échec validation → même `WP_Error` (HTTP 422 + `errors`) qu'un vrai write (parité oracle).
- [x] Aucun effet de bord : sideload image skippé, pas de meta écrite, pas de révision créée. Fonctionne sur un site sans articles (création simulée).

### 32.3 — Suppression `validate-content` (orphelin)
- [x] Route (`class-api.php`), handler REST (`trait-api-blocks.php`), méthode métier (`class-blocks.php`) retirés
- [x] Test N3 (`AcfValidatorTest.php`) conservé (teste `validate_and_preprocess(dry_run)`, fondation du dry-run) — commentaire de section mis à jour

### 32.4 — Tests & build
- [x] `ArticleDryRunTest.php` (5 tests) : create no-persist + blocs ; create ACF invalide → 422 ; create sans contenu → blocs vides ; flag absent → persiste (regression) ; update publié sous enforcement → pas de révision
- [x] Suite complète verte : **363 tests** (était 355 ; +5 dry-run +3 field_values Phase 33)
- [x] `./build.sh` passe — v0.1.33
- [x] `api-contract.md` master : `dry_run` documenté + caveat + retrait `validate-content`

### 32.5 — Endpoints d'écriture différés (structure sans spéculation)
Plomberie prête (`is_dry_run()`) ; à câbler dès qu'un consommateur apparaît. Call-sites de save, cut-line = avant la ligne indiquée :
- `DELETE /articles/{id}` — `wp_delete_post` (`trait-api-posts.php`)
- `PUT /pages/{id}` — `wp_update_post` (`trait-api-posts.php`)
- `POST /media` — `media_handle_sideload` ; `PUT /articles/{id}/featured-image` — `set_post_thumbnail` ; `PUT /media/{id}` — `wp_update_post` ; `DELETE /media/{id}` — `wp_delete_attachment` (`trait-api-media.php`)
- `POST|PUT|DELETE /categories` + `/tags` — `wp_insert_term`/`wp_update_term`/`wp_delete_term` (`trait-api-taxonomies.php`)
- `POST|DELETE /redirects` — `wp_insert_post`/`wp_delete_post` (`trait-api-redirects.php`) ; `PUT /field-schema` — `update_option` (`trait-api-field-schema.php`)

---

## Phase 33 : `GET /articles/{id}/blocks` renvoie les `field_values` (perf, basse priorité)

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-06-20*
*Sévérité : basse — pure latence. Découvert pendant la review du harness anti-OOM (aa-bqy7).*

### Contexte
L'agent SEO lit un article en deux temps via `get_cms_article` : le mode « carte » (défaut) appelle `GET /articles/{id}/blocks` pour la structure des blocs, puis fait un **2e appel** au listing uniquement pour récupérer les `field_values` post-level (ACF/meta). Inclure les `field_values` dans la réponse blocks supprime ce 2e appel.

### 33.1 — Enrichir la réponse
- [x] Helper `get_field_values_for_post( $post_id )` extrait de `format_post()` (`trait-api-formatters.php`) — single source of truth, partagé listing + blocks
- [x] `get_article_blocks()` renvoie `{ post_id, blocks, field_values }` (branches contenu + contenu vide)

### 33.2 — Tests & build
- [x] `ArticleBlocksTest.php` (+3 tests) : field_values présents/cohérents ; présents même sans contenu ; sans ACF → objet vide (pas null/array, pas de crash)
- [x] `./build.sh` passe + `api-contract.md` master mis à jour

---

## Phase 34 : Fix `core/*` block pass-through — jamais de 422 sur un bloc core

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-06-27 ; réponse backend (shapes + scope Tier 2) intégrée 2026-06-27*
*Nature : **violation de contrat** (`content-model.md` §4 + §8.1 : le plugin ne doit jamais 422 un bloc `core/*`).*
*Sévérité : moyenne. AA dodge le cas `core/group` par construction (`flatten_sections`), mais `core/quote` / `core/table` / `core/separator` sont **réellement émis** sur site vanilla (tableaux fréquents).*
*Scope : **Tier 2** — fix 422 **+ rendu natif fidèle** des trois blocs (shapes confirmées/validées backend).*

### Contexte
Publier un `core/group` (ou `core/quote`, `core/table`, `core/separator`) sur un site Gutenberg-natif (non-ACF) renvoie `422 "Block type 'group' not registered"`. Les blocs `core/*` sont toujours valides dans `post_content`, jamais 422.

**Root cause.** `validate_block_recursive` (`class-blocks.php`) strippe le préfixe `core/` **avant** d'appeler `registry->is_registered($stripped_type)`. L'early-return « core/* toujours accepté » dans `is_registered` (`class-block-registry.php`) est donc **dead code** — au moment où il s'exécute le préfixe a déjà disparu, la vérif retombe sur l'allowlist (`BUILTIN_BLOCKS` {paragraph, heading, image, list} + `INTERNAL_TYPES` {section, text} + custom). Tout autre `core/*` échoue le lookup → 422. `core/paragraph/heading/list/image` marchent uniquement car leur nom strippé EST un builtin (d'où le faux positif de `test_core_blocks_not_rejected`).

**Shapes JSON reçues (confirmées backend, chemin vanilla uniquement — sur ACF elles sont pré-converties par le transform) :**
- `core/quote` → `{"type":"core/quote","content":"<texte markdown inline>"}` (pas de champ citation)
- `core/table` → `{"type":"core/table","properties":{"headers":[str]|null,"rows":[[str],…]}}` — cellules = markdown inline (pas HTML brut) ; invariant backend : rectangulaire (`len(row)==len(headers)` si headers)
- `core/separator` → `{"type":"core/separator"}` (aucun payload)

### 34.1 — Fix validation (jamais de 422 sur core/*)
- [x] Cas-spécialiser `core/*` **avant** le strip dans `validate_block_recursive` : accepter + récurser dans les enfants (pas de lookup allowlist, pas de validation de propriétés)
- [x] Garder l'early-return de `is_registered` comme défense en profondeur + commentaire (poka-yoke)

### 34.2 — Rendu natif fidèle (Tier 2)
- [x] `Arcadia_Gutenberg_Adapter` : `separator()` → `<!-- wp:separator --><hr class="wp-block-separator …"/>…`
- [x] `Arcadia_Gutenberg_Adapter` : `quote($content)` → `<!-- wp:quote --><blockquote class="wp-block-quote">` + paragraphe interne (`parse_markdown` inline)
- [x] `Arcadia_Gutenberg_Adapter` : `table($headers, $rows)` → `<!-- wp:table --><figure class="wp-block-table"><table>` + `<thead>` si headers + `<tbody>` ; cellules via `parse_markdown` (pas de double-escape)
- [x] `Arcadia_Block_Processor` : helper `native_gutenberg()` (adapter-indépendant, filet §551) + `case 'separator'/'quote'/'table'` dans `process_block`

### 34.3 — Tests & build
- [x] `BlocksTest` : `core/group/quote/table/separator` → string, pas WP_Error
- [x] `BlocksTest` : `core/table` (avec/sans headers) → `<table>`/`<thead>`/`<td>` ; `core/quote` → `<blockquote>` ; `core/separator` → `<hr` ; markdown inline de cellule converti
- [x] `BlocksTest` : `core/whatever` inconnu → pas de 422 (fallback existant)
- [x] `GutenbergAdapterTest` : tests directs des 3 nouvelles méthodes (5 tests)
- [x] Régression : builtin (paragraph/heading/image/list) + ACF inchangés (379 tests verts)
- [x] `content-model.md` §4 + `decisions.md` : shapes core/* + rendu fidèle codifiés
- [x] `./build.sh` passe (v0.1.35)

---

## Phase 35 : Champs wysiwyg ACF — préserver le HTML de structure à l'écriture REST  ⚠️ SUPERSEDED → Phase 36

> **⚠️ Direction corrigée par le backend (2026-06-27).** Phase 35 supposait que l'agent envoie du **HTML** de structure à *préserver* via `wp_kses_post`. **C'est faux** : l'agent n'émet jamais de HTML (ADR-013/ADR-022 — AA produit le contenu, le plugin produit le HTML). Il envoie du **markdown bloc+inline**. Le `parse_rich` inline-only livré en v0.1.35 rend `## Titre` **littéralement**. → refait en **Phase 36** (le `wp_kses_post` final reste valable ; c'est l'étape de parsing qui change).

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-06-27*
*Nature : **changement de contrat** (pas une violation — le strip inline-only est volontaire, ADR-013).*
*Sévérité : moyenne — bloque la parité de rendu natif/REST sur les thèmes ACF (iSelection).*

### Contexte
Tout champ `wysiwyg` d'un bloc ACF passe par `Arcadia_Markdown_Parser::parse_markdown()`, dont le `wp_kses` final n'autorise que l'inline `{strong, em, code, a}` (`class-markdown-parser.php`). Les balises de structure (`<h2>`–`<h6>`, `<p>`, `<ul>/<ol>/<li>`, `<table>`, `<blockquote>`, `<span>`…) sont supprimées à l'enregistrement. Point de strip : `class-adapter-acf.php`, `custom_block()`, `case 'wysiwyg'`.

**Pourquoi changer.** Les articles natifs (rédigés dans l'éditeur WP) stockent du HTML riche directement dans ces champs wysiwyg — c'est ainsi que le thème (iSelection) les stylise (`.acf-text h2`, `.acf-text a` en vert). Le chemin d'écriture REST ne peut donc **pas** reproduire un article au rendu natif :
- contenu dans `acf/text` → bon conteneur (liens stylés) **mais structure supprimée** ;
- contenu en `core/*` → structure conservée **mais hors conteneur `.acf-text`** (liens non stylés).

La parité de rendu est impossible tant que les champs wysiwyg suppriment la structure.
**Preuve :** l'`acf/text` natif du post `58038` (preprod iSelection) contient `<h2>`, plusieurs `<h3>`, `<a href>`, `<ul><li>` — toutes balises que l'écriture REST supprime aujourd'hui.

### 35.1 — Élargir l'allowlist sur le chemin d'écriture wysiwyg
- [x] Nouveau `Arcadia_Markdown_Parser::parse_rich()` : convertit le markdown inline **puis** sanitise avec `wp_kses_post` (allowlist post-content standard WP). Refactor : `convert_inline()` privé partagé ; `parse_markdown()` (inline-only) inchangé.
- [x] `wp_kses_post` bloque toujours `<script>` / `<iframe>` / `on*` / `javascript:` → pas de downgrade sécu (déjà utilisé sur le chemin plain-content, `class-post-builder.php`)
- [x] Strip inline-only **conservé** pour les textes courts encapsulés (heading/paragraph/listing du gutenberg adapter). Les 2 sites wysiwyg (`class-adapter-acf.php`, `trait-api-acf-fields.php`) pointent vers `parse_rich()`.

### 35.2 — Docs & tests à mettre à jour avec le fix
- [x] `content-model.md` master §2 — formatage des champs wysiwyg (inline markdown + HTML de structure sanitisé)
- [x] ADR-013 (fichier `adr/ADR-013-*.md`) + `decisions.md` master — le contrat « inline = markdown only » devient « inline markdown + HTML de structure sanitisé » sur wysiwyg
- [x] Mock `wp_kses_post` durci dans `bootstrap.php` (allowlist post-content fidèle) pour que les tests prouvent vraiment preservation + strip
- [x] Nouveaux tests (`AcfAdapterTest` + `AcfFieldsTest`) : wysiwyg `<h2>/<ul>/<table>` → préservé ; `<script>/<iframe>/onerror` → strippé. Tests inline `parse_markdown` (BlocksTest) inchangés (chemin inline préservé).

### 35.3 — Build
- [x] `./build.sh` passe (v0.1.35)

---

## Phase 36 : CORRECTION wysiwyg — l'agent envoie du markdown bloc+inline (PAS du HTML)

*Ref: backlog.md — correction backend intégrée 2026-06-27. **Supersede la direction de Phase 35.***
*Nature : correction de contrat. Phase 35 supposait « l'agent envoie du HTML à préserver » — faux. L'agent n'émet jamais de HTML (ADR-013/ADR-022).*

### Contrat corrigé (backend, source de vérité)
Dans un champ `wysiwyg` ACF, l'agent envoie du **markdown de structure** :
- titres `##` … `######`
- listes `-` / `1.`
- tables markdown `| a | b |`
- citations `>`
- inline : `**gras**`, `*italique*`, `[lien](url)`, `` `code` ``

**Doit rendre** en HTML riche (`<h2>`, `<p>`, `<ul><li>`, `<table>`, `<blockquote>`, `<a>`, `<strong>`…), identique à un article rédigé nativement, pour que le thème stylise via son conteneur (`.acf-text`).

**Conséquence plugin :** parser le markdown **bloc + inline** → HTML → `wp_kses_post`. Seul écart vs. Phase 35 : le **bloc** en plus de l'inline (aujourd'hui seul l'inline est géré).

### 36.0 — DÉCISION : approche du parser de bloc ⬅️ Oscar
- [x] **Hand-roll** retenu (`parse_block_markdown()` maison, 0 dépendance). Risque correctness maîtrisé par matrice de tests (44 tests, 3 passes de recherche GFM/CommonMark/inline). Évite collision classe globale `Parsedown` + complexité PHP-Scoper WP.org.

### 36.1 — Parser markdown de bloc
- [x] `parse_rich()` : markdown **bloc+inline** → HTML → `wp_kses_post` (`parse_rich = wp_kses_post(parse_block_markdown())`)
- [x] Constructs : titres `##`-`######`, listes `-`/`1.` (imbrication 1 niveau, tight), tables GFM (`| |` + délimiteur concordant, alignement `:`, `\|` échappé), citations `>`, barres `---`, code clôturé ` ``` `, passthrough HTML, paragraphes ; inline réutilise `convert_inline()` (code-span protégé avant emphase). Préprocessing PCRE : garde UTF-8, CRLF, regex `/u`.
- [x] `skip_markdown` (round-trip, aa-u6nl) : `is_skip_markdown()` (miroir `dry_run`) → `finalize_post` options → `process_acf_fields()` → `parse_rich($v, $skip)`. Chemin génération blocs ACF = markdown par contrat (filet passthrough HTML).

### 36.2 — Tests
- [x] `MarkdownBlockParserTest` (44 tests) : chaque construct, bloc+inline combinés, `skip_markdown=true` → pas de parsing, `<script>`/`onerror` strippés, accents FR / CRLF / gros input, liens externes `rel`/`target`, `<img>` conservé
- [x] Tests Phase 35 revus : `AcfAdapterTest` reçoit du markdown (`## Titre` → `<h2>`) ; `AcfFieldsTest` + test `skip_markdown`

### 36.3 — Docs & build
- [x] `content-model.md` §2 + `decisions.md` : « inline + HTML préservé » → « markdown **bloc+inline** parsé → HTML » (ADR-013 déjà amendé par le backend)
- [x] `./build.sh` (v0.1.35 → v0.1.36, 15 gates ✓)

### Hors scope (noté backend)
- `*` / `[` littéral dans du markdown frais = ambigu (italique vs littéral). `skip_markdown` ne le résout pas (on veut le parsing pour `**gras**`). À traiter si la fréquence le justifie. **(= finding review #4)**

---

## Phase 37 : Code-review Phases 34-35 — findings vérifiés (workflow xhigh, 2026-06-27)

*10 finders, 26 candidats, 22 verifiers → 11 findings retenus. Liste unique par sévérité (pas de tri « pré-existant »).*

### P1 — Perte de données / correctness
- [x] **#1** Blocs conteneurs `core/*` : passthrough verbatim (backend a tranché — ingestion round-trip, jamais vidé en silence). `process_block` détecte les blocs round-trip (`inner_blocks`/`inner_content`) → reconstruit le markup stocké (`<!-- wp:... -->` + ouverture + enfants récursifs + fermeture) ; garde « jamais vide » dans le `default` ; `validate_block_recursive` les accepte verbatim (pas de 422 sur bloc tiers). Symétrie read/write (clés `inner_blocks`/`innerBlocks`/`children`).
- [x] **#2** `quote()`/`table()` : garde scalaire (`is_scalar() ? (string) : ''`) → plus de littéral `Array` + warning.

### P2 — Contrat / découverte
- [x] **#3** `core/quote`/`separator`/`table` ajoutés à `BUILTIN_BLOCKS` (GET /blocks les liste, nom nu ne 422 plus ; description table documente `headers`/`rows`).
- [x] **#4** Tokenizer inline flanking/escapes → **clos, pas de changement de code** (différé, tranché backend, hors scope). Le parser applique des regex sûres (code-span protégé avant emphase) mais ne réécrit pas l'emphase au flanking CommonMark. À rouvrir uniquement si un cas réel remonte du terrain.

### P3 — Sécurité / tests / perf / maintenabilité
- [x] **#5** wysiwyg persiste `<img>` : **accepté** (cohérent post_content, agent JWT). Test `test_wysiwyg_img_survives`.
- [x] **#6** Tests liens externes : `test_external_link_gets_rel_and_target` + `test_internal_link_has_no_target` (stub `wp_kses` autorise déjà `href`/`target`/`rel`).
- [x] **#7** `home_url()` calculé une fois par `convert_inline` (`$site_host` hors callback, capturé via `use()`).
- [x] **#8** Helper partagé `Arcadia_Block_Registry::is_core_type()`/`strip_core_prefix()` appliqué aux 4 sites (fin du `substr($type,5)` magique).
- [x] **#9** `native_gutenberg()` : docblock sur la règle de décision (interface = multi-builder pour les types assembly ; `native_gutenberg()` hors interface = rendu core-only).

---

## Phase 38 : Revue de la revue — durcissement passthrough round-trip (workflow xhigh, 2026-06-27)

*Review xhigh des changements Phase 36+37 : 10 finders, 46 candidats, 38 verifiers → 14 findings retenus (9 CONFIRMED + 5 PLAUSIBLE) + 8 réfutés. Tous traités (sauf #12, by-design). v0.1.36 → v0.1.37, 440 tests.*

### 🔴 Sécurité (stored XSS — round-trip n'est plus exempté de `wp_kses_post`)
- [x] **#1** Nom de bloc réduit à son slug avant le délimiteur `<!-- wp:NAME -->` (`safe_block_name()`) — plus de comment-breakout via un `type` forgé.
- [x] **#2** Chunks `inner_content` passés par `wp_kses_post()` — plus de `<script>` agent stocké verbatim. ⚠ strippe aussi les `<iframe>`/embeds (voir coordination backend).

### 🟠 Perte de contenu / ordre
- [x] **#3** Feuille `core/*` non rendue préservée en commentaire natif (`native_block_comment()`) au lieu d'être droppée.
- [x] **#5** Validation recurse les enfants round-trip (nœud cassé → 422, plus de disparition muette) ; feuilles namespaced du sous-arbre acceptées (pas de 422 sur contenu tiers).
- [x] **#4** Reconstruction fidèle via null placeholders WP-grammar (enfants interleavés) ; fallback lossless si absents. ⚠ exactitude complète dépend du reader AA (voir coordination backend).
- [x] **#6** URL avec parenthèses équilibrées non tronquée (liens Wikipedia).
- [x] **#7** Liens extraits avant la passe emphase (`*` dans URL ne devient plus `<em>` mangé par `esc_url`) ; emphase appliquée au texte du lien.
- [x] **#8** Filet passthrough HTML élargi aux balises inline en début de ligne (round-trip HTML sans `skip_markdown` non corrompu).

### 🟡 Robustesse / fail-safety
- [x] **#9** `content:"0"` n'est plus traité comme vide (`'' !==` au lieu de `empty()`).
- [x] **#10** Garde `function_exists` sur mbstring (pas de fatal sur hôte sans ext-mbstring).
- [x] **#11** Backstop `MAX_BLOCK_DEPTH` sur la récursion passthrough (anti-DoS imbrication).
- [x] **#13** `content` non vide prime sur un `inner_content` parasite (discriminateur round-trip).
- [x] **#14** Test `skip_markdown` non-vacant (input markdown nu, prouve le threading du flag dans les 2 branches).
- [x] **#15** `passthrough_block` utilise `strip_core_prefix()` (plus de `preg_replace('#^core/#')` dupliqué).
- [x] **#12** Prose wysiwyg `## `/`- `/`| |` → élément structurel : **clos, by-design** (contrat Phase 36, le wysiwyg porte du markdown — ADR-013/ADR-022). Pas de changement de code.

### 🔵 Coordination backend (questions ouvertes — voir decisions.md 2026-06-27)
- [x] **Null placeholders** : ~~décider~~ **tranché (Oscar)** — note basse-priorité à AA, **non-bloquant**. Le reader AA dé-nulle `inner_content` → ordre deviné pour un conteneur avec HTML brut entre enfants (jamais perdu, repli lossless ; cas rare). Plugin déjà forward-compatible : si AA **préserve** les null placeholders un jour, reconstruction exacte sans changement plugin.
- [x] **Embeds/iframes** : ~~décider~~ **tranché (Oscar)** — on garde le strip `wp_kses_post` des `<iframe>`/embeds sur round-trip. Sécurité > préservation verbatim.

---

## Phase 39 : Markdown inline dans les cellules de table ACF (`acf/table` → `row.cols.cell`)

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-07-30*
*Bug live : `www.iselection.com` WP#48869 + preprod WP#88200 — les `**gras**` s'affichent littéralement dans les cellules.*

**Contexte.** Contrat ACF wysiwyg = le champ porte du markdown, le plugin convertit en HTML à l'écriture (Phase 36, commit AA `0a8f852a`). La conversion s'applique aux champs texte (`acf/text` → champ `text`) mais **pas aux cellules de répéteur** (`acf/table` → `row.cols.cell`) : le transform AA envoie volontairement le markdown brut, et le thème (`blocks/table/template.php`) ne le passe jamais au convertisseur.

**Attendu.** Appliquer la même conversion markdown→HTML au champ `cell` du répéteur `row.cols` — et à tout autre champ wysiwyg de répéteur si le cas se présente.

**Hors périmètre.** Structure et compteurs de répéteur (garantis côté AA, fix `_repeater_counts.py`). Ce ticket ne concerne que la conversion inline du contenu.

**Cause racine.** `Arcadia_ACF_Adapter::flatten_repeater()` était un passthrough brut : il calculait déjà `$sub_types` (pour détecter les répéteurs imbriqués) mais ne s'en servait jamais pour transformer les valeurs feuilles. Un sous-champ `wysiwyg` ne voyait donc **aucun** convertisseur, contrairement aux propriétés de premier niveau (`custom_block()` → `case 'wysiwyg'` depuis la Phase 36).

**Décision — conversion INLINE, pas bloc.** Les feuilles de répéteur reçoivent `parse_markdown()` (inline-only : `strong`/`em`/`code`/`a`), **pas** `parse_rich()` comme au premier niveau. Une ligne de répéteur **est** déjà la structure ; la feuille est un texte court pré-encapsulé par le thème dans un `<td>`/`<li>`. Le parsing bloc envelopperait chaque cellule d'une seule ligne dans un `<p>` (marges dans tous les `<td>`) et promouvrait une cellule commençant par `- ` en `<ul>`. Même règle que l'adaptateur Gutenberg natif, qui convertit déjà chaque cellule avec `parse_markdown()` (content-model.md § « Cellules de tableau = chaînes markdown inline »).

**Périmètre de types.** Uniquement `wysiwyg`. Les types `text`/`url`/`select`/`image` restent intouchés — contrat ADR-013 (content-model.md L92) : le plugin n'injecte jamais de HTML dans un champ dont le template de thème peut l'échapper (double-échappement → balises visibles à l'écran).

- [x] Localiser le chemin d'écriture des sous-champs de répéteur → `flatten_repeater()` dans `includes/adapters/class-adapter-acf.php` (chemin blocs). Le chemin `acf_fields` post-level est un cas distinct, voir « Reste à faire » ci-dessous.
- [x] Critère de détection = **field schema ACF** (`sub_fields[].type`), pas d'allowlist de noms — poka-yoke, aucun cas spécial « cell »
- [x] Nouvelle méthode `transform_sub_field_value()` appliquée dans `flatten_repeater()` ; docblock qui justifie inline-vs-bloc
- [x] Tests unitaires (6, dans `BlockRegistryTest.php`) : `**gras**` / `[lien](url)` / `` `code` `` / `*italique*` ; inline-only (pas de `<p>`, pas de `<ul>`, pas de `<h2>`) ; cellule vide + cellule `"0"` préservées ; sous-champ `text` non converti ; XSS strippée ; répéteur plat (`acf/faq` → `answer`) en plus du nested `row.cols.cell`
- [x] Mutation-check : la ligne du fix remise en passthrough → 3 tests rouges (non-vacants)
- [x] Suite complète verte : **446 tests** (440 → +6)
- [x] `./build.sh` → v0.1.38 (380KB) — a nécessité de réparer deux défauts du build, voir ci-dessous

### Réparation de `build.sh` (découverte en lançant le build)

Le build échouait au check #14 depuis la Phase 31 (commit `2daca44`, celui-là même qui a ajouté les gates) — **aucun zip n'a pu être produit depuis**. Deux défauts, tous deux corrigés :

- [x] **Gate #14 en faux positif.** `phpstan.neon.dist`, `phpstan-baseline.neon` et `phpcs.xml` vivent dans `arcadia-agents/` et n'étaient pas exclus du zip. Le grep `phpstan|szepeviktor|wordpress-stubs|parallel-lint` matchait ces **fichiers de config** (pas de vraies dev deps — `composer install --no-dev` faisait bien son travail) → abort systématique. Exclusions ajoutées (+ `.phpunit.cache/`).
- [x] **Le bump de version brûlait un numéro à chaque échec.** Le check #12 (bump) s'exécute *avant* la création (#13) et l'audit (#14) du zip, sans rollback : chaque build avorté laissait l'arbre sur une version jamais packagée. C'est ainsi que l'arbre est arrivé à 0.1.37 sans zip correspondant. Le trap `EXIT` restaure désormais la version quand le build n'a pas atteint la fin (`BUILD_OK`/`VERSION_BUMPED`/`PREV_VERSION`).
- [x] Rollback **vérifié** par injection d'un `fail` juste après le bump : `0.1.38 → 0.1.39` puis « Version restored to 0.1.38 » dans les 3 sources (define, header, `Stable tag`).

### ✅ Vérification bloquante — tranchée par AA (relevé live 2026-07-30)

Le fix ne se déclenche que si le sous-champ `cell` est déclaré **`wysiwyg`**. Réponse AA, relevée en
live via `GET /blocks` sur les **deux** sites iSelection (preprod + www, même clé ACF, même groupe) :

```
acf/table → row (repeater) → cols (repeater) → cell : type "text", key field_68b93eaa96ebc
```

- [x] **Type réel = `text`, pas `wysiwyg`.** Les champs wysiwyg de ce bloc sont `title`, `text` et
      `text-bottom` — `cell` n'en fait pas partie. **Le fix Phase 39 ne se déclenche donc pas ici.**
- [x] **Le bug est côté AA**, pas côté plugin : AA émet du markdown dans un champ ACF texte brut, ce qui
      viole ADR-013. AA l'a acté (« votre refus était le bon »), le traite chez eux, et **n'attend rien
      du plugin**. Rien à élargir aux champs `text` — la conversion y provoquerait un double-échappement.
- [x] Le fix Phase 39 reste **juste et utile** : tout sous-champ de répéteur réellement déclaré
      `wysiwyg` est désormais converti. Il ne trouve simplement pas de cas d'emploi sur `acf/table`.
- [x] Confirmé au passage par AA : `GET /blocks` **expose bien** les `sub_fields` imbriqués. C'est leur
      parseur qui les aplatissait — défaut chez eux, tracé chez eux.
- [x] ~~Valider le rendu sur preprod WP#88200 / prod WP#48869~~ — sans objet, le chemin n'est pas emprunté.

### Gap adjacent identifié (hors périmètre, non corrigé)

`process_acf_fields()` (chemin `acf_fields` post-level, pas blocs) a exactement la même cécité : `case 'repeater'` est un passthrough et `build_acf_field_type_map()` ne descend pas dans les `sub_fields`. Un répéteur envoyé via `acf_fields` avec des sous-champs wysiwyg garde son markdown brut. Pas de preuve que AA emprunte ce chemin pour des répéteurs → laissé en l'état plutôt que corrigé à l'aveugle.

---

## Phase 40 : Rename surface `/articles` → `/contents` — ✅ FAIT (2026-08-01)

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-07-30*

**Contexte.** Côté AA le langage a été renommé (déployé prod 2026-07-02) : « article » devient `EditorialContent` (types `article` | `business_page`). La surface REST du plugin suit.

Livré avec la Phase 41 dans une release unique, comme la décision produit l'exigeait.

- [x] Chaque endpoint exposé sous `/contents*` **et** `/articles*` — table `content_route_definitions()`
      + boucle `CONTENT_ROUTE_PREFIXES`, `class-api.php`
- [x] `/articles*` déprécié : `Deprecation` (RFC 9745), `Sunset` (RFC 8594, 2027-02-01),
      `Link; rel="successor-version"` (RFC 5829) — `class-api-deprecations.php`
- [x] Grâce de six mois, ≥ un cycle de release complet
- [x] Tests — `ContentRouteParityTest.php` (12) + `DeprecationHeadersTest.php` (33)
- [ ] Coordination : le connector AA bascule sur `/contents` une fois la release déployée sur les 3 sites

### Comment la parité est garantie

`build_endpoints()` est appelé **une fois par route** et son résultat monté sous les deux préfixes :
les jumeaux partagent la **même instance de Closure**. La divergence de scope devient impossible
*par identité*, pas par discipline — et le test l'assert avec `assertSame()`, pas en comparant deux
littéraux qui se ressemblent aujourd'hui.

**Deux pièges évités, chacun épinglé par un test :**
1. `/revisions` et `/revisions/{revision_id}` vivaient dans leur propre `register_revision_routes()`.
   Aliaser le seul groupe article les aurait oubliées **sans aucune erreur**. Fondues dans la table.
2. `/…/{id}/featured-image` utilise **`media:write`**, pas `articles:write`. C'est la seule ligne de la
   table qui casse le motif, donc celle qu'un copier-coller élargit en silence.

**Scopes non renommés.** `articles:*` est persisté dans une option WP et affiché en checkboxes admin
(`class-auth.php`, `admin/settings.php`) ; des `contents:*` imposeraient une migration de settings sur
chaque site client pour zéro gain fonctionnel.

**Filtre `rest_post_dispatch`, pas un wrapper de callback** — un wrapper casserait la parité (les
jumeaux n'auraient plus le même callback), et surtout il **ne s'exécute pas sur 401/403** :
`permission_callback` court-circuite avant, or un client refusé pour scope est exactement celui qu'on
veut avertir. Règles par **préfixe** avec frontière de segment obligatoire (sinon `/articles-archive`
matcherait). Rien dans le corps : des payloads identiques octet pour octet entre jumeaux, c'est ce qui
rend l'assertion de parité utile.

### `PUT /pages/{id}` déprécié, `GET /pages` conservé

Vérifié côté AA : `GET /pages` alimente le maillage interne, `update_page()` existe dans le connector
mais **aucun appelant**. La route est réenregistrée sur `update_post` et le corps de `update_page()`
supprimé — un chemin déprécié ne doit pas garder une mise en forme sur mesure, sinon la migration
change les sémantiques plus tard au lieu de maintenant.

⚠️ **Ce n'est pas neutre en payload.** La réponse passe de `{ success, page }` (10 champs, dont
`parent`/`menu_order`/`template`) à `{ success, post }` (21 champs). De nouveaux comportements
atteignent les pages pour la première fois : `dry_run`, le chemin force-draft → révision (une page
publiée peut désormais répondre **201 + revision_created** au lieu de 200), le rejet de changement de
`post_type`, le 422 sur champs structurels, et le finalize complet du builder. **Accepté** (zéro
appelant), **à annoncer** dans `backlog-for-backend.md`.

**Non-vacuité vérifiée** (9 mutants) : préfixe `/contents` retiré → 5 rouges ; `build_endpoints()` appelé
par préfixe (identité perdue) → 1 ; scope `featured-image` élargi → 3 ; routes de révision retirées de la
table → 4 ; frontière de segment retirée → 2 ; règle `/pages` non limitée à PUT → 3 ; check de namespace
retiré → 2 ; `Link` qui remplace au lieu d'ajouter → 1 ; `PUT /pages` sur un autre handler → 1.

> Le mutant « check de namespace retiré » a d'abord **survécu** : mes cas étrangers (`/wp/v2/posts`,
> `/other/v1/articles`) se dégradaient en chaînes inoffensives une fois strippés naïvement. Il a fallu un
> namespace étranger de **même longueur** qu'`/arcadia/v1` (`/foobar/v11/articles`) pour l'atteindre. Sans
> la passe de mutation, ce trou serait passé pour couvert.

### Suppression au sunset (4 endroits, tous dans les fichiers touchés ici)

1. `CONTENT_ROUTE_PREFIXES` → ne garder que `/contents`
2. `Arcadia_API_Deprecations::rules()` → vider
3. `register_page_routes()` → retirer `/pages/(?P<id>\d+)`
4. `ContentRouteParityTest` → la direction inverse de la bijection

---

## Phase 41 : Lot P1b — garanties `post_type` (3 défauts root-causés)

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-07-30*
*Source AA : `docs/tasks_backlog/agent-seo/next/_capture_business_pages/findings.md`, sondé en réel sur iSelection préprod le 2026-07-02.*

Le périmètre plugin de P1b, qui bloquait la Phase 40. Les 3 défauts sont **vérifiés dans le code** de cette session (pas seulement rapportés).

### 41.1 — `is_allowed_post_type` rejette les types hiérarchiques

**Confirmé** : `trait-api-posts.php:922` → `return $post_type_obj->public && ! $post_type_obj->hierarchical;`

Le CPT `page` est hiérarchique, donc les 4 appelants (`trait-api-posts.php:327/420/565/672` — create, update, `get_article_blocks`, delete) répondent `404 post_not_found`. Le listing et `blocks/usage` servent pourtant ces mêmes posts : **la surface est incohérente avec elle-même**, c'est ça le vrai défaut.

**Décision (2026-08-01) : politique unique = `public` moins `attachment`**, strictement identique à celle
de `get_blocks_usage()`. Trois politiques divergentes cohabitaient ; il n'en reste qu'une, appliquée
sur toutes les coutures. Effet de bord découvert au passage : `attachment` est public *et* non
hiérarchique, donc l'ancienne garde le laissait passer alors que son docblock affirmait l'exclure.
La nouvelle garde le ferme pour de bon.

- [x] Autoriser les `post_type` hiérarchiques dans `is_allowed_post_type()` — `trait-api-posts.php:906-940`
- [x] Aligner `get_posts()` sur la même garde (`:30-47`) — le `post_type` de requête partait en `WP_Query`
      sans validation alors que `orderby`/`order` étaient allowlistés cinq lignes plus bas
- [x] Rejet **422 `forbidden_structural_field`** sur `post_parent` / `menu_order` / `page_template`,
      scanné au top-level, dans `meta` **et** dans `content.meta` (forme imbriquée, promue plus tard) —
      `class-post-builder.php:38-56` (constante) + `reject_structural_fields()`
- [x] Vérifier que les 4 appelants se comportent identiquement (garde partagée → correction partagée)
- [x] Tests — `ContentTypePolicyTest.php`, 34 tests / 97 assertions

**Non-vacuité vérifiée** (4 mutants, chacun tue des tests) : politique remise à `public && !hierarchical`
→ 21 rouges ; garde `get_posts()` retirée → 3 ; rejet 422 retiré → 9 ; `build_post_data()` qui émet une
clé hors liste → 1.

**Verrou anti-refactor.** Le 422 est un *signal*, pas la barrière. La barrière, c'est que
`build_post_data()` construit sa payload clé par clé (construction positive, jamais copy-then-filter).
Les deux propriétés sont testées **séparément** : un refactor vers un filtre garderait le test du 422
au vert tout en rouvrant le trou pour tout champ que le filtre oublierait.

### 41.2 — Preview de révision rendue au mauvais template

**Confirmé** : `class-preview.php:434-436` construit les candidats depuis `$post->post_type`. Pour une révision, ce post **est** le `aa_revision` → candidats `single-aa_revision*.php` → repli générique. Observé : body class `single-aa_revision postid-88553`, contre `single-page-investir page-investir-template-default` en live.

Conséquence : le client valide la révision dans un template qui n'est pas celui de la page — **HITL aveugle** sur des pages à layout riche. Touche aussi les articles, moins visiblement.

**Deux défauts adjacents trouvés dans la même fonction**, aussi corrigés : elle ne lisait jamais
le gabarit assigné en éditeur (`get_page_template_slug()`), et elle n'avait **aucune branche
`page-*.php`** — même une preview de page simple tombait sur `single.php`, un template que
WordPress ne choisirait jamais pour elle.

- [x] Résoudre le contexte de rendu depuis le **post parent** — `resolve_render_context()`,
      `class-preview.php`. Guard null conservé : rien ne cascade la suppression d'une révision
      quand le parent disparaît, une révision orpheline retombe sur son propre contexte.
- [x] Hiérarchie fidèle à WordPress : gabarit éditeur d'abord (tout type, WP ≥ 4.7), puis branche
      `page-{slug}/page-{id}/page.php` pour `page`, branche `single-*` sinon
- [x] `queried_object` = le parent, la boucle = la révision — c'est le `queried_object` que lisent
      `body_class()` et `is_page()`. `is_page`/`is_single` positionnés depuis le contexte.
- [x] Fallback minimal Phase 19 non touché (le chemin `render_fallback()` est inchangé)
- [x] Rapport `aa_debug=1` étendu d'une section `render_context` (`is_revision`, `context_id/type/name`,
      `parent_id`, `parent_missing`, `template_slug`) — sans elle le correctif est invérifiable sur
      site client : le rapport montrerait les bons candidats sans dire pourquoi
- [x] Tests — `PreviewRenderContextTest.php`, 15 tests

**Non-vacuité vérifiée** (5 mutants) : contexte toujours le post lui-même → 3 rouges ; gabarit éditeur
ignoré → 3 ; branche page supprimée → 4 ; `queried_object` remis sur la révision → 1 ; `is_page`
jamais posé → 1.

**Stub corrigé** : `get_page_template_slug()` retournait `''` en dur en ignorant son argument — toute
assertion sur le gabarit aurait été vacante. Rendu configurable via `$_test_page_template_slugs`.
`WP_Query::$is_page` ajouté au stub (déclarée dans le vrai `WP_Query`).

### 41.3 — `word_count` = 0 sur les posts à blocs ACF

**Confirmé** : `trait-api-formatters.php:47-48` → `str_word_count( wp_strip_all_tags( $post->post_content ) )`. Quand le contenu vit dans les attributs de blocs ACF, `post_content` ne porte que des commentaires de bloc → 0.

Ce n'est pas une donnée manquante mais un **faux signal** : un audit qui lit `word_count = 0` conclut « thin content » sur une page de 30k caractères. **Absence de champ préférable à zéro.**

- [x] **Omission** retenue (pas `null`) — `count_words()` retourne `null`, la clé est retirée de la
      réponse. Décision AA : « absence de champ préférable à zéro ».
- [x] Comptage depuis les blocs parsés **écarté** : coûterait un `parse_blocks()` par post dans le
      listing. À noter — l'idée initiale de compter depuis `get_field_values_for_post()` **ne marchait
      pas** : cette fonction retourne les champs ACF *post-level*, le contenu des blocs vit dans
      `$block['data']` à l'intérieur de `post_content`.
- [x] **Défaut adjacent corrigé** : `str_word_count()` traite les octets accentués comme des séparateurs
      — « Réhabilitation énergétique » comptait **4** mots au lieu de 2. Remplacé par un `preg_split`
      sur `\s+` avec le flag `/u`. Profite à tous les posts, pas seulement aux pages business.
- [x] Tests — `WordCountTest.php`, 16 tests

**Non-vacuité vérifiée** (3 mutants) : `0` au lieu de `null` → 6 rouges ; retour à `str_word_count()`
→ 4 ; `unset` retiré → 6.

**Test vacant repéré au passage** : `FormattersTest::test_format_post_structure` comparait un tableau
écrit à la main **avec lui-même** (`assertCount(21, $expected_fields)`) — il serait resté vert à travers
n'importe quel changement du formateur. La vraie assertion, pilotée par la sortie de `format_post()`,
est maintenant dans `WordCountTest::test_format_post_payload_shape`. L'ancienne est conservée comme
documentation, avec sa nature déclarative écrite noir sur blanc.

⚠️ **Changement de contrat à annoncer à AA** : `word_count` peut désormais être absent de la réponse.

### 41.4 — Release groupée

- [x] **Phase 41 + Phase 40** livrées dans la même release — v0.2.0
- [x] `./build.sh 0.2.0` — 15 gates verts, zip produit, 556 tests
- [x] Annonce écrite dans `backlog-for-backend.md`
- [ ] Campagne de déploiement sur les 3 sites (iSelection preprod + www, trottinette)

**`build.sh` étendu.** Le script n'incrémentait que le patch, donc `0.2.0` était hors de sa portée et
il aurait fallu éditer les trois sources de version à la main — précisément la dérive que son check #12
existe pour attraper. Il accepte maintenant une version cible explicite (`./build.sh 0.2.0`), validée
en format et **strictement supérieure** à la courante (re-publier un numéro, c'est comment deux zips
différents finissent par se déclarer identiques). Un seul écrivain des versions, toujours.

### Vérification sur site client — ✅ FAITE par AA sur préprod (sondes des 2026-08-04 et 08-05)

Vérifiée **en fait**, pas en lecture de code, sur préprod iSelection en 0.2.1 :

- [x] **41.1** — `GET /contents/{id}/blocks` sur un `page` (hiérarchique) : `404 post_not_found` → **200,
      14 blocs**. `GET /contents` répond (la route n'existait pas avant)
- [x] **41.2** — preview de révision rendue **avec le template du parent**. `body_class` relevé :
      `page-investir-template-default single-page-investir postid-20858`, **aucune occurrence de
      `aa_revision`**, un seul `<h1>`, titre correct. AA a retiré la mention « à corriger » de son
      invariant 4 — le défaut qu'ils décrivaient était celui que la Phase 41.2 a fermé
      ⚠️ **Portée exacte de ce ✅ (précisé le 2026-08-07) : le template, pas le contenu.** La sonde
      mesurait la résolution de gabarit. Une seconde sonde AA (08-06) montre que la preview rend
      **vide** — 0 `<p>`, 0 `<h2>`, pas de header/footer : les champs ACF ne sont pas résolus. Le
      routage reste correct. Voir **Phase 43**, qui ne remet pas 41.2 en cause mais en borne la portée
- [x] **41.3** — `word_count` : `0` sur des pages à 30k caractères → **clé absente**. AA a corrigé son
      côté (le défaut `0` de leur parseur reconstruisait le faux signal qu'on venait de retirer)
- [x] **Invariant 4 (révisions tout `post_type`)** — `PUT /articles/20858` (CPT `page-investir`, publié)
      avec un body ne portant **que** `acf_fields` → `201 revision_created`, révision 92200, post live
      strictement inchangé (status, slug, url, **et les 12 champs**). Le chemin révision tient sur une
      page business, pas seulement sur `article`
- [x] **40** — `curl -i` sur `/articles` montre `Deprecation` + `Sunset` ; sur `/contents`, aucun des deux.
      Vérifié le 2026-08-07 sur trottinette (0.3.0) : `/articles` renvoie `deprecation:` et `sunset:` =
      `Mon, 01 Feb 2027 00:00:00 GMT` + `link: <…/contents>; rel="successor-version"` ; `/contents` ne
      porte que le `link` WP standard. Les en-têtes sont émis avant l'auth (relevé sur un `401`)

### 🔴 Trou découvert par la sonde AA : la surface REST des révisions est en lecture seule

AA a laissé la révision pending **92200** sur le post 20858 et ne peut pas s'en défaire par l'API.
Vérifié dans le code : `class-api.php:238-256` n'expose que `GET /{id}/revisions` et
`GET /{id}/revisions/{revision_id}`. `approve_revision()` et `reject_revision()` existent
(`class-revisions.php:243` et `:371`) mais ne sont atteignables **que** par AJAX wp-admin
(`arcadia-agents.php:156-157` → `class-revision-metabox.php`).

Conséquence : chaque sonde d'écriture d'AA sur un post publié laisse un résidu qu'un humain doit
nettoyer à la main. Ça rend leur répétition e2e coûteuse, et ça s'aggrave à chaque itération.

**Distinction à trancher — les deux verbes ne sont pas symétriques :** ⬅️ Oscar
- **`reject` par REST** ne casse rien : l'agent retire *sa propre* proposition. Le contenu live n'est
  jamais touché, la garantie HITL est intacte. C'est du nettoyage, pas de la publication.
- **`approve` par REST casserait le sens du dispositif** : les révisions en attente existent
  précisément pour qu'un *humain* valide avant mise en ligne. Un agent qui approuve ses propres
  révisions contourne la seule protection que le client a demandée.

- [ ] Décider : exposer `DELETE`/`POST /contents/{id}/revisions/{revision_id}/reject` seul, ou rien
- [ ] En attendant, deux résidus à traiter à la main dans l'admin préprod :
      - `92200` sur le post `20858` → **à rejeter**
      - `92277` sur le post `21495` → **à approuver** : elle restaure la valeur d'origine de la page
        de test d'AA, l'approuver remet la page dans son état initial

**Le coût s'accumule comme prévu.** Un résidu au 08-05, deux au 08-07. Chaque sonde d'écriture d'AA en
ajoute un, et aucun ne part sans un humain dans wp-admin. C'est l'argument principal pour trancher la
décision `reject` par REST ci-dessus, et AA a confirmé que `reject` seul les débloquerait.

### Anomalie non élucidée (à surveiller)

Un run de `./build.sh` a rapporté **1 test en échec** (556 tests, 1789 assertions au lieu de 1791).
Je n'avais capturé que la fin de la sortie, donc l'identité du test est perdue. **Non reproduit en
45 exécutions** ensuite — dont 5 passes complètes du pipeline de build et 3 cycles reproduisant le
va-et-vient `composer --no-dev` / restore. Rien n'indique un défaut du code livré, mais l'anomalie
est réelle et n'a pas d'explication. Si elle revient : capturer **toute** la sortie du build, pas le tail.

---

## Phase 42 : Intégrité d'écriture des champs — 4 défauts routés le 2026-07-30, absents de v0.2.0

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-08-05*
*Routés par AA le 2026-07-30 (commits `ff4b324e`, `9a20f793`) avec la mention « ⏸ à grouper avec le lot post_type ».*

### Pourquoi ce lot existe

Les Phases 40 et 41 ont ouvert la surface contenu aux `page` / CPT éditoriaux. Ces 4 défauts sont ce qui
rend cette surface **inutilisable en pratique sur ces mêmes pages** : la garde `post_type` laisse
désormais passer un `PUT` sur une page business, et ce `PUT` perd ou recroise des champs en silence.
Ils devaient partir dans la même release ; ils ont été oubliés à l'intégration. v0.2.0 et v0.2.1 sont
donc parties **incomplètes** — d'où le blocage du déploiement jusqu'à ce lot.

**Le fil commun.** Trois des quatre défauts sont la même erreur d'architecture : *un chemin d'écriture
secondaire qui ne rejoue pas le traitement du chemin principal*. La correction structurelle est unique
— **un seul pipeline de traitement des champs, appelé depuis tous les points d'écriture** — pas quatre
rustines. Traiter 42.1 et 42.2 séparément reconstruirait la divergence qu'on est en train de fermer.

### 42.1 — 🔴 Approuver une révision produit le même état qu'un `PUT` direct — ✅ FAIT

**Tous les pointeurs AA confirmés dans le code.** `approve_revision()` bouclait sur `update_field()` en
brut sans passer par `process_acf_fields()` — donc markdown jamais converti, URL d'image jamais
sideloadée, `wysiwyg: null` stocké tel quel, et `apply_field_schema_mappings()` jamais rejoué.

**La racine était plus large que le rapport.** `class-revisions.php:270-352` ne divergeait pas seulement
sur les champs ACF : c'était une **réimplémentation complète** du pipeline d'écriture — titre, extrait,
meta Yoast, image à la une, taxonomies, ACF, chacun recopié à la main. Corriger champ par champ aurait
laissé la divergence en place et garanti son retour.

- [x] `approve_revision()` **délègue à `Arcadia_Post_Builder::finalize_post()`** — exactement l'appel que
      fait le `PUT` direct (`trait-api-posts.php`). Un seul pipeline, deux appelants. **−114 lignes.**
- [x] Ce qui suit vient gratuitement avec la délégation : coercions ACF, field-schema, meta SEO,
      taxonomies, image à la une, `_acf_changed` / `acf/save_post`, render test
- [x] **Parité complète des modes d'échec** (décision Oscar) : approuver hérite des erreurs du `PUT`.
      Un render test rouge signale un vrai défaut de template — mieux vu à l'approbation qu'en prod
- [x] **`skip_markdown` persisté dans le payload de révision** — le flag appartient à la requête
      d'origine et l'approbation n'a pas de requête. Sans lui, un contenu round-trip (déjà HTML) était
      re-parsé en markdown à l'approbation : exactement l'asymétrie que la phase ferme
- [x] Le titre du CPT révision ne retombe plus sur `meta.title` (c'est un libellé wp-admin, pas un H1)
- [x] Tests — `FieldWriteIntegrityTest.php` : markdown → `<h2>`/`<strong>`, `wysiwyg: null` → recopie du
      contenu rendu, champ `text` non converti, `skip_markdown` honoré, persistance du flag

### 42.2 — 🔴 Un `PUT` sans `acf_fields` ne vide plus les champs — ✅ FAIT

**Confirmé** : `finalize_post()` appelait `auto_populate_acf_fields()` dès que `acf_fields` était absent,
et cette fonction écrit `''` dans **tous** les champs `wysiwyg`/`textarea` du post type. Le docblock
affirmait « Does NOT inject content » — vrai sur l'intention, faux sur l'effet : écrire `''` dans un
champ peuplé, c'est le détruire.

- [x] `else` → `elseif ( $is_create )` (`class-post-builder.php`). L'auto-remplissage est un filet de
      **création** (faire que `get_fields()` renvoie un array et non `false`) ; sur un post existant les
      références existent déjà, le filet n'a plus d'objet et ne fait que détruire
- [x] Un `PUT` partiel reste partiel : un champ que le body ne mentionne pas n'est jamais écrit
- [x] `POST` (création) inchangé — vérifié par un test dédié, pour ne pas sur-corriger en supprimant le
      filet là où il sert
- [x] Tests — update sans `acf_fields` → **aucun** appel `update_field` sur les champs du post type ;
      create sans `acf_fields` → auto-remplissage toujours actif

### 42.3 — 🟠 Un champ envoyé atteint une seule destination — ✅ FAIT

**2 sites, pas 3.** Phase 40 avait déjà supprimé le corps de `update_page()`, donc `trait-api-posts.php`
était propre. Restaient `class-post-builder.php` et `class-revisions.php` — et le second **disparaît de
lui-même** avec la délégation de 42.1, au lieu d'être corrigé deux fois.

- [x] `meta.title` → `_yoast_wpseo_title` **uniquement** ; `post_title` ne change que sur `body.title`
- [x] `meta.description` → `_yoast_wpseo_metadesc` **uniquement** ; `post_excerpt` ne change que sur
      `body.excerpt` (`isset`, pas `empty` : un `""` explicite reste une valeur fournie qui vide le champ)
- [x] Tests **inversés** : `TitleSeoSeparationTest` et `ExcerptTest` asserted la retombée. Ils asserted
      maintenant son absence, avec en plus le cas de production (un `PUT` ne portant que `meta.title`
      laisse le H1 live intact)
- [x] ⚠️ **Changement de contrat annoncé à AA** : un `PUT` sans `body.excerpt` laisse désormais
      `post_excerpt` inchangé au lieu d'y écrire la meta-description — visible sur les thèmes qui
      affichent l'extrait

### 42.4 — 🟡 `PUT /field-schema` valide les `source` et redevient réversible — ✅ FAIT

- [x] Une `source` inconnue → **400 `invalid_mapping_source`**, avec la liste des valeurs acceptées dans
      le message **et** dans `data.allowed_sources`
- [x] **Poka-yoke** : `Arcadia_API::mapping_sources()` est la source unique, lue par la validation **et**
      par l'écriture. Un test structurel assert que la liste déclarée et la table de valeurs
      d'`apply_field_schema_mappings()` coïncident — une source ajoutée d'un seul côté serait soit
      inatteignable, soit acceptée puis ignorée : le défaut même qu'on ferme
- [x] **Méthode, pas constante** : les constantes de trait exigent PHP 8.2, le plugin cible **8.0**
- [x] **Dé-calibration par `null`** (décision Oscar) : `{"page": {"champ": null}}` retire la clé. Le
      chemin de lecture traitait déjà un mapping vide comme « non calibré » — il ne manquait que le
      verbe d'écriture. Le `PUT` n'est plus purement additif
- [x] Tests — source inconnue refusée, chaque source déclarée acceptée, accord déclaration/écriture,
      `null` retire, `null` ne retire **que** le champ nommé

### 42.5 — Vérification & release — ✅ FAIT

- [x] **Non-vacuité : 7 mutants, 7 tués.** Filet remis inconditionnel → 1 rouge (et la sortie montre
      `chapo`/`notes` écrasés par `''`) ; rejeu brut restauré → 4 ; `skip_markdown` ignoré → 1 ;
      validation de source retirée → 1 ; `null` sans effet → 2 ; source fantôme déclarée → 1 ;
      retombée `meta.title`/`meta.description` restaurée → 4
- [x] Suite complète : **572 tests / 1829 assertions**, zéro warning (556 → +16)
- [x] PHPStan local (`memory_limit=3G`) — **No errors**
- [x] `./build.sh 0.3.0` — **15 gates verts**, zip 390KB
- [x] Annonce écrite dans `backlog-for-backend.md`
- [x] **Déploiement manuel sur les 3 sites — FAIT**, vérifié par `GET /health` le 2026-08-07 :
      trottinette **0.3.0**, iselection/b2c **0.3.0** (il était encore en 0.2.1 au relevé du 08-06),
      préprod **0.3.0** (non sondable de l'extérieur, `401` d'auth staging — relevé AA du 08-06)
- [x] Avertissement « ne pas pousser sur les pages business » **levé** dans `backlog-for-backend.md`

---

## Contexte de déploiement — relevé du 2026-08-07

**v0.3.0 est live partout.** Relevé nous-mêmes, sans dépendre d'AA :

```bash
curl -s https://<site>/wp-json/arcadia/v1/health   # public, pas de JWT
```

| Site | Version live (2026-08-07) | Note |
|---|---|---|
| www.iselection.com/b2c | **0.3.0** | déployé entre le 08-06 (0.2.1) et le 08-07 |
| www.trottinette-tout-terrain.fr | **0.3.0** | le `500` signalé par AA était réel, **réparé par Oscar** |
| preprod-iselection.vertuelle.com | **0.3.0** (relevé AA du 08-06) | non sondable : `401` = auth HTTP de staging, ni plugin ni Cloudflare |

*Historique : au 2026-08-05, les trois étaient en 0.2.1 — la version incomplète que la Phase 42 corrige.*

**`GET /health` est le moyen canonique de savoir ce qui tourne chez un client** — public, sans auth,
renvoie `ARCADIA_AGENTS_VERSION` (`arcadia-agents.php`). Ne plus jamais inférer une version déployée ni
attendre un relevé d'AA : la question se tranche en une commande.

### Deux croyances corrigées

1. **Le chemin de déploiement était une fausse énigme.** Les mises à jour sont faites **manuellement par
   Oscar**. Il n'y a pas de pipeline mystérieux à élucider : le zip est déposé à la main sur chaque site.
2. **Le relevé AA du 2026-07-30 (« 0.1.37 ») est périmé**, pas faux — il précède de trois jours le zip
   0.2.1 (2026-08-02). `deployed-versions.md` côté AA est donc à considérer comme un instantané daté,
   jamais comme l'état courant.

**Trottinette : le `500` rapporté par AA était réel** (leur diagnostic était bon), **et Oscar a réparé le
site** depuis. Vérifié le 2026-08-05 : `200` + `0.2.1`. AA peut reprendre ses relevés dessus.

### ⚠️ Ce que ça change pour la Phase 42

La Phase 42 n'est **pas** un travail à finir avant déploiement : c'est un **correctif sur du code déjà en
production**. Avant la Phase 41, `PUT` sur un `post_type` hiérarchique répondait `404` — les pages
business étaient inécrivables, donc protégées **par accident**. Depuis v0.2.0 l'appel réussit, et perd
des champs en silence (42.1 / 42.2). On a rendu ces pages writables et lossy dans la même release.

- [x] Avertissement écrit dans `backlog-for-backend.md` : ne pas pousser sur les pages business avant la
      release Phase 42, et vérifier les champs ACF de toute page déjà poussée depuis le 2026-08-02
- [x] Livrer la Phase 42, builder, déployer (manuellement) sur les 3 sites, puis lever l'avertissement —
      **bouclé le 2026-08-07**, avertissement levé dans `backlog-for-backend.md`
- [x] **Fenêtre 08-02 → 08-07 : rien à réparer, tranché par AA.** Leurs seules écritures sous 0.2.1
      sont les sondes du 08-05 sur le post `20858`, non destructives par construction (valeur relue puis
      réinjectée telle quelle) et vérifiées après coup : `status`/`slug`/`url` identiques, **0 champ
      modifié**. Aucune écriture de contenu réel n'est partie pendant la fenêtre
- [x] **Le rejeu à l'approbation (42.1) est vérifié en live par AA**, pas seulement en test unitaire :
      post `21495` (`page`, publié, 19 blocs), `PUT` → révision `92276` → approbation depuis le metabox
      → relecture. La valeur proposée est rendue, **rayon d'impact nul par ailleurs** — 19 blocs
      identiques, 9 champs post-level intacts **y compris l'objet `image`** (tableau ACF de 1 322
      caractères), soit exactement ce que la boucle `update_field()` brute abîmait. Le live est resté
      strictement inchangé entre le `PUT` et l'approbation

---

## Phase 43 : Une révision en attente ne dit pas ce qu'elle propose

*Ref: [backlog.md](/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/backlog.md) — intégré 2026-08-07*
*Item neuf, mesuré par AA le 2026-08-06 sur préprod en **0.3.0**. Il avait été rédigé le 06 sur une
branche AA qui n'a pas atteint `main` — d'où le fait qu'on ait vidé le backlog sans l'avoir vu.*

### Pourquoi ça compte

Le HITL est le **chemin nominal** du chantier pages business : les pages cibles sont toutes publiées,
donc 100 % des écritures AA deviennent des révisions à approuver. Aujourd'hui, ni le validateur humain
ni l'agent qui a proposé ne peuvent lire la proposition avant de décider. On demande à un client de
valider à l'aveugle.

### Ce qu'AA a mesuré (post `20858`, `page-investir`, 12 blocs, révision `92200`)

| | live | preview |
|---|---|---|
| octets | 72 432 | 10 963 |
| `<p>` | 27 | **0** |
| `<h2>`/`<h3>` | 8 | **0** |
| `<header>` / `<footer>` | oui | **non** |
| `<h1>` | « Dispositif fiscal LMNP (géré) » | « Dispositif fiscal LMNP » |
| `body_class` | `single-page-investir postid-20858` | identique |

### Diagnostic — vérifié dans le code, les trois pointeurs AA sont exacts

Le routage est bon (Phase 41.2 tient : bon gabarit, bonnes classes). **C'est le contenu qui manque.**

1. `create_revision()` insère le CPT `aa_revision` avec `post_title` + `post_content` seulement
   (`class-revisions.php:122-133`) et range le body complet en JSON dans `_aa_revision_meta`
   (`:158-170`). **Aucun `acf_fields` n'est écrit en meta sur la révision** — ils n'existent que dans
   le JSON, rejoué à l'approbation.
2. `setup_preview_state()` fait boucler `wp_query` sur la révision (`class-preview.php:263-268`) —
   choix délibéré et commenté (« the loop yields the revision — that's the content under review »).
   Tout `get_field()` du thème résout donc sur la révision, qui n'a aucun champ, et rend vide. Le
   `<h1>` le confirme indépendamment : il retombe sur `post_title` faute de `title_overrided`.
3. `format_revision()` (`class-revisions.php:489-517`) ne renvoie que de la métadonnée — pas un octet
   de contenu proposé.

**La formule d'AA est la bonne :** la preview tente de rendre un **delta** comme si c'était une page
complète.

### Décision de conception commune — un seul constructeur, trois consommateurs

**Nouveau fichier `includes/class-revision-diff.php`.** C'est le même geste que 42.1 (`approve_revision()`
délègue à `finalize_post()`) : REST, bannière classique et panneau Gutenberg lisent **la même
projection**. Trois formatages indépendants auraient dérivé, et un diff qui contredit l'écriture est
pire que pas de diff.

**Invariant dur tenu : construire un diff n'a aucun effet de bord.** Les trois consommateurs sont un
`GET` ou un rendu d'écran. Les coercions sont **nommées**, jamais appliquées — appeler
`process_acf_fields()` sideloaderait une image, et un `GET` qui crée des médias serait un défaut plus
grave que celui qu'on ferme. Un test le garde (`test_building_a_diff_writes_nothing`).

### 43.1 — 🔴 Exposer la proposition dans `GET /contents/{id}/revisions/{rid}` — ✅ FAIT

- [x] Une entrée par champ touché : `field`, `label`, `kind`, `current` (valeur live), `proposed`
      (brute, telle qu'envoyée), `transform`, `origin`, `source`
- [x] **Le listing reste à la métadonnée** : `format_revision( $rev, $include_changes = false )`.
      Le défaut `false` est le poka-yoke — seul le handler de détail bascule à `true`. Test dédié
      (`test_revisions_listing_carries_no_diff`) : lister 20 révisions ne doit pas construire 20 diffs
- [x] **`transform` dit ce qui sera réellement stocké** — `markdown_to_html`, `copy_rendered_content`,
      `sideload_image`. Un écho brut de `acf_fields` mentirait : le markdown est converti, l'URL d'image
      devient un attachment ID. Un test structurel vérifie que le descripteur et la coercion sont
      d'accord cas par cas
- [x] **Les écritures implicites du field-schema sont surfacées** (`origin: field_schema`, avec la
      `source` dont la valeur dérive). Ces champs calibrés changent du contenu sans que le payload les
      nomme — c'était invisible à 100 %
- [x] **`body.status` volontairement absent** : `approve_revision()` ne l'applique jamais. L'annoncer
      comme « proposé » serait faux sur le seul écran dont le métier est d'être fiable. Signalé à AA
- [x] Contenu de blocs → booléen `content_changed`, pas un diff (markup Gutenberg illisible ;
      c'est l'argument de la décision 2026-04-05). Question de périmètre posée à AA

### 43.2 — 🟠 Le diff dans les deux surfaces admin — ✅ FAIT

- [x] **Bannière éditeur classique** : `<details>` natif + tableau avant/après, ouvert d'office
      jusqu'à 5 champs. Styles inline — le plugin n'a aucun fichier CSS ni précédent d'accordéon
- [x] **Panneau Gutenberg** à parité stricte : même liste, transportée par `wp_localize_script`.
      Les deux surfaces rendent `to_display_rows()` — la parité est **structurelle**, pas une
      coïncidence entre deux implémentations
- [x] Valeurs aplaties et tronquées à 300 caractères ; `—` distingue « aucune valeur stockée » de
      « stockée, et vide » (la nuance qui dit si un changement est destructeur)
- [x] Chaque coercion affiche sa phrase (« le markdown sera converti », « l'image sera importée »)

### 43.3 — 🟡 La preview résout les champs (repli sur le parent) — ✅ FAIT

- [x] **Superposition en lecture seule** via un filtre `get_post_metadata`, armé uniquement pendant
      le rendu d'une preview de révision. Une seule règle : la proposition gagne quand elle est
      présente, sinon le parent. Rien n'est écrit, la superposition meurt avec la requête
- [x] **Les paires ACF suivent** (`<nom>` + `_<nom>`) — c'est précisément parce que la règle est
      générique, et pas une liste de champs, qu'elles sont couvertes
- [x] **Les meta internes ne retombent jamais** (`_aa_revision_*`, `_aa_preview_*`, `_edit_*`) :
      le token de preview du parent résolu sur la révision serait un défaut de sécurité
- [x] **Lecture en bloc fusionnée** (`$meta_key === ''`) — ACF amorce son cache par là ; ne répondre
      qu'aux lectures unitaires aurait laissé la moitié des champs vides
- [x] **Coercion réutilisée, pas dupliquée** : extraction de `coerce_field_value()` sur le chemin
      d'écriture, appelée par `process_acf_fields()` et par la preview. Dupliquer les trois règles
      aurait reconstruit la divergence que la Phase 42 vient de fermer
- [x] ⚠️ **Limite documentée** : un champ `image` proposé en URL n'a pas encore d'attachment ID, et
      en fabriquer un veut dire importer le fichier. La preview affiche donc **l'image du parent**,
      et c'est le diff qui annonce le changement. Un rendu de page ne crée pas de média

### 43.4 — Vérification & release — ✅ FAIT

- [x] **Non-vacuité : 9 mutants, 9 tués.** Garde meta interne retirée → rouge ; diff activé par défaut
      sur le listing → rouge ; image URL non déclinée → rouge ; `status` reporté → rouge ;
      `skip_markdown` ignoré → rouge ; la proposition ne gagne plus → rouge ; champs calibrés non
      surfacés → rouge ; `null` indistinguable de `""` → rouge ; lecture en bloc non fusionnée → rouge
- [x] Suite complète : **605 tests / 1904 assertions** (572 → +33), zéro warning
- [x] **Bootstrap de test rapproché du vrai WordPress** : `get_post_meta()` applique désormais le
      filtre `get_post_metadata` et renvoie la forme brute de core sur une lecture en bloc. Sans ça,
      la superposition était **intestable** — elle ne fonctionne qu'en répondant à ce filtre, donc un
      stub qui l'ignore aurait affiché un test vert sur du code jamais exécuté
- [x] PHPStan local (`memory_limit=3G`) — **No errors**. Deux entrées de baseline devenues orphelines
      supprimées, et le code mort qu'elles couvraient (`0 === $value` après `empty()`) retiré
- [x] `./build.sh 0.4.0` — **15 gates verts**, dont le check de fidélité sur vrai WordPress. Zip 401KB
- [ ] Déploiement manuel sur les 3 sites
- [ ] Vérification sur préprod (voir ci-dessous)

### Vérification de sortie — séparer les deux natures de changement

On a empilé un changement d'API et un changement de rendu dans le même zip (réserve exprimée, levée
par Oscar). La vérification les sépare donc explicitement :

- [ ] **API seule** : `GET /contents/20858/revisions/92200` renvoie `changes` avec les champs touchés
      et leur valeur courante. Ne touche à aucun rendu — vérifiable sans regarder une page
- [ ] **Rendu** : ouvrir la preview de `92200`. Attendu — page **complète** (proche des 72 432 octets
      du live, header/footer présents, `<p>` et `<h2>` non nuls), avec le seul champ proposé à sa
      nouvelle valeur. C'est le critère de sortie qu'AA a écrit
- [ ] **Admin** : la bannière déplie l'avant/après ; le panneau Gutenberg montre la même chose

### Correction à acter dans nos propres notes

AA nous avait écrit le 2026-08-05 « la preview est bonne, rien ne vous attend là-dessus », et on l'a
recopié tel quel dans la vérification 41.2. C'était trop large **des deux côtés** : la sonde mesurait
la **résolution du template**, jamais le **contenu** rendu. Le template est bon, le contenu est vide.
Voir la note ajoutée en 41.2.

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
