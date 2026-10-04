# Archive des phases terminées (27+)

Archive glissante **append-only** : une phase quitte [`../checklist.md`](../checklist.md) et
atterrit ici quand **toutes** ses cases sont cochées. Les phases 0–26 sont dans
[`checklist-phases-0-26.md`](checklist-phases-0-26.md).

Contenu déplacé tel quel, sans réécriture — c'est la trace de travail d'origine.

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


## Phase 45 : Le build teste enfin la seule opération que vivent les clients — la mise à jour

*2026-08-09. Question d'Oscar : « build.sh suffit-il pour ne rien casser chez les clients ? »
Constat : 16 gates, tous sur un zip installé À NEUF dans UN environnement. Or un client n'installe
jamais à neuf — il upgrade. Aucun gate ne testait la transition N-1 → N.*

### 45.1 — Upgrade-path test (nouveau gate #15)

- [x] **Stack éphémère** `test/upgrade/docker-compose.upgrade.yml` : WordPress jetable (`upgrade-wp`,
      volume détruit par `down -v`), zéro port publié. Le WP de dev est inutilisable pour ça : son
      plugin est un bind-mount des sources, y dézipper écraserait l'arbre de travail
- [x] **Pas de deuxième MySQL.** Tenté deux fois : sur tmpfs le serveur crash à l'init, sur volume
      l'init prend 3 min et le daemon dépasse son timeout — une machine qui fait tourner ~24
      containers étouffe un deuxième mysqld. Le test rejoint le réseau du stack de dev et utilise
      son MySQL avec une base jetable `wordpress_upgrade_test` (créée/droppée par le runner). Le
      stack de dev est déjà exigé par le gate #1
- [x] **Scénario client réel** : installer le zip N-1 (le plus récent de `dist/`) → activer → seeder
      (options de connexion, post au contenu adversarial — backslashes, quotes, accents, emoji —,
      meta JSON) → `wp plugin install --force` du zip candidat (fichiers remplacés, plugin actif :
      l'opération exacte d'un update client) → asserter : plugin actif, `/health` sur la nouvelle
      version, contenu/meta/options intacts à l'octet, CPT `aa_revision` enregistré, zéro fatal PHP
- [x] **`dist/` archive chaque zip buildé** (git-ignoré) : baseline du build suivant + rollback
      immédiat. Premier build sans baseline → mode `--fresh-only` (zip validé sur WP vierge)
- [x] Piège DNS évité : sur le réseau partagé, un service nommé `wordpress` serait en collision
      d'alias avec le container de dev — les requêtes du test pourraient atterrir sur le mauvais
      WordPress. D'où `upgrade-wp`

### 45.2 — Vérification & release

- [x] Run standalone prouvé : `./test/upgrade/run-upgrade-test.sh dist/arcadia-agents-0.5.1.zip
      arcadia-agents.zip 0.5.1`
