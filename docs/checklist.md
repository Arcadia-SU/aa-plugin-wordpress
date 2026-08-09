# Plugin WordPress - Checklist de développement

**Dernière mise à jour :** 2026-08-09 (**v0.5.2 déployée sur les 3 sites**. Gate #15 étendu :
le build teste désormais N-1 **et** la plus vieille version déployée, `deployed-versions.conf` ;
rituel de déploiement écrit dans [`deploy.md`](deploy.md) ; CI durcie : matrice PHPUnit
8.1–8.3 + PHPCompatibility 8.0+ bloquant)

> **Prochain front de travail :** v0.5.2 est déployée sur **les 3 sites** (2026-08-09).
> Reste : (1) cocher `revisions:write` dans Réglages sur chaque site,
> (2) annoncer `reject` à AA (chemin, scope, corps, codes de retour). Détail en Phase 44.

> **Archives :** une phase quitte ce fichier quand **toutes** ses cases sont cochées.
> Phases 0–26 → [`archives/checklist-phases-0-26.md`](archives/checklist-phases-0-26.md) ·
> Phases 27+ → [`archives/checklist-archive.md`](archives/checklist-archive.md) (append-only)

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
| 27 | ACF Pro repeater flat-keys en PUT (symétrie GET/PUT) | 2026-05 |
| 28 | Coercion canonique ACF (identity-passthrough type contract) | 2026-05 |
| 30 | Pending Revisions — enforcement serveur | 2026-06 |
| 32 | Flag `dry_run` transversal + suppression `/validate-content` | 2026-06 |
| 33 | `GET /articles/{id}/blocks` renvoie les `field_values` | 2026-06 |
| 34 | Fix `core/*` block pass-through — jamais de 422 sur un bloc core | 2026-06 |
| 35 | ~~Wysiwyg : préserver le HTML~~ SUPERSEDED → Phase 36 | 2026-06 |
| 36 | Wysiwyg : l'agent envoie du markdown bloc+inline, pas du HTML | 2026-06 |
| 37 | Code-review Phases 34-35 — findings vérifiés | 2026-06 |
| 38 | Revue de la revue — durcissement passthrough round-trip | 2026-06 |
| 39 | Markdown inline dans les cellules de table ACF (`acf/table`) | 2026-07 |
| 42 | Intégrité d'écriture des champs — 4 défauts absents de v0.2.0 | 2026-08 |
| 45 | Upgrade-path test (gate #15) : le build teste la mise à jour N-1 → N + archive `dist/` | 2026-08 |

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

- [x] Décider : exposer `DELETE`/`POST /contents/{id}/revisions/{revision_id}/reject` seul, ou rien
      — **tranché en Phase 44.1** : `POST .../reject` existe (scope `revisions:write`), pas de jumeau `approve`
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
- [ ] ~~Déploiement de 0.4.0~~ — **jamais déployée**, remplacée par 0.4.1 (voir 43.5)

### 43.5 — Code review : le descripteur avait dérivé du scripteur — ✅ FAIT

*Revue multi-agents lancée le 2026-08-07 après le build 0.4.0, avant tout déploiement.
10 findings vérifiés (9 CONFIRMED, 1 PLAUSIBLE), tous corrigés ici.*

**Le thème est une correction à ce qu'on avait écrit en 43.2.** La parité entre les deux surfaces
admin était bien structurelle. Mais le principe « un seul pipeline, plusieurs appelants » n'a pas tenu
là où il comptait le plus : **entre ce qui décrit et ce qui écrit**. C'est la classe de défaut que la
Phase 42 avait fermée sur le chemin d'écriture, rouverte sur le chemin de lecture.

**La preview montrait un écran qui n'était ni l'avant ni l'après — le contraire de son objet :**

- [x] **`wysiwyg: null` vidait le champ.** `finalize_post()` retombe sur le contenu **du parent** quand
      la révision ne propose pas de contenu ; la preview ne lisait que celui de la révision, souvent
      vide. Le relecteur voyait un bloc blanc et rejetait une révision correcte. Miroir du writer,
      fallback compris
- [x] **Les champs structurés affichaient une chimère.** ACF ne range pas un repeater sous son propre
      nom (compteur de lignes + une clé par sous-champ et par ligne). Injecter le tableau brut faisait
      lire `intval(array) = 1` : **une** ligne, remplie depuis les sous-clés **du parent**. Repeater,
      group, flexible_content et clone retombent maintenant sur le parent — comme les images en URL,
      et pour la même raison : réimplémenter le stockage d'ACF sur un chemin de lecture se casserait
      chez le client
- [x] **La superposition ratait les lectures adressées au parent.** `setup_preview_state()` pointe
      `queried_object` sur le parent : tout thème lisant `get_field( 'x', get_queried_object_id() )`
      obtenait les valeurs live. Le mode de défaillance le plus dangereux du lot — la page rendait
      pleine, bien formée et périmée, sans rien à l'écran pour le laisser deviner

**Le diff annonçait des changements que l'approbation ne fait pas :**

- [x] **`isset()` contre `!empty()`.** Le diff ouvrait sur `isset`, les scripteurs ferment sur
      `!empty` pour le titre, le slug, les meta SEO, les taxonomies et l'image à la une. Un
      `title: ""` était listé puis silencieusement ignoré. Chaque garde cite désormais la ligne du
      scripteur qu'elle reflète — `excerpt` reste en `isset` (un `""` explicite efface, Phase 42.3),
      et c'est cette asymétrie qui interdit de factoriser en une règle unique
- [x] **L'alt de l'image à la une** n'est écrit qu'à l'intérieur de la branche `featured_image_url` :
      envoyé seul, il n'est plus listé
- [x] **SEO : lecture multi-plugin, écriture Yoast en dur.** `Arcadia_SEO_Meta::storage_keys()` est
      désormais la source unique des deux sens. Sur un site Rank Math, l'écriture partait dans une clé
      que rien n'affiche ; sur un site nu, le diff montrait le H1 en « valeur courante » alors que
      l'approbation ne touche pas au H1. **C'est un changement de ce que l'approbation écrit** — hors
      du périmètre initial « montrer sans rien changer », intégré ici parce que corriger le seul
      descripteur aurait maquillé le défaut
- [x] **Trois orthographes de « est-ce un sideload ? »** — `filter_var(VALIDATE_URL)` chez le writer
      field-schema, la même recopiée dans le diff, « toute chaîne non vide » dans
      `describe_field_transform()`. Une URL protocole-relative recevait trois réponses contradictoires
      et finissait écrite brute dans un champ image. Le writer field-schema délègue maintenant à
      `coerce_field_value()` : une règle, une implémentation, trois consommateurs
- [x] **La garde ACF était restée du mauvais côté de l'extraction.** Déplacée dans
      `resolve_field_schema_mappings()`, que lisent aussi le diff et la preview

**Deux défauts de sûreté :**

- [x] **`transform: null` sur `skip_markdown` était un mensonge.** `parse_rich( $v, true )` est
      `wp_kses_post()` : un `<iframe>` disparaît. Nouvelle valeur `sanitize_html`, annoncée sur les
      trois surfaces. **Le test de lockstep ne l'attrapait pas** : son unique cas `skip_markdown`
      (`<h2>T</h2>`) traverse kses intact, donc il ne prouvait rien. Toutes les sondes sont maintenant
      choisies pour être visiblement transformées
- [x] **Le détail REST expédiait les valeurs brutes.** Un champ ACF `post_object`/`relationship`/`user`
      en « retour objet » fait renvoyer des `WP_Post`/`WP_User` par `get_fields()` — sérialisés, ils
      emportent `post_password`, le `post_content` entier, `user_email`. `to_api_changes()` réduit tout
      objet à `{object, id}` et borne `current` à 5 000 caractères avec un `current_truncated` explicite.
      `proposed` reste verbatim : c'est le payload de l'appelant, et l'`api-contract` le promet tel quel
- [x] **`preg_replace('/\s+/u')` renvoie `null`** sur du contenu non-UTF-8 (pages migrées depuis
      latin1). La cellule « valeur courante » s'affichait vide : le relecteur lit « champ vide »,
      approuve, et écrase un texte qui était là. Repli octet par octet

**Vérification :**

- [x] **Non-vacuité : 12 mutants, 11 tués.** Le survivant est la garde `function_exists('update_field')` —
      inexerçable en processus (le bootstrap définit le stub inconditionnellement, et PHP ne sait pas
      dé-définir une fonction). Couvert autrement : un test prouve que le writer écrit **exactement**
      les champs que le résolveur résout, ce qui est ce sur quoi la correction repose
- [x] Suite complète : **630 tests / 1961 assertions** (605 → +25). PHPStan local — **No errors**
- [x] `./build.sh 0.4.1` — **15 gates verts**, zip 406KB
- [ ] ~~Déploiement de 0.4.1~~ — **jamais déployée** non plus, remplacée par 0.5.1 (voir 44.3)
- [ ] Vérification sur préprod (voir ci-dessous) — sur **0.5.1**

### Vérification de sortie — séparer les deux natures de changement

On a empilé un changement d'API et un changement de rendu dans le même zip (réserve exprimée, levée
par Oscar). La vérification les sépare donc explicitement :

