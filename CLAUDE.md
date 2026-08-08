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
| `backlog.md` | **AA → Plugin** : file d'attente actionnelle (vidée après intégration plugin) |
| `backlog-for-backend.md` | **Plugin → AA** : questions, blocages, annonces de release (vidé par la session AA) |
| `api-contract.md` | Endpoints, params, réponses (28 endpoints: 15 MVP + 13 v2) |
| `auth.md` | JWT RS256, handshake, scopes |
| `content-model.md` | JSON schema blocs (ADR-013), mapping ACF, multi-builder |
| `code-review.md` | Audit code v2.0.1 (33 issues) |
| `decisions.md` | Historique des décisions validées |
| `dev-guide.md` | Guide dev, repo, CI/CD, publication WP.org |

### Protocole backlog (inter-repo)

Communication **pull-based** entre les sessions AA et Plugin, **un fichier par sens** — pour que
« vide = rien en attente » reste vrai des deux côtés.

**AA → Plugin (`backlog.md`)**
1. AA écrit des items dans `backlog.md`
2. Plugin lit `backlog.md`, intègre dans `docs/checklist.md`
3. Plugin vide `backlog.md`
4. **Backlog vide = plugin à jour**

**Plugin → AA (`backlog-for-backend.md`)**
1. Plugin écrit ses questions, blocages et annonces de release dans `backlog-for-backend.md`
2. AA lit, répond ou agit (souvent en écrivant dans `backlog.md`)
3. AA vide `backlog-for-backend.md`
4. **Backlog-for-backend vide = AA n'a rien en attente du plugin**

Ne jamais écrire dans le fichier de l'autre sens : un canal bidirectionnel casse l'invariant
« vide = rien en attente ».

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
./build.sh          # incrémente le patch (0.1.38 → 0.1.39)
./build.sh 0.2.0    # release une version précise (bump mineur/majeur)
```

L'argument de version est **validé** : format `MAJOR.MINOR.PATCH`, et strictement supérieur à
la version courante. Éditer les trois sources de version à la main est précisément la dérive
que le check #12 existe pour attraper — passer la cible au script garde un seul écrivain.

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
| 12 | **Entrée de changelog présente** pour la version cible + version bump + sync readme `Stable tag` | Oui |
| 13 | Création du zip | Oui |
| 14 | Zip content audit (pas de tests/dev deps) | Oui |
| 15 | Zip size en octets (warning si > 500KB) | Warning |
| – | Restauration dev deps + rollback version (trap EXIT) | - |

Si un check bloquant échoue, **pas de zip**. Les dev deps sont toujours restaurées (même en cas d'erreur) via `trap EXIT`, et le bump de version est annulé — un build avorté ne doit pas laisser l'arbre sur une version jamais packagée (c'est exactement ce qui s'est produit des Phases 31 à 38 : 0.1.30 → 0.1.37 sans aucun zip).

### Choisir le numéro

**MAJOR** : on retire ou on casse du publié (`/articles*` en 2027-02-01, WP.org en 1.0.0).
**MINOR** : du nouveau à utiliser, **ou** quelque chose qui marche autrement (endpoint, scope, champ
de réponse, un appel accepté qui devient 422, une écriture qui change de destination).
**PATCH** : contrat identique, seule la conformité s'améliore (CI, perf, un bug qui empêchait le
comportement déjà documenté).

C'est **l'effet observable** qui décide, jamais l'origine du correctif — `0.4.1` est partie en patch
parce qu'elle sortait d'une code review, alors qu'elle déplaçait la clé d'écriture des meta SEO.
Raccourci : relire l'entrée de changelog (obligatoire avant le bump, check #12). Un *new* / *no
longer* / *now refuses* → minor ; que des *fixed* → patch.

⚠️ De `0.1.2` à `0.1.38` tout est passé en patch, y compris un nouveau scope et la suppression d'un
endpoint : c'était `./build.sh` sans argument par réflexe. Pas un précédent.

**Écrire l'entrée de changelog AVANT de builder.** Le check #12 la réclame, parce qu'après coup il est
trop tard : les trois sources de version ne s'écrivent que par ce script, et il refuse de re-couper un
numéro déjà pris. Un changelog corrigé après le build coûte donc un bump de plus, et le zip livré
décrit une autre release. C'est exactement comme ça que 0.4.0, 0.4.1 et 0.5.0 ont été brûlées.

**Gate clé de voûte (#2) :** le `wp_slash safety gate` interdit toute écriture WordPress (`wp_insert_post`/`wp_update_post`, ou un `*_post_meta` avec `wp_json_encode`) sans `wp_slash()`. Échappatoire documentée : annoter la ligne avec `// arcadia:slash-safe — <raison>`. C'est le garde-fou anti-régression de la classe de bug qui a atteint la prod deux fois.

**Analyse statique (hors `./build.sh`) :** PHPStan (niveau 5 + `phpstan-wordpress` + baseline) tourne dans la CI GitHub. Voir `.github/workflows/ci.yml` et `phpstan.neon.dist`.

Il tourne **aussi en local** à condition de relever la mémoire explicitement — c'est le défaut du conteneur qui est trop bas, pas la machine :

```bash
docker compose exec -T wordpress bash -c "cd /var/www/html/wp-content/plugins/arcadia-agents && php -d memory_limit=3G vendor/bin/phpstan analyse --no-progress --memory-limit=3G"
```

**Le lancer avant de pousser sur `main`.** Deux classes d'erreurs n'apparaissent qu'ici, jamais dans les tests : les entrées de baseline devenues orphelines (supprimer le code fautif fait échouer l'analyse), et les propriétés inconnues sur un `@param object` mal typé.

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