- [x] `./build.sh` complet (0.5.2, patch : contrat identique, seule la conformité du pipeline
      s'améliore) — prouve le gate intégré de bout en bout

*Trois bugs réels trouvés au premier run honnête (aucun lié à la machine) : (1) le client MariaDB
de `wordpress:cli` ne peut pas parler à MySQL 8 — vérification TLS par défaut puis plugin
`caching_sha2_password` absent — donc le probe `wp db check` échouait éternellement ; remplacé par
« wp-config.php existe », c'est `wp core install` (PHP/mysqli) qui valide la connexion DB. (2)
`www-data` = uid 82 (Alpine, cli) vs 33 (Debian, wordpress) → cli incapable d'écrire dans le volume ;
fix `user: "33:33"` + `HOME=/tmp`. (3) Les `&>/dev/null` rendaient tout ça indiagnostiquable — le
script imprime désormais probe + logs du conteneur au timeout, et la sortie des installs en échec.*

---

## Phase 44 : Les deux décisions ouvertes, tranchées

*Arbitrage Oscar, 2026-08-08. Les deux dormaient dans `backlog-for-backend.md` depuis le 08-07.*

### 44.1 — `reject` par REST — ✅ FAIT

`POST /contents/{id}/revisions/{revision_id}/reject`, corps optionnel `{ "decision_notes": "…" }`.

- [x] **Pas de jumeau `approve`, et c'est le cœur du design.** Retirer une proposition ne touche
      jamais le contenu publié ; approuver si. C'est la seule protection que le client a demandée en
      activant le dispositif — un agent qui approuve ses propres révisions la contournerait
- [x] **Scope dédié `revisions:write`**, pas `articles:write` : écrire du contenu et détruire une
      décision qu'un humain s'apprêtait à prendre sont deux pouvoirs différents. Deuxième ligne du
      tableau de routes à rompre le motif `articles:*`, après `featured-image` — donc épinglée par
      `ContentRouteParityTest`
- [x] ⚠️ **Le scope arrive désactivé sur les 3 sites.** `arcadia_agents_scopes` n'utilise la liste
      complète que si l'option n'a jamais été enregistrée ; sur un site où la page Réglages a déjà
      été validée, un scope neuf est absent donc refusé. **Il faudra cocher la case sur chaque site**
      — c'est le bon défaut : une capacité nouvelle se donne, elle ne s'attrape pas en mettant à jour
- [x] **`decided_by` = `arcadia-agents-api`.** Le `sub` du JWT identifie le *site*, pas une personne :
      inscrire un login humain dans la piste d'audit serait faux. Une identité machine garde les
      retraits par API distinguables d'un clic dans wp-admin
- [x] La vérification d'appartenance (`revision->post_parent === id`) est **partagée** avec le
      handler de détail — sinon elle est présente sur l'un et oubliée sur l'autre, et n'importe
      quelle révision serait adressable via n'importe quelle URL de post

**Au passage — la liste des scopes existait en trois exemplaires.** `Arcadia_Auth::$all_scopes`, plus
deux copies dans `admin/settings.php` (dont la map de libellés, qui est ce qui **pilote réellement le
rendu**). Un scope ajouté au seul enforcement aurait été refusé par l'API et **incochable dans l'UI** :
403 permanent, sans indice. `Arcadia_Auth` possède maintenant `all_scopes()` et `scope_labels()`, un
test épingle que les clés coïncident dans le même ordre.

### 44.2 — `body.status` : 422 sur le chemin révision — ✅ FAIT

Ni « laisser tel quel » ni « appliquer à l'approbation » : **refuser explicitement**.

- [x] **Le fait qui tranche la question :** en mode HITL (`aa_force_draft`), `body.status` n'a *déjà*
      aucun effet nulle part — sur un post publié l'écriture devient une révision et le statut est
      perdu, sur un post non publié `build_post_data()` force `draft` de toute façon. Le défaut
      n'était pas l'absence d'effet, c'était le **silence** sur cette absence
- [x] Un `PUT` avec `status` sur le chemin révision renvoie **422 `status_not_supported_for_revision`**.
      Même précédent que `FORBIDDEN_STRUCTURAL_FIELDS` : un champ qui ne peut pas prendre effet se
      refuse, il ne se laisse pas tomber en silence
- [x] **Périmètre serré** : sans Force Draft, ou sur un post non publié, l'écriture s'applique
      directement et `body.status` est honoré comme avant. Test de non-vacuité dédié
- [x] **Ce qu'on n'a pas fait, et pourquoi.** Faire appliquer le statut à l'approbation ferait passer
      l'écran HITL de « ceci change du texte » à « ceci peut mettre une page business hors ligne ».
      Autre rayon d'action : il faudrait une ligne d'avertissement distincte dans le diff et une
      décision sur l'interaction avec `aa_force_draft`, sous peine de refaire diverger les deux
      chemins d'écriture (le défaut que 42.1 a fermé). À rouvrir **seulement** si AA répond qu'elle
      s'appuie sur `status`

### 44.3 — Vérification & release

- [x] **Non-vacuité : 7 mutants, 7 tués** (appartenance contournée, identité humaine dans l'audit,
      notes ignorées, 422 désarmé, 422 élargi à tous les chemins, scope élargi à `articles:write`,
      libellé de scope désynchronisé)
- [x] Suite complète : **638 tests / 2005 assertions**. PHPStan local — **No errors**
- [x] Changelog `readme.txt` rattrapé — il s'était arrêté à 0.2.1, trois versions en arrière
- [x] `./build.sh 0.5.1` — **16 gates verts**, zip 409KB. Bump mineur : nouvel endpoint, nouveau
      scope, et un `PUT` jusqu'ici accepté renvoie désormais 422
- [x] **Nouveau gate de build #12 : l'entrée de changelog doit exister avant le bump.** Le changelog
      a été écrit *après* `./build.sh 0.5.0`, donc l'entrée décrivait une autre version que le zip.
      Impossible à réparer sur place — les trois sources de version ne s'écrivent que par le script,
      qui refuse de re-couper un numéro. **0.4.0, 0.4.1 et 0.5.0 ont été brûlées comme ça** ; 0.5.1
      est la première dont le changelog soit exact. Le gate rend l'erreur impossible plutôt que
      rattrapable, et le changelog `readme.txt` — arrêté à 0.2.1 — est rattrapé en une entrée
      honnête qui dit que les trois versions intermédiaires n'ont jamais été publiées
- [x] Déploiement manuel sur les 3 sites — finalement en **0.5.2** (buildée le 2026-08-09) :
      `www.iselection.com` et `www.trottinette-tout-terrain.fr` (relevés `/health` 2026-08-09),
      preprod-iselection (confirmé par Oscar le 2026-08-09)
- [x] **Cocher `revisions:write`** dans Réglages sur chacun des 3 sites — fait par Oscar le
      2026-08-09
- [x] Annoncer à AA : chemin, scope, corps, codes de retour — contrat annoncé le 2026-08-08
      (v0.5.1), activation annoncée le 2026-08-09 dans `backlog-for-backend.md` : v0.5.2 live
      partout, scope actif, le résidu `92200` est rejetable par AA elle-même via REST

---

## Phase 51 : Route `POST /disconnect` — AA prévient le plugin quand le propriétaire déconnecte

*Source : backlog AA du 2026-10-03, intégré le 2026-10-04.*

Un propriétaire peut retirer la connexion WP depuis les réglages Arcadia (connexion, clé, paire
RS256 supprimées côté AA). Le plugin continue d'afficher « Connecté » et le champ de clé reste en
lecture seule : l'utilisateur doit deviner qu'il faut cliquer « Déconnecter » dans WP.

**Contrat demandé :**
- `POST /wp-json/arcadia/v1/disconnect`, JWT RS256 validé par `validate_jwt` / `validate_claims`
  (`sub` = `site_id` épinglé, `iss` épinglé). **Aucun scope requis.**
- Effet : `Arcadia_Auth::disconnect()` (`class-auth.php:584`) → état « non connecté », champ éditable.
- `200 {"success": true}`. **Idempotente** : si déjà déconnecté (plus de clé publique), `200` sans
  valider — l'appel ne peut rien casser.
- Côté AA : best-effort juste avant suppression de la paire ; `404`/`401`/timeout journalisés et
  ignorés. Rien ne casse avant déploiement. **AA attend la version qui porte la route.**

- [x] 51.1 — Route + `permission_callback` dédié (JWT valide sans scope ; `200` direct si non connecté)
- [x] 51.2 — Tests : JWT valide → déconnecté ; JWT d'un autre `site_id`/`iss` → `401` et **toujours
      connecté** ; déjà déconnecté → `200` ; JWT expiré → `401`. Passe de mutation.
- [x] 51.3 — `api-contract.md` : ajouter l'endpoint (via `backlog-for-backend.md`, pas d'écriture directe)
- [x] 51.4 — Release **MINOR** (nouvel endpoint) + annoncer la version dans `backlog-for-backend.md`

