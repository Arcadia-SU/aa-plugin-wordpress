# Plugin WordPress - Checklist de développement

**Dernière mise à jour :** 2026-05-01 (Phase 27 code+tests OK — reste déploiement preprod)

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
- [ ] Déploiement standalone sur preprod-iselection.vertuelle.com
- [ ] Validation E2E : push article iSelection cms_post_id 20803 (contenant repeater) sans 422
- [ ] Fermer bead `aa-iedn` côté AA

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