- [ ] **API seule** : `GET /contents/20858/revisions/92200` renvoie `changes` avec les champs touchés
      et leur valeur courante. Ne touche à aucun rendu — vérifiable sans regarder une page
- [ ] **Rendu** : ouvrir la preview de `92200`. Attendu — page **complète** (proche des 72 432 octets
      du live, header/footer présents, `<p>` et `<h2>` non nuls), avec le seul champ proposé à sa
      nouvelle valeur. C'est le critère de sortie qu'AA a écrit
- [ ] **Admin** : la bannière déplie l'avant/après ; le panneau Gutenberg montre la même chose
- [ ] **SEO** : relevé fait le 2026-08-08 depuis le HTML public — **iselection.com/b2c = Yoast**
      (cible d'écriture inchangée), **trottinette-tout-terrain.fr = Rank Math** (la cible change).
      Sur trottinette, toute meta SEO écrite par le plugin avant 0.4.1 est partie dans
      `_yoast_wpseo_title` / `_yoast_wpseo_metadesc` et n'a jamais été affichée. **À décider :
      recopier ces valeurs vers les clés `rank_math_*`**, ou les laisser (les meta actuelles du site
      sont celles saisies à la main, elles sont correctes). Préprod iSelection : non sondé, à
      vérifier — présumé Yoast comme la prod
- [ ] Le plugin n'expose nulle part quel plugin SEO tourne sur un site. `get_active_plugin()` existe
      et n'est branché sur aucun endpoint — à ajouter à `/site-info` quand on y touchera

### Correction à acter dans nos propres notes

AA nous avait écrit le 2026-08-05 « la preview est bonne, rien ne vous attend là-dessus », et on l'a
recopié tel quel dans la vérification 41.2. C'était trop large **des deux côtés** : la sonde mesurait
la **résolution du template**, jamais le **contenu** rendu. Le template est bon, le contenu est vide.
Voir la note ajoutée en 41.2.

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
- [ ] **Cocher `revisions:write`** dans Réglages sur chacun des 3 sites (sinon 403)
- [ ] Annoncer à AA : chemin, scope, corps, codes de retour

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