### Livré — v0.11.0 (2026-10-04)

- Route dans `class-api.php` (`register_connection_routes()`), handler + gate dans
  `includes/api/trait-api-connection.php`. Seule route hors `check_permission()` : JWT exigé, aucun scope.
- **« Connecté » = clé publique stockée** (`Arcadia_Auth::is_connected()`), pas le drapeau d'affichage
  `arcadia_agents_connected` : sans clé, aucun JWT n'est vérifiable.
- Hors connexion : `200` sans valider **et rien n'est touché** — la requête est alors non authentifiée,
  elle ne doit pas pouvoir effacer une `connection_key` en cours de saisie dans l'admin.
- `DisconnectEndpointTest` (13 tests) contre le **vrai** `Arcadia_Auth`, paire RSA réelle, jetons signés
  par `firebase/php-jwt` : autre clé, autre `sub`, autre `iss`, expiré, sans jeton → 401 **et toujours
  connecté** ; aucun scope coché → passe ; header `X-AA-Token` → passe ; déjà déconnecté → 200, état
  identique à l'octet. Mutants tués : gate toujours vraie, scope exigé, disconnect inconditionnel,
  `is_connected` sur le drapeau.

---

## Phase 52 : Repli `passthrough_block()` sans `null` — enfants après la balise fermante

