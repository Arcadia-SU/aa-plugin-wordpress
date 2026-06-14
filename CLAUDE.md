# AA Plugin WordPress

Plugin WordPress pour connecter un site WP à ArcadiaAgents.

## Guide des tâches

**Suivre `docs/checklist.md`** pour savoir quelles tâches faire et dans quel ordre.
Cocher les cases au fur et à mesure de l'avancement.

## Documentation

- `docs/checklist.md` - Liste des tâches à faire (suivi local)
- `docs/checklist-test-site-client.md` - Checklist test manuel site client (ACF Pro)

### Source de vérité des specs

**Dossier maître :** `/Users/oscarsatre/Documents/ArcadiaAgents/docs/satellites/plugin-wp/`

Cette session lit directement les fichiers maîtres (pas de copie locale).

| Fichier | Contenu |
|---------|---------|
| `README.md` | Hub : purpose, protocole, liens vers tous les fichiers |
| `backlog.md` | File d'attente actionnelle (vidée après intégration plugin) |
| `api-contract.md` | Endpoints, params, réponses (28 endpoints: 15 MVP + 13 v2) |
| `auth.md` | JWT RS256, handshake, scopes |
| `content-model.md` | JSON schema blocs (ADR-013), mapping ACF, multi-builder |
| `code-review.md` | Audit code v2.0.1 (33 issues) |
| `decisions.md` | Historique des décisions validées |
| `dev-guide.md` | Guide dev, repo, CI/CD, publication WP.org |

### Protocole backlog (inter-repo)

Communication **pull-based** entre les sessions AA et Plugin :
1. AA écrit des items dans `backlog.md`
2. Plugin lit `backlog.md`, intègre dans `docs/checklist.md`
3. Plugin vide `backlog.md`
4. **Backlog vide = plugin à jour**

## Conventions

- Préfixe : `arcadia_*` pour fonctions, classes, options WP
- WordPress Coding Standards (PHPCS)
- Licence : GPL v2+
- PHP 8.0+

## Architecture

```
arcadia-agents/
├── arcadia-agents.php       # Point d'entrée + hooks
├── includes/
│   ├── class-auth.php       # Validation JWT RS256
│   ├── class-api.php        # Endpoints REST
│   └── class-blocks.php     # Génération blocs Gutenberg/ACF
└── admin/
    └── settings.php         # Page admin WP
```

## Dev local

```bash
./start.sh   # Lance WordPress + MySQL + PHPMyAdmin
./stop.sh    # Arrête les containers
```

- WordPress : http://localhost:8082
- PHPMyAdmin : http://localhost:8083
- Health check : http://localhost:8082/wp-json/arcadia/v1/health

## Dépendances PHP

Pour la validation JWT, utiliser `firebase/php-jwt` :
```bash
composer require firebase/php-jwt
```

## Build zip

**TOUJOURS utiliser le script de build.** Ne jamais builder manuellement.

```bash
./build.sh
```

Le script exécute ces checks avant de créer le zip :

| # | Check | Bloquant |
|---|-------|----------|
| 1 | Docker running | Oui |
| 2 | **wp_slash safety gate** (`bin/check-wp-slash.php`) | Oui |
| 3 | PHPUnit tests | Oui |
| 4 | **Real-WordPress fidelity check** (`test/fidelity-check.php`) | Oui |
| 5 | `composer install --no-dev` | Oui |
| 6 | PHP lint (tous les .php) | Oui |
| 7 | **Debug-code scan** (pas de `var_dump`/`print_r` echo) | Oui |
| 8 | **Uninstall completeness** (options/CPT/cron nettoyés) | Oui |
| 9 | Autoloader audit (pas de dev deps) | Oui |
| 10 | Vendor completeness (firebase/php-jwt) | Oui |
| 11 | Boot test (autoloader charge) | Oui |
| 12 | Version bump + sync readme `Stable tag` | Oui |
| 13 | Création du zip | Oui |
| 14 | Zip content audit (pas de tests/dev deps) | Oui |
| 15 | Zip size en octets (warning si > 500KB) | Warning |
| – | Restauration dev deps (trap EXIT) | - |

Si un check bloquant échoue, **pas de zip**. Les dev deps sont toujours restaurées (même en cas d'erreur) via `trap EXIT`.

**Gate clé de voûte (#2) :** le `wp_slash safety gate` interdit toute écriture WordPress (`wp_insert_post`/`wp_update_post`, ou un `*_post_meta` avec `wp_json_encode`) sans `wp_slash()`. Échappatoire documentée : annoter la ligne avec `// arcadia:slash-safe — <raison>`. C'est le garde-fou anti-régression de la classe de bug qui a atteint la prod deux fois.

**Analyse statique (CI uniquement) :** PHPStan (niveau 5 + `phpstan-wordpress` + baseline) tourne dans la CI GitHub, pas dans `./build.sh` (le conteneur local manque de RAM pour analyser tout le code d'un coup). Voir `.github/workflows/ci.yml` et `phpstan.neon.dist`.

**RÈGLE : Toujours lancer `./build.sh` après tout changement de code.** Le zip doit rester à jour.

## Tests

```bash
# PHPUnit (dans le container)
docker compose exec wordpress bash -c "cd /var/www/html/wp-content/plugins/arcadia-agents && ./vendor/bin/phpunit --testdox"

# Setup JWT pour tests manuels
docker compose exec wordpress bash -c "cd /var/www/html/wp-content/plugins/arcadia-agents/test && php mock-setup.php && php generate-jwt.php private_key.pem 'articles:read'"
```

- **Unit tests** : `arcadia-agents/tests/unit/` (PHPUnit, mocks WP)
- **Integration tests** : `test/integration/` (shell scripts + curl)
- Config integration : `cp test/config.example.sh test/config.sh`
## Contexte

### Projet

Plugin WordPress pour connecter un site WP à ArcadiaAgents. Permet à l'agent SEO de publier/modifier du contenu automatiquement.

### Architecture

```
ArcadiaAgents (orchestrateur)
    │
    │ REST API + JWT
    ▼
Plugin WordPress (exécuteur)
    │
    ▼
Site WordPress du client
```

### Documents liés (repo ArcadiaAgents)

**PRD Agent SEO :** `/Users/oscarsatre/Documents/ArcadiaAgents/docs/tasks_backlog/agent-seo/prd.md`
- Lecture seule, sous responsabilité du repo ArcadiaAgents

### Principes clés

- **Agent orchestre, plugin exécute** - Le plugin est passif, il expose une API REST
- **JSON sémantique** - L'agent envoie du JSON structuré, le plugin le mappe vers les blocs WP
- **Multi-builders** - Adaptateurs pour ACF Blocks (MVP) + Gutenberg natif (MVP)
- **Sécurité JWT** - ArcadiaAgents signe, le plugin vérifie avec la public key

## Guiding Principle: Structure Over Brevity

**Oscar's explicit preference:** "More structure is better for me, even if experienced developers might call it over-engineering. I tend to easily forget things, structure forces me to remember."

**Why:**
- **Guardrails**: Structure prevents mistakes (like TypeScript for JavaScript)
- **Navigation**: Always know where to look and where to put things
- **Confidence**: Less doubt = less paralysis = more productivity
- **Learning**: Structure teaches best practices by example

**Accepted trade-offs:**
- More files to create per feature (boilerplate)
- More jumps between files when debugging (indirection)
- Some conceptual duplication (domain + infrastructure versions)
- Heavier refactoring when changing decisions

**This is intentional.** When in doubt, add more structure, not less.