*Source : backlog AA du 2026-09-15, intégré le 2026-10-04. Non urgent : AA a corrigé de son côté
(PR #350), quelle que soit la version du plugin. Ferme le trou à la source.*

**Symptôme en ligne** : trottinette-tout-terrain.fr, 19 pages avec `<ul class="wp-block-list"></ul>`
suivi des `<li>` hors liste.

**Cause plugin** : le repli legacy de `passthrough_block()` (`class-block-processor.php:331-344`)
prend le premier morceau comme ouverture. Avec un seul morceau (cas de tout conteneur lu par
`format_parsed_blocks()`), l'ouverture est l'enveloppe entière `<ul>\n\n</ul>` → enfants collés après.

- [x] 52.1 — Repli : couper à l'élément vide (ou ne contenant que des blancs) laissé par WP à
      l'emplacement des enfants (`<ul>\n\n</ul>`, `<div>` intérieur d'un groupe / `uagb/container`,
      conteneur intérieur d'un cover) — règle AA : `app/domain/seo/value_objects/block_children.py`.
      Si aucune coupure sûre : **refuser le bloc (422)** plutôt que casser la page en silence.
- [x] 52.2 — Lecture (`trait-api-posts.php:703-705`) : exposer aussi `innerContent` **avec ses `null`**,
      pour que les positions réelles voyagent. Le chemin null-aware de l'écriture existe déjà
      (forward-compat Phase 37). AA devra garder les `null` (`_wordpress_parsers.py:176`) → annoncer.
- [x] 52.3 — Tests sur enveloppes réelles (list, group, uagb/container, cover) + mutants
- [x] 52.4 — Release (52.1 = PATCH ; 52.2 ajoute un champ de réponse → MINOR) + annonce AA

### Livré — v0.11.0 (2026-10-04)

- `includes/class-block-children.php` : port ligne à ligne de `block_children.py` (emplacement vide le
  plus large, dernier en cas d'égalité, éléments void exclus ; sinon avant la fermante, `<cite>` /
  `<figcaption>` finale gardée dernière). Les chunks sont **joints puis coupés** : un mono-morceau et un
  multi-morceaux dé-nullé atterrissent pareil.
- **Pas de refus quand aucune coupure n'existe** (contrairement à ce que 52.1 envisageait) : la règle
  tombe toujours à l'intérieur du parent dès que l'enveloppe est un élément ; sans élément du tout, les
  enfants suivent le texte, sans perte. Le refus est réservé au seul cas illisible — placeholders en
  nombre ≠ enfants → **422 `child_position_mismatch`**, comme côté AA.
- Lecture : `innerContent` sur les parents seulement, retenu si un enfant ignoré décalerait les positions.
- Tests : `ChildPlacementTest` (24, fixtures AA), 3 dans `ArticleBlocksTest`, et **Case 4 de la gate
  réelle `fidelity-check.php`** (vrai `parse_blocks` → écriture → re-parse → `render_block`). Rejouée
  contre l'ancien repli, la Case 4 **reproduit le bug de trottinette** ; verte avec le nouveau.
  Mutants tués : égalité → premier, légende ignorée, repli fermante retiré, ancien repli, refus retiré,
  `innerContent` toujours / jamais / aussi sur les feuilles.

---
